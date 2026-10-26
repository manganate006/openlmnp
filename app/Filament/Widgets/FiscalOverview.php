<?php

namespace App\Filament\Widgets;

use App\Models\Expense;
use App\Models\Income;
use App\Models\Property;
use App\Services\DepreciationService;
use App\Support\MicroBicRules;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
class FiscalOverview extends StatsOverviewWidget
{
    use Concerns\HiddenForMemberPrincipal;

    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = [
        'default' => 1,
        'sm' => 2,
        'lg' => 4,
    ];

    protected function getColumns(): int|array
    {
        return [
            'default' => 1,
            'sm' => 2,
            'lg' => 4,
        ];
    }

    protected ?string $heading = null;

    protected function getStats(): array
    {
        $year = (int) date('Y');
        $properties = Property::all();

        // Guide de démarrage si aucun bien
        if ($properties->isEmpty()) {
            return [
                Stat::make('Étape 1', 'Ajoutez votre bien immobilier')
                    ->description('Menu Mes biens → Biens immobiliers → Nouveau')
                    ->icon('heroicon-o-home-modern')
                    ->color('primary')
                    ->url('/properties/create'),
                Stat::make('Étape 2', 'Saisissez vos recettes')
                    ->description('Après avoir ajouté un bien')
                    ->icon('heroicon-o-banknotes')
                    ->color('gray'),
                Stat::make('Étape 3', 'Consultez le simulateur')
                    ->description('Comparez micro-BIC vs régime réel')
                    ->icon('heroicon-o-calculator')
                    ->color('gray'),
            ];
        }

        $current = $this->calculateYear($properties, $year);
        $previous = $this->calculateYear($properties, $year - 1);

        $stats = [];

        // Ligne N (année en cours)
        $stats = array_merge($stats, $this->buildYearStats($current, $year, $properties->count(), true));

        // Ligne N-1 (année précédente) — seulement si des données existent
        if ($previous['totalIncome'] > 0 || $previous['totalExpenses'] > 0) {
            $stats = array_merge($stats, $this->buildYearStats($previous, $year - 1, $properties->count(), false));
        }

        return $stats;
    }

    private function calculateYear($properties, int $year): array
    {
        $totalIncome = 0;
        $totalExpenses = 0;
        $totalDepreciation = 0;
        $incomeCount = 0;
        $expenseCount = 0;

        foreach ($properties as $property) {
            $totalIncome += $property->incomes()->whereYear('income_date', $year)->sum('amount');
            $incomeCount += $property->incomes()->whereYear('income_date', $year)->count();

            $dedicated = $property->expenses()->whereYear('expense_date', $year)->where('is_dedicated', true)->sum(Expense::usageAdjustedSql('amount'));
            $shared = $property->expenses()->whereYear('expense_date', $year)->where('is_dedicated', false)->sum(Expense::usageAdjustedSql('amount'));
            $totalExpenses += $dedicated + (int) bcmul((string) $shared, $property->quota_share, 0);
            $expenseCount += $property->expenses()->whereYear('expense_date', $year)->count();

            $depreciation = app(DepreciationService::class)->calculateAnnualDepreciation($property, $year);
            $totalDepreciation += (int) $depreciation['total'];
        }

        $resultBefore = $totalIncome - $totalExpenses;
        $cappedDepreciation = min($totalDepreciation, max(0, $resultBefore));
        $fiscalResult = max(0, $resultBefore - $cappedDepreciation);

        // Classement le plus restrictif du portefeuille (celui qui plafonne le plus
        // tôt) : un dashboard qui agrège plusieurs biens ne peut pas choisir le
        // régime le plus flatteur sans risquer d'annoncer un micro-BIC qui ne serait
        // en réalité pas disponible pour l'activité la moins bien lotie.
        $classification = self::mostRestrictiveClassification($properties, $year, $totalIncome);
        $microBicResult = (int) bcmul((string) $totalIncome, bcdiv(MicroBicRules::rate($classification, $year), '100', 10), 0);
        // Micro-BIC : mêmes règles qu'un dossier individuel pour une indivision (piège P13,
        // simplification du 2026-10-15 — voir App\Support\Indivision).
        $microBicAvailable = MicroBicRules::isAvailable($classification, $year, $totalIncome);

        return compact(
            'totalIncome', 'totalExpenses', 'totalDepreciation',
            'incomeCount', 'expenseCount',
            'fiscalResult', 'microBicResult', 'microBicAvailable',
        );
    }

    private static function mostRestrictiveClassification($properties, int $year, int $totalIncome): string
    {
        $classifications = $properties->pluck('tourism_classification')->filter()->unique();

        if ($classifications->isEmpty()) {
            return MicroBicRules::UNCLASSIFIED;
        }

        return $classifications->sortBy(fn (string $c) => MicroBicRules::ceilingCents($c, $year) ?: PHP_INT_MAX)->first();
    }

    private function buildYearStats(array $data, int $year, int $propertyCount, bool $isCurrent): array
    {
        $fmt = fn (int $cents) => number_format($cents / 100, 0, ',', ' ');
        $color = fn (string $c) => $isCurrent ? $c : 'gray';

        return [
            Stat::make("Recettes {$year}", "{$fmt($data['totalIncome'])} €")
                ->description("{$data['incomeCount']} recette(s) · {$propertyCount} bien(s)")
                ->icon('heroicon-o-banknotes')
                ->color($color('success'))
                ->url($isCurrent ? '/incomes' : null),

            Stat::make("Charges {$year}", "{$fmt($data['totalExpenses'])} €")
                ->description("{$data['expenseCount']} charge(s) · Après quote-part")
                ->icon('heroicon-o-receipt-percent')
                ->color($color('warning'))
                ->url($isCurrent ? '/expenses' : null),

            Stat::make("Amortissements {$year}", "{$fmt($data['totalDepreciation'])} €")
                ->description('Immeuble + travaux + mobilier')
                ->icon('heroicon-o-building-library')
                ->color($color('info')),

            Stat::make("Résultat {$year} (réel)", "{$fmt($data['fiscalResult'])} €")
                ->description($data['microBicAvailable']
                    ? "Micro-BIC : {$fmt($data['microBicResult'])} € · " . ($data['fiscalResult'] < $data['microBicResult'] ? '✓ Réel avantageux' : '⚠ Micro-BIC avantageux')
                    : 'Micro-BIC non disponible (plafond dépassé) · régime réel obligatoire')
                ->icon('heroicon-o-calculator')
                ->color((! $data['microBicAvailable'] || $data['fiscalResult'] < $data['microBicResult']) ? ($isCurrent ? 'success' : 'gray') : ($isCurrent ? 'danger' : 'gray'))
                ->url($isCurrent ? '/simulator' : null),
        ];
    }
}
