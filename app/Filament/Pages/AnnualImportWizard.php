<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\NavigationAware;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Property;
use App\Services\AirbnbImportService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\HtmlString;
use Livewire\WithFileUploads;
use UnitEnum;

class AnnualImportWizard extends Page implements HasForms
{
    use InteractsWithForms, NavigationAware, WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;
    protected static string|UnitEnum|null $navigationGroup = 'Fiscal';
    protected static ?string $navigationLabel = 'Import annuel';
    protected static ?string $title = 'Assistant d\'import annuel';
    protected static ?int $navigationSort = 3;

    protected static function isHiddenInSimpleMode(): bool
    {
        return true;
    }

    protected static function getGuidedNavigationGroup(): string
    {
        return 'Déclaration annuelle';
    }
    protected string $view = 'filament.pages.annual-import-wizard';

    public ?array $data = [];
    public ?array $importResult = null;
    public int $expensesCreated = 0;

    public function mount(): void
    {
        $this->form->fill([
            'year' => (int) date('Y') - 1,
            'import_airbnb' => false,
            'add_expenses' => false,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Wizard::make([
                    $this->stepSelectContext(),
                    $this->stepAirbnbImport(),
                    $this->stepExpenses(),
                    $this->stepSummary(),
                ])
                ->columnSpanFull()
                ->skippable()
                ->submitAction(new HtmlString(
                    '<button type="submit"
                        class="fi-btn fi-btn-size-md fi-btn-color-primary fi-color-custom relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg gap-1.5 px-4 py-2 text-sm inline-grid shadow-sm bg-custom-600 text-white hover:bg-custom-500 dark:bg-custom-500 dark:hover:bg-custom-400"
                        style="--c-400:var(--primary-400);--c-500:var(--primary-500);--c-600:var(--primary-600);"
                    >
                        Terminer l\'import
                    </button>'
                )),
            ]);
    }

    // -------------------------------------------------------------------------
    // Étape 1 : Contexte
    // -------------------------------------------------------------------------

    private function stepSelectContext(): Step
    {
        return Step::make('Année & Bien')
            ->icon('heroicon-o-calendar')
            ->description('Sélectionnez l\'année et le bien concerné')
            ->schema([
                Grid::make(2)->schema([
                    Select::make('year')
                        ->label('Année')
                        ->options(function () {
                            $years = [];
                            for ($y = (int) date('Y'); $y >= (int) date('Y') - 5; $y--) {
                                $years[$y] = $y;
                            }
                            return $years;
                        })
                        ->default((int) date('Y') - 1)
                        ->required()
                        ->live(),
                    Select::make('property_id')
                        ->label('Bien immobilier')
                        ->options(Property::pluck('name', 'id'))
                        ->required()
                        ->preload()
                        ->live(),
                ]),
                Placeholder::make('existing_data')
                    ->label('Données existantes')
                    ->content(function (callable $get) {
                        $propertyId = $get('property_id');
                        $year = $get('year');
                        if (!$propertyId || !$year) {
                            return 'Sélectionnez un bien et une année.';
                        }

                        $property = Property::find($propertyId);
                        if (!$property) return '—';

                        $incomeCount = $property->incomes()->whereYear('income_date', $year)->count();
                        $incomeTotal = $property->incomes()->whereYear('income_date', $year)->sum('amount');
                        $expenseCount = $property->expenses()->whereYear('expense_date', $year)->count();
                        $expenseTotal = $property->expenses()->whereYear('expense_date', $year)->sum('amount');

                        return new HtmlString(
                            '<div class="grid grid-cols-2 gap-4">'
                            . '<div class="rounded-lg border border-green-200 bg-green-50 p-3 dark:border-green-700 dark:bg-green-900/20">'
                            . '<div class="text-lg font-bold text-green-700 dark:text-green-400">' . $incomeCount . ' recette(s)</div>'
                            . '<div class="text-sm text-green-600 dark:text-green-500">' . number_format($incomeTotal / 100, 2, ',', ' ') . ' €</div>'
                            . '</div>'
                            . '<div class="rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-700 dark:bg-red-900/20">'
                            . '<div class="text-lg font-bold text-red-700 dark:text-red-400">' . $expenseCount . ' charge(s)</div>'
                            . '<div class="text-sm text-red-600 dark:text-red-500">' . number_format($expenseTotal / 100, 2, ',', ' ') . ' €</div>'
                            . '</div>'
                            . '</div>'
                        );
                    }),
            ]);
    }

