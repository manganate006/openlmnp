<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\NavigationAware;
use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Expense;
use App\Models\Property;
use App\Services\FiscalYearService;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * Assistant de création d'un exercice fiscal LMNP.
 *
 * Parcours en 4 étapes :
 *   1. Sélection de l'année
 *   2. Vérification des données (recettes, charges, amortissements)
 *   3. Résultat fiscal (plafonnement, amortissement différé, micro-BIC vs réel)
 *   4. Confirmation et clôture
 */
class FiscalYearWizard extends Page
{
    use NavigationAware;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;
    protected static string|UnitEnum|null $navigationGroup = 'Fiscal';
    protected static ?string $navigationLabel = 'Nouvel exercice';
    protected static ?string $title = 'Assistant de clôture fiscale';
    protected static ?int $navigationSort = 2;
    protected static bool $shouldRegisterNavigation = false;

    protected static function getGuidedNavigationGroup(): string
    {
        return 'Déclaration annuelle';
    }
    protected string $view = 'filament.pages.fiscal-year-wizard';

    // -------------------------------------------------------------------------
    // État du formulaire
    // -------------------------------------------------------------------------

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->data = [
            'year'   => (int) date('Y') - 1,
            'status' => FiscalYear::STATUS_DRAFT,
        ];
    }

    // -------------------------------------------------------------------------
    // Schéma du formulaire (wizard)
    // -------------------------------------------------------------------------

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Wizard::make([
                    $this->stepSelectYear(),
                    $this->stepVerifyData(),
                    $this->stepFiscalResult(),
                    $this->stepConfirmation(),
                ])
                ->submitAction(new HtmlString(
                    '<button type="submit"
                        class="fi-btn fi-btn-size-md fi-btn-color-primary fi-color-custom fi-btn-color-primary relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-btn-size-md gap-1.5 px-4 py-2 text-sm inline-grid shadow-sm bg-custom-600 text-white hover:bg-custom-500 focus-visible:ring-custom-500/50 dark:bg-custom-500 dark:hover:bg-custom-400 dark:focus-visible:ring-custom-400/50 fi-color-primary"
                        style="--c-400:var(--primary-400);--c-500:var(--primary-500);--c-600:var(--primary-600);"
                    >
                        Créer l\'exercice fiscal
                    </button>'
                )),
            ]);
    }

    // -------------------------------------------------------------------------
    // Étape 1 : Sélection de l'année
    // -------------------------------------------------------------------------

    private function stepSelectYear(): Step
    {
        $currentYear = (int) date('Y');

        return Step::make('Sélection de l\'année')
            ->icon('heroicon-o-calendar')
            ->description('Choisissez l\'année de l\'exercice fiscal à créer')
            ->schema([
                Section::make()
                    ->schema([
                        Select::make('year')
                            ->label('Année fiscale')
                            ->options($this->buildYearOptions($currentYear))
                            ->default($currentYear - 1)
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->resetComputedData())
                            ->hint('L\'exercice couvre du 1er janvier au 31 décembre'),

                        \Filament\Forms\Components\Placeholder::make('year_summary')
                            ->label('Résumé de l\'année sélectionnée')
                            ->content(fn () => $this->buildYearSummaryHtml()),
                    ]),
            ]);
    }

    // -------------------------------------------------------------------------
    // Étape 2 : Vérification des données
    // -------------------------------------------------------------------------

    private function stepVerifyData(): Step
    {
        return Step::make('Vérification des données')
            ->icon('heroicon-o-clipboard-document-check')
            ->description('Contrôlez les montants avant le calcul fiscal')
            ->schema([
                Section::make('Recettes et charges')
                    ->schema([
                        \Filament\Forms\Components\Placeholder::make('verify_income')
                            ->label('Total recettes nettes')
                            ->content(fn () => $this->buildPlaceholderHtml(
                                $this->getYearIncomeCents(),
                                'Loyers encaissés déduction faite des commissions plateformes',
                                'text-green-700 dark:text-green-400'
                            )),

                        \Filament\Forms\Components\Placeholder::make('verify_expenses')
                            ->label('Total charges déductibles')
                            ->content(fn () => $this->buildPlaceholderHtml(
                                $this->getYearExpensesCents(),
                                'Charges directes + quote-part des charges partagées + intérêts d\'emprunt',
                                'text-red-700 dark:text-red-400'
                            )),

                        \Filament\Forms\Components\Placeholder::make('verify_depreciation')
                            ->label('Total amortissements de l\'année')
                            ->content(fn () => $this->buildPlaceholderHtml(
                                $this->getYearDepreciationCents(),
                                'Immeuble (composants) + travaux + mobilier, au prorata temporis',
                                'text-blue-700 dark:text-blue-400'
                            )),

                        \Filament\Forms\Components\Placeholder::make('verify_alert')
                            ->label('Alertes')
                            ->content(fn () => $this->buildAlertsHtml())
                            ->visible(fn () => $this->hasAlerts()),
                    ]),
            ]);
    }

    // -------------------------------------------------------------------------
    // Étape 3 : Résultat fiscal
    // -------------------------------------------------------------------------

    private function stepFiscalResult(): Step
    {
        return Step::make('Résultat fiscal')
            ->icon('heroicon-o-calculator')
            ->description('Calcul du résultat imposable au régime réel simplifié')
            ->schema([
                Section::make('Détail du calcul')
                    ->schema([
                        \Filament\Forms\Components\Placeholder::make('result_before_dep')
                            ->label('Résultat avant amortissement')
                            ->content(fn () => $this->buildResultLineHtml(
                                $this->getResultBeforeDepreciation(),
                                'Recettes − Charges'
                            )),

                        \Filament\Forms\Components\Placeholder::make('result_carried_forward')
                            ->label('Amortissements différés N−1')
                            ->content(fn () => $this->buildPlaceholderHtml(
                                $this->getPreviousDeferred(),
                                'Report de l\'exercice précédent (non limités dans le temps)',
                                'text-purple-700 dark:text-purple-400'
                            )),

                        \Filament\Forms\Components\Placeholder::make('result_total_dep')
                            ->label('Amortissements disponibles (année + report)')
                            ->content(fn () => $this->buildPlaceholderHtml(
                                $this->getTotalAvailableDepreciation(),
                                'Amortissements de l\'année + différés N−1',
                                'text-blue-700 dark:text-blue-400'
                            )),

                        \Filament\Forms\Components\Placeholder::make('result_capped')
                            ->label('Amortissement plafonné (déduit)')
                            ->content(fn () => $this->buildPlaceholderHtml(
                                $this->getCappedDepreciation(),
                                'Limité au résultat avant amortissement (ne peut pas créer de déficit)',
                                'text-orange-700 dark:text-orange-400'
                            )),

                        \Filament\Forms\Components\Placeholder::make('result_deferred')
                            ->label('Amortissement différé (reporté N+1)')
                            ->content(fn () => $this->buildPlaceholderHtml(
                                $this->getDeferredDepreciation(),
                                'Excédent d\'amortissement reporté indéfiniment',
                                'text-purple-700 dark:text-purple-400'
                            )),

                        \Filament\Forms\Components\Placeholder::make('result_fiscal')
                            ->label('Résultat fiscal final')
                            ->content(fn () => $this->buildFiscalResultHtml()),
                    ]),

                Section::make('Comparaison micro-BIC vs régime réel')
                    ->schema([
                        \Filament\Forms\Components\Placeholder::make('micro_bic_comparison')
                            ->label('Comparatif')
                            ->content(fn () => $this->buildMicroBicComparisonHtml()),
                    ]),
            ]);
    }

    // -------------------------------------------------------------------------
    // Étape 4 : Confirmation
    // -------------------------------------------------------------------------

    private function stepConfirmation(): Step
    {
        return Step::make('Confirmation')
            ->icon('heroicon-o-check-circle')
            ->description('Vérifiez le résumé puis créez l\'exercice')
            ->schema([
                Section::make('Récapitulatif')
                    ->schema([
                        \Filament\Forms\Components\Placeholder::make('confirmation_summary')
                            ->label('')
                            ->content(fn () => $this->buildConfirmationSummaryHtml()),
                    ]),

                Section::make('Statut de l\'exercice')
                    ->schema([
                        Toggle::make('is_closed')
                            ->label('Clôturer l\'exercice')
                            ->helperText('Activez cette option pour marquer l\'exercice comme définitivement clôturé. Désactivé = brouillon modifiable.')
                            ->default(false)
                            ->live()
                            ->afterStateUpdated(fn (bool $state) => $this->data['status'] = $state
                                ? FiscalYear::STATUS_CLOSED
                                : FiscalYear::STATUS_DRAFT
                            ),
                    ]),
            ]);
    }

    // -------------------------------------------------------------------------
    // Action de création de l'exercice
    // -------------------------------------------------------------------------

    public function create(): void
    {
        $year   = (int) ($this->data['year'] ?? date('Y') - 1);
        $status = $this->data['status'] ?? FiscalYear::STATUS_DRAFT;

        $service    = app(FiscalYearService::class);
        $fiscalYear = $service->getOrCreate(auth()->user(), $year);

        $fiscalYear->update(['status' => $status]);

        app(\App\Services\BadgeService::class)->evaluate(auth()->user(), 'fiscal_year_created', [
            'fiscal_year' => $year,
        ]);

        Notification::make()
            ->title('Exercice fiscal créé')
            ->body(sprintf(
                'L\'exercice %d a été %s avec succès.',
                $year,
                $status === FiscalYear::STATUS_CLOSED ? 'clôturé' : 'enregistré en brouillon'
            ))
            ->success()
            ->send();

        $this->redirect(route('filament.admin.resources.fiscal-years.index'));
    }

    // -------------------------------------------------------------------------
    // Méthodes de calcul (données de l'année sélectionnée)
    // -------------------------------------------------------------------------

    private function selectedYear(): int
    {
        return (int) ($this->data['year'] ?? date('Y') - 1);
    }

    #[Computed]
    public function properties()
    {
        return Property::all();
    }

    private function getYearIncomeCents(): int
    {
        $year       = $this->selectedYear();
        $properties = Property::all();
        $total      = 0;

        foreach ($properties as $property) {
            $net = $property->incomes()
                ->whereYear('income_date', $year)
                ->selectRaw('SUM(amount) - SUM(platform_fee) as net_income')
                ->value('net_income');
            $total += (int) ($net ?? 0);
        }

        return $total;
    }

    private function getYearExpensesCents(): int
    {
        $year       = $this->selectedYear();
        $properties = Property::all();
        $total      = 0;

        foreach ($properties as $property) {
            // Charges 100 % dédiées
            $dedicated = $property->expenses()
                ->whereYear('expense_date', $year)
                ->where('is_dedicated', true)
                ->sum('amount');
            $total += (int) $dedicated;

            // Charges partagées au prorata
            $shared = $property->expenses()
                ->whereYear('expense_date', $year)
                ->where('is_dedicated', false)
                ->sum('amount');
            $total += (int) bcmul((string) $shared, $property->quota_share, 0);

            // Intérêts et assurances d'emprunt (quote-part)
            foreach ($property->loans as $loan) {
                $interest  = $loan->payments()->whereYear('payment_date', $year)->sum('interest_amount');
                $insurance = $loan->payments()->whereYear('payment_date', $year)->sum('insurance_amount');
                $total    += (int) bcmul((string) ($interest + $insurance), $property->quota_share, 0);
            }
        }

        return $total;
    }

    private function getYearDepreciationCents(): int
    {
        $year             = $this->selectedYear();
        $properties       = Property::all();
        $depService       = app(\App\Services\DepreciationService::class);
        $total            = 0;

        foreach ($properties as $property) {
            $dep    = $depService->calculateAnnualDepreciation($property, $year);
            $total += (int) $dep['total'];
        }

        return $total;
    }

    private function getPreviousDeferred(): int
    {
        $year     = $this->selectedYear();
        $previous = FiscalYear::withoutGlobalScopes()
            ->where('user_id', auth()->id())
            ->where('year', $year - 1)
            ->first();

        return (int) ($previous?->deferred_depreciation ?? 0);
    }

    private function getResultBeforeDepreciation(): int
    {
        return $this->getYearIncomeCents() - $this->getYearExpensesCents();
    }

    private function getTotalAvailableDepreciation(): int
    {
        return $this->getYearDepreciationCents() + $this->getPreviousDeferred();
    }

    private function getCappedDepreciation(): int
    {
        $resultBefore = $this->getResultBeforeDepreciation();
        $available    = $this->getTotalAvailableDepreciation();

        if ($resultBefore <= 0) {
            return 0;
        }

        return min($available, $resultBefore);
    }

    private function getDeferredDepreciation(): int
    {
        $resultBefore = $this->getResultBeforeDepreciation();
        $available    = $this->getTotalAvailableDepreciation();

        if ($resultBefore <= 0) {
            return $available;
        }

        return max(0, $available - $resultBefore);
    }

    private function getFiscalResult(): int
    {
        return max(0, $this->getResultBeforeDepreciation() - $this->getCappedDepreciation());
    }

    private function getIncomeCount(): int
    {
        $year       = $this->selectedYear();
        $properties = Property::all();
        $count      = 0;

        foreach ($properties as $property) {
            $count += $property->incomes()->whereYear('income_date', $year)->count();
        }

        return $count;
    }

    private function getExpenseCount(): int
    {
        $year       = $this->selectedYear();
        $properties = Property::all();
        $count      = 0;

        foreach ($properties as $property) {
            $count += $property->expenses()->whereYear('expense_date', $year)->count();
        }

        return $count;
    }

    private function hasAlerts(): bool
    {
        return $this->getYearIncomeCents() === 0 || Property::count() === 0;
    }

    // -------------------------------------------------------------------------
    // Aide au formatage
    // -------------------------------------------------------------------------

    private function formatEuros(int $cents): string
    {
        $euros = $cents / 100;
        $sign  = $euros < 0 ? '−&nbsp;' : '';
        return $sign . number_format(abs($euros), 2, ',', '&nbsp;') . '&nbsp;€';
    }

    private function buildYearOptions(int $currentYear): array
    {
        $options = [];
        for ($y = $currentYear + 2; $y >= $currentYear - 2; $y--) {
            $options[$y] = $y;
        }
        return $options;
    }

    private function resetComputedData(): void
    {
        // Livewire se charge de la réactivité via live()
    }

    // -------------------------------------------------------------------------
    // Constructeurs HTML des placeholders
    // -------------------------------------------------------------------------

    private function buildYearSummaryHtml(): HtmlString
    {
        $year          = $this->selectedYear();
        $propertyCount = Property::count();
        $incomeCount   = $this->getIncomeCount();
        $expenseCount  = $this->getExpenseCount();
        $existing      = FiscalYear::withoutGlobalScopes()
            ->where('user_id', auth()->id())
            ->where('year', $year)
            ->first();

        $existingBadge = $existing
            ? '<span class="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900 dark:text-amber-200">Exercice existant — sera recalculé</span>'
            : '';

        return new HtmlString(
            '<div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50">'
            . '<p class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">'
            . 'Année ' . $year . $existingBadge
            . '</p>'
            . '<div class="grid grid-cols-3 gap-4">'
            . $this->buildStatCard('Biens', $propertyCount, 'text-indigo-600', 'bien(s) enregistré(s)')
            . $this->buildStatCard('Recettes', $incomeCount, 'text-green-600', 'ligne(s) de revenu')
            . $this->buildStatCard('Charges', $expenseCount, 'text-red-600', 'ligne(s) de charge')
            . '</div>'
            . '</div>'
        );
    }

    private function buildStatCard(string $label, int|string $value, string $colorClass, string $subtitle): string
    {
        return '<div class="text-center">'
            . '<div class="text-2xl font-bold ' . $colorClass . '">' . $value . '</div>'
            . '<div class="text-xs text-gray-500 dark:text-gray-400 mt-1">' . $subtitle . '</div>'
            . '</div>';
    }

    private function buildPlaceholderHtml(int $cents, string $detail, string $colorClass): HtmlString
    {
        return new HtmlString(
            '<div class="flex flex-col gap-0.5">'
            . '<span class="text-xl font-bold ' . $colorClass . '">' . $this->formatEuros($cents) . '</span>'
            . '<span class="text-xs text-gray-500 dark:text-gray-400">' . $detail . '</span>'
            . '</div>'
        );
    }

    private function buildResultLineHtml(int $cents, string $formula): HtmlString
    {
        $color = $cents >= 0
            ? 'text-green-700 dark:text-green-400'
            : 'text-red-700 dark:text-red-400';

        return new HtmlString(
            '<div class="flex flex-col gap-0.5">'
            . '<span class="text-xl font-bold ' . $color . '">' . $this->formatEuros($cents) . '</span>'
            . '<span class="text-xs text-gray-500 dark:text-gray-400">' . $formula . '</span>'
            . '</div>'
        );
    }

    private function buildFiscalResultHtml(): HtmlString
    {
        $result = $this->getFiscalResult();
        $color  = $result <= 0
            ? 'bg-green-50 border-green-300 dark:bg-green-900/30 dark:border-green-700'
            : 'bg-blue-50 border-blue-300 dark:bg-blue-900/30 dark:border-blue-700';

        $label = $result === 0
            ? 'Résultat nul — aucune imposition au réel'
            : 'Montant imposable au régime réel simplifié';

        return new HtmlString(
            '<div class="rounded-lg border p-4 ' . $color . '">'
            . '<div class="text-2xl font-bold text-gray-900 dark:text-white">' . $this->formatEuros($result) . '</div>'
            . '<div class="mt-1 text-sm text-gray-600 dark:text-gray-400">' . $label . '</div>'
            . '</div>'
        );
    }

    private function buildMicroBicComparisonHtml(): HtmlString
    {
        $year        = $this->selectedYear();
        $properties  = Property::all();

        // CA brut pour le micro-BIC (sans déduire commissions)
        $grossIncome = 0;
        foreach ($properties as $property) {
            $grossIncome += (int) $property->incomes()
                ->whereYear('income_date', $year)
                ->sum('amount');
        }

        $microBic50 = (int) bcmul((string) $grossIncome, '0.5', 0);
        $microBic30 = (int) bcmul((string) $grossIncome, '0.7', 0); // abattement 30 %, 70 % imposable
        $realResult = $this->getFiscalResult();

        $advantageVs50 = $microBic50 - $realResult;
        $advantageVs30 = $microBic30 - $realResult;

        $recommended = $realResult < $microBic50 ? 'réel' : 'micro-BIC (50 %)';

        $rows = [
            ['Régime', 'Base imposable', 'Avantage vs réel'],
            ['Micro-BIC 50 % (classé)', $this->formatEuros($microBic50), $this->formatSignedEuros($advantageVs50)],
            ['Micro-BIC 30 % (non classé)', $this->formatEuros($microBic30), $this->formatSignedEuros($advantageVs30)],
            ['Régime réel simplifié', $this->formatEuros($realResult), '— référence —'],
        ];

        $headerRow = array_shift($rows);

        $tableHtml = '<table class="w-full text-sm border-collapse">'
            . '<thead><tr class="bg-gray-100 dark:bg-gray-700">'
            . implode('', array_map(
                fn ($h) => '<th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">' . $h . '</th>',
                $headerRow
            ))
            . '</tr></thead><tbody>';

        foreach ($rows as $i => $row) {
            $bg         = $i % 2 === 0 ? '' : 'bg-gray-50 dark:bg-gray-800/50';
            $tableHtml .= '<tr class="' . $bg . '">';
            foreach ($row as $j => $cell) {
                $align      = $j === 0 ? 'text-left' : 'text-right font-mono';
                $tableHtml .= '<td class="px-3 py-2 ' . $align . '">' . $cell . '</td>';
            }
            $tableHtml .= '</tr>';
        }

        $tableHtml .= '</tbody></table>';

        $verdictColor = $realResult < $microBic50
            ? 'bg-green-50 border-green-300 text-green-800 dark:bg-green-900/30 dark:border-green-700 dark:text-green-300'
            : 'bg-amber-50 border-amber-300 text-amber-800 dark:bg-amber-900/30 dark:border-amber-700 dark:text-amber-300';

        return new HtmlString(
            '<div class="flex flex-col gap-4">'
            . '<div class="overflow-auto rounded-lg border border-gray-200 dark:border-gray-700">' . $tableHtml . '</div>'
            . '<div class="rounded-lg border p-3 text-sm font-medium ' . $verdictColor . '">'
            . 'Recommandation : <strong>' . $recommended . '</strong>'
            . '</div>'
            . '</div>'
        );
    }

    private function buildAlertsHtml(): HtmlString
    {
        $alerts = [];

        if (Property::count() === 0) {
            $alerts[] = 'Aucun bien immobilier enregistré. Créez au moins un bien avant de lancer la clôture.';
        } elseif ($this->getYearIncomeCents() === 0) {
            $alerts[] = 'Aucune recette trouvée pour ' . $this->selectedYear() . '. L\'exercice sera créé avec un résultat nul.';
        }

        if (empty($alerts)) {
            return new HtmlString('');
        }

        $items = implode('', array_map(
            fn ($a) => '<li class="flex items-start gap-2"><span class="mt-0.5 text-amber-500">⚠</span><span>' . $a . '</span></li>',
            $alerts
        ));

        return new HtmlString(
            '<ul class="flex flex-col gap-2 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300">'
            . $items
            . '</ul>'
        );
    }

    private function buildConfirmationSummaryHtml(): HtmlString
    {
        $year    = $this->selectedYear();
        $status  = ($this->data['status'] ?? FiscalYear::STATUS_DRAFT) === FiscalYear::STATUS_CLOSED
            ? 'Clôturé'
            : 'Brouillon';

        $lines = [
            ['Année fiscale', (string) $year],
            ['Recettes nettes', $this->formatEuros($this->getYearIncomeCents())],
            ['Charges déductibles', $this->formatEuros($this->getYearExpensesCents())],
            ['Amortissements de l\'année', $this->formatEuros($this->getYearDepreciationCents())],
            ['Amortissements différés N−1', $this->formatEuros($this->getPreviousDeferred())],
            ['Amortissement plafonné (déduit)', $this->formatEuros($this->getCappedDepreciation())],
            ['Amortissement différé (reporté)', $this->formatEuros($this->getDeferredDepreciation())],
            ['Résultat fiscal', $this->formatEuros($this->getFiscalResult())],
            ['Statut', $status],
        ];

        $rows = implode('', array_map(function ($line) {
            [$label, $value] = $line;
            return '<tr class="border-b border-gray-100 dark:border-gray-700 last:border-0">'
                . '<td class="py-2 pr-4 text-sm text-gray-600 dark:text-gray-400 font-medium">' . $label . '</td>'
                . '<td class="py-2 text-sm text-right font-mono text-gray-900 dark:text-white">' . $value . '</td>'
                . '</tr>';
        }, $lines));

        return new HtmlString(
            '<div class="rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">'
            . '<table class="w-full"><tbody>' . $rows . '</tbody></table>'
            . '</div>'
        );
    }

    private function formatSignedEuros(int $cents): string
    {
        if ($cents === 0) {
            return '<span class="text-gray-500">0,00&nbsp;€</span>';
        }

        if ($cents > 0) {
            return '<span class="text-green-600">+&nbsp;' . $this->formatEuros($cents) . '</span>';
        }

        return '<span class="text-red-600">' . $this->formatEuros($cents) . '</span>';
    }
}
