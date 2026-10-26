<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\NavigationAware;
use App\Services\BadgeService;
use App\Services\DepreciationService;
use App\Services\FiscalYearService;
use App\Models\Expense;
use App\Models\Property;
use App\Models\RentMapIndicator;
use App\Services\OpenData\RentMapDiscovery;
use App\Services\OpenData\RentMapSource;
use App\Support\MicroBicRules;
use BackedEnum;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use UnitEnum;

class Simulator extends Page implements HasForms
{
    use InteractsWithForms, NavigationAware;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;
    protected static string | UnitEnum | null $navigationGroup = 'Fiscal';
    protected static ?string $navigationLabel = 'Simulateur';
    protected static ?string $title = 'Simulateur micro-BIC vs régime réel';
    protected static ?int $navigationSort = 2;

    protected static function isHiddenInSimpleMode(): bool
    {
        return true;
    }

    protected static function getGuidedNavigationGroup(): string
    {
        return 'Déclaration annuelle';
    }
    protected string $view = 'filament.pages.simulator';

    public int $year = 2026;
    public string $abatement = '50';

    /** Taux micro-BIC d'une location meublée : 30 % tourisme non classé, 50 % sinon. */
    public const ABATEMENTS = ['30', '50'];

    private const CATEGORY_LABELS = [
        'property_tax' => 'Taxes (TF, CFE)',
        'insurance'    => 'Assurance',
        'energy'       => 'Énergie',
        'maintenance'  => 'Entretien / réparations',
        'telecom'      => 'Télécom / Internet',
        'supplies'     => 'Fournitures',
        'platform_fees' => 'Commissions plateforme',
        'accounting'   => 'Comptabilité',
        'cleaning'     => 'Ménage',
        'travel'       => 'Déplacements',
        'other'        => 'Divers',
    ];

    public function mount(): void
    {
        $this->year = (int) (request()->query('year', date('Y')));

        // Classement du premier bien (ordre alphabétique) comme repère : mieux qu'un
        // 50 % systématique, qui pouvait laisser croire un micro-BIC classé possible
        // à qui loue en meublé de tourisme non classé.
        $firstClassification = Property::query()->orderBy('name')->value('tourism_classification');
        if ($firstClassification) {
            $this->abatement = MicroBicRules::rate($firstClassification, $this->year);
        }

        app(BadgeService::class)->evaluate(auth()->user(), 'simulator_used');

        // Événement navigateur relayé vers le dataLayer GTM (partials/gtm-head).
        // regime_recommended est une donnée dérivée (jamais les montants saisis).
        $this->dispatch('analytics', [
            'event' => 'simulator_used',
            'regime_recommended' => $this->simulationResults['recommended'] ?? null,
        ]);
    }

