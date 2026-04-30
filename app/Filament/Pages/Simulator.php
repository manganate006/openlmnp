<?php

namespace App\Filament\Pages;

use App\Services\DepreciationService;
use App\Services\FiscalYearService;
use App\Models\Property;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use UnitEnum;

class Simulator extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;
    protected static string | UnitEnum | null $navigationGroup = 'Fiscal';
    protected static ?string $navigationLabel = 'Simulateur';
    protected static ?string $title = 'Simulateur micro-BIC vs régime réel';
    protected static ?int $navigationSort = 2;
    protected string $view = 'filament.pages.simulator';

    public int $year = 2026;
    public string $abatement = '50'; // 50% classé, 30% non classé

    public function mount(): void
    {
        $this->year = (int) date('Y');
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

        // Détail des amortissements
        $depreciationService = app(DepreciationService::class);
        $depreciationDetails = [];
        foreach ($properties as $property) {
            $dep = $depreciationService->calculateAnnualDepreciation($property, $this->year);
            $depreciationDetails[$property->name] = $dep;
        }

        // Calcul charges détaillées
        $totalExpensesDedicated = 0;
        $totalExpensesShared = 0;
        foreach ($properties as $property) {
            $totalExpensesDedicated += $property->expenses()
                ->whereYear('expense_date', $this->year)
                ->where('is_dedicated', true)
                ->sum('amount');
            $shared = $property->expenses()
                ->whereYear('expense_date', $this->year)
                ->where('is_dedicated', false)
                ->sum('amount');
            $totalExpensesShared += (int) bcmul((string) $shared, $property->quota_share, 0);
        }

        // Économie en impôt (estimation TMI 11% et 30%)
        $advantage = (int) $comparison['advantage'];
        $taxSaving11 = (int) bcmul((string) $advantage, '0.11', 0);
        $taxSaving30 = (int) bcmul((string) $advantage, '0.30', 0);

        // PS savings (18.6%)
        $psSaving = (int) bcmul((string) $advantage, '0.186', 0);

        return [
            'empty' => false,
            'year' => $this->year,
            'abatement' => $this->abatement,
            'gross_income' => $this->formatCents($comparison['gross_income']),
            'micro_bic_result' => $this->formatCents($comparison['micro_bic_result']),
            'real_result' => $this->formatCents($comparison['real_result']),
            'advantage' => $this->formatCents($comparison['advantage']),
            'recommended' => $comparison['recommended'],
            'expenses_dedicated' => $this->formatCents((string) $totalExpensesDedicated),
            'expenses_shared' => $this->formatCents((string) $totalExpensesShared),
            'depreciation_details' => $depreciationDetails,
            'tax_saving_11' => $this->formatCents((string) $taxSaving11),
            'tax_saving_30' => $this->formatCents((string) $taxSaving30),
            'ps_saving' => $this->formatCents((string) $psSaving),
        ];
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