    // -------------------------------------------------------------------------
    // Étape 2 : Import Airbnb CSV
    // -------------------------------------------------------------------------

    private function stepAirbnbImport(): Step
    {
        return Step::make('Recettes Airbnb')
            ->icon('heroicon-o-arrow-down-tray')
            ->description('Importez vos revenus depuis un fichier CSV')
            ->schema([
                Toggle::make('import_airbnb')
                    ->label('Importer un fichier CSV Airbnb')
                    ->default(false)
                    ->live(),

                Section::make('Fichier CSV')
                    ->visible(fn (callable $get) => (bool) $get('import_airbnb'))
                    ->schema([
                        Placeholder::make('csv_help')
                            ->label('')
                            ->content(new HtmlString(
                                '<div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm dark:border-blue-700 dark:bg-blue-900/20">'
                                . '<p class="font-medium text-blue-800 dark:text-blue-300 mb-1">Comment obtenir le fichier CSV ?</p>'
                                . '<ol class="list-decimal list-inside text-blue-700 dark:text-blue-400 space-y-1">'
                                . '<li>Connectez-vous sur airbnb.com</li>'
                                . '<li>Allez dans Performances → Historique des transactions</li>'
                                . '<li>Filtrez par année et cliquez "Exporter en CSV"</li>'
                                . '</ol>'
                                . '</div>'
                            )),
                        FileUpload::make('csv_file')
                            ->label('Fichier CSV Airbnb')
                            ->acceptedFileTypes(['text/csv', 'application/vnd.ms-excel', '.csv'])
                            ->maxSize(10240),
                    ]),
            ]);
    }

    // -------------------------------------------------------------------------
    // Étape 3 : Charges annuelles
    // -------------------------------------------------------------------------

    private function stepExpenses(): Step
    {
        return Step::make('Charges annuelles')
            ->icon('heroicon-o-receipt-percent')
            ->description('Ajoutez vos charges déductibles')
            ->schema([
                Toggle::make('add_expenses')
                    ->label('Saisir des charges maintenant')
                    ->helperText('Vous pourrez aussi les ajouter plus tard via le menu Charges.')
                    ->default(false)
                    ->live(),

                Repeater::make('expenses')
                    ->label('Charges')
                    ->visible(fn (callable $get) => (bool) $get('add_expenses'))
                    ->schema([
                        Grid::make(4)->schema([
                            Select::make('category')
                                ->label('Catégorie')
                                ->options(Expense::categoryLabels())
                                ->required(),
                            TextInput::make('amount')
                                ->label('Montant')
                                ->suffix('€')
                                ->required()
                                ->numeric()
                                ->step(0.01),
                            DatePicker::make('expense_date')
                                ->label('Date')
                                ->required()
                                ->displayFormat('d/m/Y'),
                            Toggle::make('is_dedicated')
                                ->label('100 % dédié')
                                ->default(true)
                                ->inline(false),
                        ]),
                        TextInput::make('description')
                            ->label('Description')
                            ->placeholder('Ex : Taxe foncière 2024'),
                    ])
                    ->addActionLabel('Ajouter une charge')
                    ->defaultItems(0)
                    ->collapsible(),
            ]);
    }

    // -------------------------------------------------------------------------
    // Étape 4 : Récapitulatif
    // -------------------------------------------------------------------------