    #[Computed]
    public function simulationResults(): array
    {
        $user = auth()->user();
        $properties = Property::all();

        if ($properties->isEmpty()) {
            return ['empty' => true];
        }

        $fiscalService = app(FiscalYearService::class);
        $comparison = $fiscalService->compareMicroBicVsReal($user, $this->year, $this->abatement);

        // Récupérer le fiscal year calculé pour les données détaillées
        $fiscalYear = $fiscalService->getOrCreate($user, $this->year);

        $depreciationService = app(DepreciationService::class);

        // Recettes détaillées
        $grossIncome = 0;
        $platformFees = 0;
        foreach ($properties as $property) {
            $amountField = $property->isTvaLiable() ? 'amount_ht' : 'amount';
            $grossIncome += $property->incomes()->whereYear('income_date', $this->year)->sum($amountField);
            $platformFees += $property->incomes()->whereYear('income_date', $this->year)->sum('platform_fee');
        }
        $netIncome = $grossIncome - $platformFees;

        // Charges par catégorie
        $expensesByCategory = [];
        $totalExpensesDedicated = 0;
        $totalExpensesShared = 0;
        foreach ($properties as $property) {
            $amountField = $property->isTvaLiable() ? 'amount_ht' : 'amount';
            $expenses = $property->expenses()
                ->whereYear('expense_date', $this->year)
                ->selectRaw('category, is_dedicated, SUM(' . Expense::usageAdjustedSqlFragment($amountField) . ') as total')
                ->groupBy('category', 'is_dedicated')
                ->get();

            foreach ($expenses as $exp) {
                $cat = $exp->category;
                if (! isset($expensesByCategory[$cat])) {
                    $expensesByCategory[$cat] = ['label' => self::CATEGORY_LABELS[$cat] ?? $cat, 'dedicated' => 0, 'shared' => 0, 'effective' => 0];
                }
                if ($exp->is_dedicated) {
                    $expensesByCategory[$cat]['dedicated'] += (int) $exp->total;
                    $expensesByCategory[$cat]['effective'] += (int) $exp->total;
                    $totalExpensesDedicated += (int) $exp->total;
                } else {
                    $expensesByCategory[$cat]['shared'] += (int) $exp->total;
                    $effective = (int) bcmul((string) $exp->total, $property->quota_share, 0);
                    $expensesByCategory[$cat]['effective'] += $effective;
                    $totalExpensesShared += $effective;
                }
            }
        }

        // Intérêts et assurance emprunt
        $loanInterest = 0;
        $loanInsurance = 0;
        foreach ($properties as $property) {
            foreach ($property->loans as $loan) {
                $interest = $loan->payments()->whereYear('payment_date', $this->year)->sum('interest_amount');
                $loanInterest += (int) $loan->prorate((string) $interest, $property->quota_share);

                $insurance = $loan->payments()->whereYear('payment_date', $this->year)->sum('insurance_amount');
                $loanInsurance += (int) $loan->prorate((string) $insurance, $property->quota_share);
            }
        }

        $totalExpenses = $totalExpensesDedicated + $totalExpensesShared + $loanInterest + $loanInsurance;
        $resultBeforeDepreciation = $netIncome - $totalExpenses;

        // Amortissements détaillés
        $depBuilding = 0;
        $depFurniture = 0;
        $depNotary = 0;
        $depreciationDetails = [];
        foreach ($properties as $property) {
            $dep = $depreciationService->calculateAnnualDepreciation($property, $this->year);
            $depBuilding += (int) $dep['building'];
            $depFurniture += (int) $dep['furniture'];
            $depNotary += (int) $dep['notary'];
            $depreciationDetails[$property->name] = $dep;
        }
        $totalDepreciation = $depBuilding + $depFurniture + $depNotary;

        // Plafonnement
        $cappedDepreciation = $totalDepreciation;
        $deferredDepreciation = 0;
        if ($totalDepreciation > $resultBeforeDepreciation && $resultBeforeDepreciation > 0) {
            $cappedDepreciation = $resultBeforeDepreciation;
            $deferredDepreciation = $totalDepreciation - $resultBeforeDepreciation;
        } elseif ($resultBeforeDepreciation <= 0) {
            $cappedDepreciation = 0;
            $deferredDepreciation = $totalDepreciation;
        }

        $fiscalResult = max(0, $resultBeforeDepreciation - $cappedDepreciation);

        // Économie en impôt
        $advantage = (int) $comparison['advantage'];
        $taxSaving11 = (int) bcmul((string) $advantage, '0.11', 0);
        $taxSaving30 = (int) bcmul((string) $advantage, '0.30', 0);
        $psSaving = (int) bcmul((string) $advantage, '0.186', 0);

        return [
            'empty' => false,
            'year' => $this->year,
            'abatement' => $this->abatement,
            // Comparaison principale
            'gross_income' => $this->fmt($grossIncome),
            'platform_fees' => $this->fmt($platformFees),
            'net_income' => $this->fmt($netIncome),
            'micro_bic_result' => $this->formatCents($comparison['micro_bic_result']),
            'real_result' => $this->formatCents($comparison['real_result']),
            'advantage' => $this->formatCents($comparison['advantage']),
            'recommended' => $comparison['available'] ? $comparison['recommended'] : 'real',
            'micro_bic_available' => $comparison['available'],
            'micro_bic_unavailable_reason' => $comparison['unavailable_reason'],
            'micro_bic_warning' => $comparison['warning'] ?? null,
            // Charges détaillées
            'expenses_dedicated' => $this->fmt($totalExpensesDedicated),
            'expenses_shared' => $this->fmt($totalExpensesShared),
            'expenses_by_category' => $expensesByCategory,
            'loan_interest' => $this->fmt($loanInterest),
            'loan_insurance' => $this->fmt($loanInsurance),
            'total_expenses' => $this->fmt($totalExpenses),
            // Waterfall
            'result_before_depreciation' => $this->fmt($resultBeforeDepreciation),
            // Amortissements
            'depreciation_building' => $this->fmt($depBuilding),
            'depreciation_furniture' => $this->fmt($depFurniture),
            'depreciation_notary' => $this->fmt($depNotary),
            'total_depreciation' => $this->fmt($totalDepreciation),
            'capped_depreciation' => $this->fmt($cappedDepreciation),
            'deferred_depreciation' => $this->fmt($deferredDepreciation),
            'depreciation_details' => $depreciationDetails,
            'fiscal_result' => $this->fmt($fiscalResult),
            // Économie
            'tax_saving_11' => $this->fmt($taxSaving11),
            'tax_saving_30' => $this->fmt($taxSaving30),
            'ps_saving' => $this->fmt($psSaving),
            // Raw values for charts (centimes) — données fiables du fiscal year
            'chart_data' => [
                'micro_bic' => (int) $comparison['micro_bic_result'],
                'real' => (int) $comparison['real_result'],
                'net_income' => (int) $fiscalYear->total_income,
                'total_expenses' => (int) $fiscalYear->total_expenses,
                'total_depreciation' => (int) $fiscalYear->capped_depreciation,
                'fiscal_result' => (int) $fiscalYear->fiscal_result,
                'expenses_dedicated' => $totalExpensesDedicated,
                'expenses_shared' => $totalExpensesShared,
                'loan_interest' => $loanInterest,
                'loan_insurance' => $loanInsurance,
                'dep_building' => $depBuilding,
                'dep_furniture' => $depFurniture,
                'dep_notary' => $depNotary,
            ],
        ];
    }

