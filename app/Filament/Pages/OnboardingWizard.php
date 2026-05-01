<?php

namespace App\Filament\Pages;

use App\Models\Property;
use App\Services\DepreciationService;
use App\Services\LoanService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
use Illuminate\Support\HtmlString;
use UnitEnum;

class OnboardingWizard extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;
    protected static string|UnitEnum|null $navigationGroup = 'Paramètres';
    protected static ?string $navigationLabel = 'Premier lancement';
    protected static ?string $title = 'Assistant de configuration';
    protected static ?int $navigationSort = 10;
    protected string $view = 'filament.pages.onboarding-wizard';

    public ?array $data = [];

    public static function shouldRegisterNavigation(): bool
    {
        return Property::count() === 0;
    }

    public function mount(): void
    {
        if (Property::count() > 0) {
            $this->redirect('/');
            return;
        }

        $this->form->fill([
            'type' => 'apartment',
            'rental_type' => 'seasonal',
            'is_primary_residence' => false,
            'land_percentage' => 15,
            'insurance_type' => 'fixed',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Wizard::make([
                    $this->stepWelcome(),
                    $this->stepProperty(),
                    $this->stepSurfaces(),
                    $this->stepAcquisition(),
                    $this->stepRental(),
                    $this->stepLoan(),
                    $this->stepDepreciation(),
                    $this->stepSummary(),
                ])
                ->columnSpanFull()
                ->submitAction(new HtmlString(
                    '<button type="submit"
                        class="fi-btn fi-btn-size-md fi-btn-color-primary fi-color-custom relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg gap-1.5 px-4 py-2 text-sm inline-grid shadow-sm bg-custom-600 text-white hover:bg-custom-500 dark:bg-custom-500 dark:hover:bg-custom-400"
                        style="--c-400:var(--primary-400);--c-500:var(--primary-500);--c-600:var(--primary-600);"
                    >
                        Terminer la configuration
                    </button>'
                )),
            ]);
    }

    // -------------------------------------------------------------------------
    // Étape 1 : Bienvenue
    // -------------------------------------------------------------------------

    private function stepWelcome(): Step
    {
        return Step::make('Bienvenue')
            ->icon('heroicon-o-sparkles')
            ->description('Présentation de l\'assistant')
            ->schema([
                Placeholder::make('welcome_text')
                    ->label('')
                    ->content(new HtmlString(
                        '<div class="rounded-xl border border-primary-200 bg-primary-50 p-6 dark:border-primary-700 dark:bg-primary-900/20">'
                        . '<h2 class="text-xl font-bold text-primary-700 dark:text-primary-300 mb-3">Bienvenue sur OpenLMNP !</h2>'
                        . '<p class="text-sm text-gray-700 dark:text-gray-300 mb-4">'
                        . 'Cet assistant va vous guider pour configurer votre comptabilité LMNP en quelques étapes :'
                        . '</p>'
                        . '<ol class="list-decimal list-inside space-y-2 text-sm text-gray-600 dark:text-gray-400">'
                        . '<li><strong>Votre bien immobilier</strong> — nom, adresse, type</li>'
                        . '<li><strong>Les surfaces</strong> — pour calculer la quote-part</li>'
                        . '<li><strong>L\'acquisition</strong> — prix, frais de notaire, valeur vénale</li>'
                        . '<li><strong>La location</strong> — date de début, liens d\'annonces</li>'
                        . '<li><strong>L\'emprunt</strong> — conditions et assurance (optionnel)</li>'
                        . '<li><strong>Les amortissements</strong> — composants par défaut</li>'
                        . '</ol>'
                        . '<p class="text-xs text-gray-500 dark:text-gray-500 mt-4">Vous pourrez modifier toutes ces informations plus tard.</p>'
                        . '</div>'
                    )),
            ]);
    }

    // -------------------------------------------------------------------------
    // Étape 2 : Informations du bien
    // -------------------------------------------------------------------------

    private function stepProperty(): Step
    {
        return Step::make('Votre bien')
            ->icon('heroicon-o-home-modern')
            ->description('Informations générales')
            ->schema([
                FileUpload::make('photo_path')
                    ->label('Photo du bien')
                    ->image()
                    ->directory('properties')
                    ->maxSize(5120)
                    ->imagePreviewHeight('150')
                    ->columnSpanFull(),
                TextInput::make('name')
                    ->label('Nom du bien')
                    ->placeholder('Ex : La Bastide, Studio Paris 11...')
                    ->required()
                    ->maxLength(255),
                Grid::make(2)->schema([
                    Select::make('type')
                        ->label('Type de bien')
                        ->options(Property::typeLabels())
                        ->required()
                        ->default('apartment'),
                    Select::make('rental_type')
                        ->label('Type de location')
                        ->options(Property::rentalTypeLabels())
                        ->required()
                        ->default('seasonal'),
                ]),
                Grid::make(3)->schema([
                    TextInput::make('address')
                        ->label('Adresse')
                        ->required(),
                    TextInput::make('city')
                        ->label('Ville')
                        ->required(),
                    TextInput::make('postal_code')
                        ->label('Code postal')
                        ->required()
                        ->maxLength(10),
                ]),
            ]);
    }

    // -------------------------------------------------------------------------
    // Étape 3 : Surfaces
    // -------------------------------------------------------------------------

    private function stepSurfaces(): Step
    {
        return Step::make('Surfaces')
            ->icon('heroicon-o-square-3-stack-3d')
            ->description('Quote-part du bien loué')
            ->schema([
                Toggle::make('is_primary_residence')
                    ->label('Ce bien fait partie de ma résidence principale')
                    ->helperText('Si oui, seule la quote-part sera appliquée aux charges et amortissements.')
                    ->live(),
                Grid::make(2)->schema([
                    TextInput::make('total_area')
                        ->label('Surface totale')
                        ->suffix('m²')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->live(),
                    TextInput::make('rented_area')
                        ->label('Surface louée')
                        ->suffix('m²')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->live(),
                ]),
                Placeholder::make('quota_preview')
                    ->label('Quote-part calculée')
                    ->content(function (callable $get) {
                        $total = (float) ($get('total_area') ?? 0);
                        $rented = (float) ($get('rented_area') ?? 0);
                        if ($total <= 0) return 'Saisissez les surfaces pour voir la quote-part';
                        $quota = round($rented / $total * 100, 1);
                        return new HtmlString(
                            '<span class="text-lg font-bold text-primary-600">' . $quota . ' %</span>'
                            . '<span class="ml-2 text-sm text-gray-500">(' . $rented . ' m² / ' . $total . ' m²)</span>'
                        );
                    }),
            ]);
    }

    // -------------------------------------------------------------------------
    // Étape 4 : Acquisition
    // -------------------------------------------------------------------------

    private function stepAcquisition(): Step
    {
        return Step::make('Acquisition')
            ->icon('heroicon-o-banknotes')
            ->description('Prix et valeur du bien')
            ->schema([
                Grid::make(3)->schema([
                    TextInput::make('acquisition_price')
                        ->label('Prix d\'achat')
                        ->suffix('€')
                        ->required()
                        ->numeric()
                        ->step(1)
                        ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : null)
                        ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),
                    TextInput::make('notary_fees')
                        ->label('Frais de notaire')
                        ->suffix('€')
                        ->numeric()
                        ->step(1)
                        ->default(0)
                        ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : '0')
                        ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),
                    DatePicker::make('acquisition_date')
                        ->label('Date d\'achat')
                        ->required()
                        ->displayFormat('d/m/Y'),
                ]),
                Grid::make(3)->schema([
                    TextInput::make('market_value')
                        ->label('Valeur vénale')
                        ->suffix('€')
                        ->numeric()
                        ->step(1)
                        ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : null)
                        ->dehydrateStateUsing(fn ($state) => $state ? (int) round(((float) $state) * 100) : null)
                        ->hint('Si bien possédé avant la location')
                        ->hintIcon('heroicon-o-question-mark-circle'),
                    DatePicker::make('market_value_date')
                        ->label('Date estimation')
                        ->displayFormat('d/m/Y'),
                    TextInput::make('land_percentage')
                        ->label('Terrain non amortissable')
                        ->suffix('%')
                        ->required()
                        ->numeric()
                        ->default(15)
                        ->minValue(0)
                        ->maxValue(50),
                ]),
            ]);
    }

    // -------------------------------------------------------------------------
    // Étape 5 : Location
    // -------------------------------------------------------------------------

    private function stepRental(): Step
    {
        return Step::make('Location')
            ->icon('heroicon-o-calendar')
            ->description('Début de location et annonces')
            ->schema([
                DatePicker::make('rental_start_date')
                    ->label('Date de début de location')
                    ->required()
                    ->displayFormat('d/m/Y')
                    ->hint('Les amortissements démarrent à cette date.'),
                Repeater::make('listing_urls')
                    ->label('Liens d\'annonces')
                    ->schema([
                        TextInput::make('label')
                            ->label('Plateforme')
                            ->placeholder('Airbnb, Booking...')
                            ->required(),
                        TextInput::make('url')
                            ->label('URL')
                            ->url()
                            ->placeholder('https://...')
                            ->required(),
                    ])
                    ->columns(2)
                    ->addActionLabel('Ajouter un lien')
                    ->defaultItems(0),
                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(3),
            ]);
    }

    // -------------------------------------------------------------------------
    // Étape 6 : Emprunt (optionnel)
    // -------------------------------------------------------------------------

    private function stepLoan(): Step
    {
        return Step::make('Emprunt')
            ->icon('heroicon-o-credit-card')
            ->description('Optionnel — conditions de votre prêt')
            ->schema([
                Toggle::make('has_loan')
                    ->label('J\'ai un emprunt pour ce bien')
                    ->default(false)
                    ->live(),

                Section::make('Conditions de l\'emprunt')
                    ->visible(fn (callable $get) => (bool) $get('has_loan'))
                    ->schema([
                        TextInput::make('loan_bank_name')
                            ->label('Banque')
                            ->placeholder('Ex : BNP, Crédit Agricole...'),
                        Grid::make(2)->schema([
                            TextInput::make('loan_amount')
                                ->label('Montant emprunté')
                                ->suffix('€')
                                ->numeric()
                                ->step(1),
                            TextInput::make('loan_annual_rate')
                                ->label('Taux annuel')
                                ->suffix('%')
                                ->numeric()
                                ->step(0.001),
                        ]),
                        Grid::make(2)->schema([
                            TextInput::make('loan_duration_months')
                                ->label('Durée')
                                ->suffix('mois')
                                ->numeric(),
                            DatePicker::make('loan_start_date')
                                ->label('Date de début')
                                ->displayFormat('d/m/Y'),
                        ]),
                        TextInput::make('loan_monthly_payment')
                            ->label('Mensualité (hors assurance)')
                            ->suffix('€')
                            ->numeric()
                            ->step(0.01)
                            ->default(0)
                            ->hint('0 = calcul automatique'),
                    ]),

                Section::make('Assurance emprunteur')
                    ->visible(fn (callable $get) => (bool) $get('has_loan'))
                    ->schema([
                        Select::make('insurance_type')
                            ->label('Type d\'assurance')
                            ->options(\App\Models\Loan::insuranceTypeLabels())
                            ->default('fixed')
                            ->live(),
                        TextInput::make('loan_insurance_monthly')
                            ->label('Montant mensuel')
                            ->suffix('€/mois')
                            ->numeric()
                            ->step(0.01)
                            ->default(0)
                            ->visible(fn (callable $get) => ($get('insurance_type') ?? 'fixed') === 'fixed'),
                        TextInput::make('loan_insurance_rate')
                            ->label('Taux annuel assurance')
                            ->suffix('%')
                            ->numeric()
                            ->step(0.001)
                            ->default(0)
                            ->visible(fn (callable $get) => ($get('insurance_type') ?? 'fixed') === 'variable'),
                    ]),
            ]);
    }

    // -------------------------------------------------------------------------
    // Étape 7 : Amortissements
    // -------------------------------------------------------------------------

    private function stepDepreciation(): Step
    {
        return Step::make('Amortissements')
            ->icon('heroicon-o-building-library')
            ->description('Composants par défaut générés automatiquement')
            ->schema([
                Toggle::make('generate_default_components')
                    ->label('Générer les 6 composants d\'amortissement par défaut')
                    ->helperText('Gros oeuvre (50 ans), Toiture (25 ans), Installation électrique (25 ans), Plomberie (25 ans), Agencements (15 ans), Revêtements (15 ans)')
                    ->default(true),
                Placeholder::make('depreciation_info')
                    ->label('')
                    ->content(new HtmlString(
                        '<div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm dark:border-blue-700 dark:bg-blue-900/20">'
                        . '<p class="font-medium text-blue-800 dark:text-blue-300 mb-2">Comment fonctionne l\'amortissement ?</p>'
                        . '<ul class="list-disc list-inside space-y-1 text-blue-700 dark:text-blue-400">'
                        . '<li>Le bien est décomposé en composants (gros oeuvre, toiture, etc.)</li>'
                        . '<li>Chaque composant est amorti sur sa durée propre</li>'
                        . '<li>Le terrain (non amortissable) est exclu de la base</li>'
                        . '<li>L\'amortissement est plafonné : il ne peut pas créer de déficit</li>'
                        . '<li>L\'excédent est reporté indéfiniment</li>'
                        . '</ul>'
                        . '<p class="mt-3 text-xs text-blue-600 dark:text-blue-500">Vous pourrez ajouter des travaux et du mobilier plus tard.</p>'
                        . '</div>'
                    )),
            ]);
    }

    // -------------------------------------------------------------------------
    // Étape 8 : Récapitulatif
    // -------------------------------------------------------------------------

    private function stepSummary(): Step
    {
        return Step::make('Récapitulatif')
            ->icon('heroicon-o-check-circle')
            ->description('Vérifiez avant de valider')
            ->schema([
                Placeholder::make('summary')
                    ->label('')
                    ->content(function (callable $get) {
                        $lines = [
                            ['Bien', $get('name') ?: '—'],
                            ['Type', Property::typeLabels()[$get('type')] ?? '—'],
                            ['Adresse', implode(', ', array_filter([$get('address'), $get('postal_code'), $get('city')]))],
                            ['Surfaces', ($get('rented_area') ?? '?') . ' m² / ' . ($get('total_area') ?? '?') . ' m²'],
                        ];

                        $price = $get('acquisition_price');
                        if ($price) {
                            $lines[] = ['Prix d\'achat', number_format((float) $price, 0, ',', ' ') . ' €'];
                        }

                        if ($get('has_loan')) {
                            $loanAmount = $get('loan_amount');
                            $lines[] = ['Emprunt', $loanAmount ? number_format((float) $loanAmount, 0, ',', ' ') . ' €' : 'Oui'];
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

        // 1. Créer le bien
        $property = Property::create([
            'user_id' => auth()->id(),
            'name' => $data['name'],
            'type' => $data['type'],
            'rental_type' => $data['rental_type'],
            'address' => $data['address'],
            'city' => $data['city'],
            'postal_code' => $data['postal_code'],
            'total_area' => (int) $data['total_area'],
            'rented_area' => (int) $data['rented_area'],
            'is_primary_residence' => (bool) ($data['is_primary_residence'] ?? false),
            'acquisition_price' => (int) round(((float) ($data['acquisition_price'] ?? 0)) * 100),
            'notary_fees' => (int) round(((float) ($data['notary_fees'] ?? 0)) * 100),
            'acquisition_date' => $data['acquisition_date'],
            'market_value' => isset($data['market_value']) && $data['market_value']
                ? (int) round(((float) $data['market_value']) * 100) : null,
            'market_value_date' => $data['market_value_date'] ?? null,
            'land_percentage' => (int) ($data['land_percentage'] ?? 15),
            'rental_start_date' => $data['rental_start_date'],
            'listing_urls' => $data['listing_urls'] ?? [],
            'notes' => $data['notes'] ?? null,
            'photo_path' => $data['photo_path'] ?? null,
        ]);

        // 2. Générer les composants d'amortissement
        if ($data['generate_default_components'] ?? true) {
            app(DepreciationService::class)->generateDefaultComponents($property);
        }

        // 3. Créer l'emprunt si renseigné
        if (!empty($data['has_loan']) && !empty($data['loan_amount'])) {
            $loan = $property->loans()->create([
                'bank_name' => $data['loan_bank_name'] ?? null,
                'amount' => (int) round(((float) $data['loan_amount']) * 100),
                'annual_rate' => (float) ($data['loan_annual_rate'] ?? 0),
                'duration_months' => (int) ($data['loan_duration_months'] ?? 240),
                'start_date' => $data['loan_start_date'] ?? $data['acquisition_date'],
                'monthly_payment' => (int) round(((float) ($data['loan_monthly_payment'] ?? 0)) * 100),
                'insurance_type' => $data['insurance_type'] ?? 'fixed',
                'insurance_monthly' => (int) round(((float) ($data['loan_insurance_monthly'] ?? 0)) * 100),
                'insurance_rate' => (float) ($data['loan_insurance_rate'] ?? 0),
            ]);

            app(LoanService::class)->generateSchedule($loan);
        }

        Notification::make()
            ->title('Configuration terminée !')
            ->body('Votre bien "' . $property->name . '" a été créé avec succès.')
            ->success()
            ->send();

        $this->redirect('/');
    }
}