    private function stepSummary(): Step
    {
        return Step::make('Récapitulatif')
            ->icon('heroicon-o-check-circle')
            ->description('Vérifiez avant de valider')
            ->schema([
                Placeholder::make('import_summary')
                    ->label('')
                    ->content(function (callable $get) {
                        $year = $get('year');
                        $propertyId = $get('property_id');
                        $property = $propertyId ? Property::find($propertyId) : null;

                        $lines = [
                            ['Année', (string) ($year ?? '—')],
                            ['Bien', $property?->name ?? '—'],
                        ];

                        if ($get('import_airbnb') && $get('csv_file')) {
                            $lines[] = ['Import CSV', 'Fichier Airbnb prêt à importer'];
                        }

                        $expenses = $get('expenses') ?? [];
                        $expenseCount = count(array_filter($expenses, fn ($e) => !empty($e['category'])));
                        if ($expenseCount > 0) {
                            $total = array_sum(array_map(fn ($e) => (float) ($e['amount'] ?? 0), $expenses));
                            $lines[] = ['Charges à créer', $expenseCount . ' charge(s) — ' . number_format($total, 2, ',', ' ') . ' €'];
                        }

                        $rows = implode('', array_map(function ($line) {
                            return '<tr class="border-b border-gray-100 dark:border-gray-700">'
                                . '<td class="py-2 pr-4 text-sm font-medium text-gray-600 dark:text-gray-400">' . $line[0] . '</td>'
                                . '<td class="py-2 text-sm text-gray-900 dark:text-white">' . $line[1] . '</td>'
                                . '</tr>';
                        }, $lines));

                        return new HtmlString(
                            '<div class="rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">'
                            . '<table class="w-full"><tbody>' . $rows . '</tbody></table>'
                            . '</div>'
                        );
                    }),
            ]);
    }

    // -------------------------------------------------------------------------
    // Soumission
    // -------------------------------------------------------------------------

    public function create(): void
    {
        $data = $this->data;
        $propertyId = (int) $data['property_id'];
        $property = Property::findOrFail($propertyId);
        $messages = [];

        // 1. Import CSV Airbnb
        if (!empty($data['import_airbnb']) && !empty($data['csv_file'])) {
            $tempFile = $data['csv_file'];

            if (is_string($tempFile)) {
                $disk = \Illuminate\Support\Facades\Storage::disk();
                $path = $disk->exists($tempFile) ? $disk->path($tempFile) : storage_path('app/public/' . $tempFile);
                if (file_exists($path)) {
                    $uploadedFile = new UploadedFile($path, basename($path));
                    $result = app(AirbnbImportService::class)->import($uploadedFile, $property);
                    $this->importResult = $result;
                    $messages[] = $result['imported'] . ' recette(s) importée(s)';
                    if ($result['skipped'] > 0) {
                        $messages[] = $result['skipped'] . ' ligne(s) ignorée(s)';
                    }
                }
            }
        }

        // 2. Créer les charges
        if (!empty($data['add_expenses']) && !empty($data['expenses'])) {
            $count = 0;
            foreach ($data['expenses'] as $expense) {
                if (empty($expense['category']) || empty($expense['amount'])) continue;

                $property->expenses()->create([
                    'category' => $expense['category'],
                    'amount' => (int) round(((float) $expense['amount']) * 100),
                    'expense_date' => $expense['expense_date'],
                    'description' => $expense['description'] ?? null,
                    'is_dedicated' => (bool) ($expense['is_dedicated'] ?? true),
                ]);
                $count++;
            }
            $this->expensesCreated = $count;
            if ($count > 0) {
                $messages[] = $count . ' charge(s) créée(s)';
            }
        }

        app(\App\Services\BadgeService::class)->evaluate(auth()->user(), 'annual_import', [
            'fiscal_year' => (int) ($data['year'] ?? date('Y')),
        ]);

        Notification::make()
            ->title('Import terminé')
            ->body(implode(' · ', $messages) ?: 'Aucune donnée importée.')
            ->success()
            ->send();

        $this->redirect('/');
    }
}