    /**
     * Repère de loyer du marché (« Carte des loyers », ANIL), bien par bien.
     *
     * ⚠️ Indépendant de l'année et de l'abattement : ce n'est PAS un terme de la simulation
     * fiscale, seulement un repère affiché à côté. Aucune requête HTTP ici — uniquement une
     * lecture de `rent_map_indicators`, alimentée à l'avance par `openlmnp:refresh-rent-map`.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function rentBenchmarks(): array
    {
        if (! RentMapSource::enabled()) {
            return [];
        }

        return Property::all()
            ->map(fn (Property $property) => $this->rentBenchmarkFor($property))
            ->all();
    }

    /**
     * Millésime RÉELLEMENT en base (le plus récent) — jamais `RentMapSource::VINTAGE`, qui
     * n'est qu'un repli de bootstrap. `null` si la table est vide (aucun import n'a encore
     * réussi).
     */
    #[Computed]
    public function rentMapVintage(): ?int
    {
        return RentMapIndicator::currentVintage();
    }

    /** Page du jeu data.gouv.fr du millésime en base, calculée à partir du slug — ou `null`. */
    #[Computed]
    public function rentMapDatasetUrl(): ?string
    {
        $vintage = $this->rentMapVintage();

        return $vintage !== null ? RentMapDiscovery::datasetPageUrl($vintage) : null;
    }

    /** @return array<string, mixed> */
    private function rentBenchmarkFor(Property $property): array
    {
        $base = [
            'property_name' => $property->name,
            'city' => $property->city,
        ];

        if ($property->insee_code === null) {
            return $base + ['status' => 'unknown_commune'];
        }

        $indicator = RentMapIndicator::forProperty($property);

        if ($indicator === null) {
            return $base + ['status' => 'not_covered', 'insee_code' => $property->insee_code];
        }

        $surface = max(0, (int) $property->total_area);
        $estimatedMonthlyCents = (int) bcmul((string) $indicator->per_m2_cents, (string) $surface, 0);

        return $base + [
            'status' => 'ok',
            'insee_code' => $property->insee_code,
            'surface_m2' => $surface,
            // 1 décimale pour le €/m² (jamais rond), 0 pour le montant estimé — même
            // convention que la colonne « Loyer vide / m² » du tableau `/lmnp` côté vitrine.
            'per_m2' => $this->fmtPerM2($indicator->per_m2_cents),
            'low_per_m2' => $this->fmtPerM2($indicator->low_per_m2_cents),
            'high_per_m2' => $this->fmtPerM2($indicator->high_per_m2_cents),
            'estimated_monthly' => $this->fmt($estimatedMonthlyCents),
            'vintage' => $indicator->vintage,
            'prediction_level' => $indicator->prediction_level,
            'observations' => $indicator->observations,
            'caution' => $indicator->callsForCaution(),
        ];
    }

    private function fmt(int $cents): string
    {
        return number_format($cents / 100, 0, ',', ' ');
    }

    private function fmtPerM2(int $cents): string
    {
        return number_format($cents / 100, 1, ',', ' ');
    }

    private function formatCents(string $cents): string
    {
        return number_format((int) $cents / 100, 0, ',', ' ');
    }

    public function updatedYear(): void
    {
        unset($this->simulationResults);
        $this->dispatch('sim-chart-update', data: $this->simulationResults['chart_data'] ?? []);
    }

    /**
     * Une propriété Livewire publique s'écrit depuis le navigateur : sans la garde, un
     * ancien choix « 71 » resté dans le localStorage (ou toute autre valeur) passait tel
     * quel au calcul.
     */
    public function updatedAbatement(): void
    {
        if (! in_array($this->abatement, self::ABATEMENTS, true)) {
            $this->abatement = '50';
        }

        unset($this->simulationResults);
        $this->dispatch('sim-chart-update', data: $this->simulationResults['chart_data'] ?? []);
    }
}
