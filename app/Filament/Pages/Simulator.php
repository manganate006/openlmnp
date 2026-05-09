<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\NavigationAware;
use App\Services\BadgeService;
use App\Services\DepreciationService;
use App\Services\FiscalYearService;
use App\Models\Property;
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
        $this->year = (int) date('Y');

        app(BadgeService::class)->evaluate(auth()->user(), 'simulator_used');
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
                ->selectRaw("category, is_dedicated, SUM({$amountField}) as total")
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
                $loanInterest += (int) bcmul((string) $interest, $property->quota_share, 0);

                $insurance = $loan->payments()->whereYear('payment_date', $this->year)->sum('insurance_amount');
                $loanInsurance += (int) bcmul((string) $insurance, $property->quota_share, 0);
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
            'recommended' => $comparison['recommended'],
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

    private function fmt(int $cents): string
    {
        return number_format($cents / 100, 0, ',', ' ');
    }

    private function formatCents(string $cents): string
    {
        return number_format((int) $cents / 100, 0, ',', ' ');
    }

    public function updatedYear(): void
    {
        unset($this->simulationResults);
    }

    public function updatedAbatement(): void
    {
        unset($this->simulationResults);
    }
}
