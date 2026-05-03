<?php

namespace App\Services;

use App\Models\Property;
use App\Models\User;

/**
 * Service de calcul pour la déclaration de TVA.
 *
 * Génère les données récapitulatives TVA collectée / déductible
 * pour les biens assujettis à la TVA (para-hôtelier).
 *
 * Tous les montants en centimes.
 */
class TvaDeclarationService
{
    /**
     * Calcule le récapitulatif TVA pour un utilisateur et une année.
     *
     * @return array{
     *     properties: array,
     *     totals: array{collected: int, deductible: int, balance: int},
     *     by_rate: array,
     *     quarters: array
     * }
     */
    public function calculate(User $user, int $year): array
    {
        $properties = Property::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('tva_regime', Property::TVA_LIABLE)
            ->get();

        $propertyResults = [];
        $totalCollected = 0;
        $totalDeductible = 0;
        $byRate = [];
        $quarters = [1 => ['collected' => 0, 'deductible' => 0], 2 => ['collected' => 0, 'deductible' => 0], 3 => ['collected' => 0, 'deductible' => 0], 4 => ['collected' => 0, 'deductible' => 0]];

        foreach ($properties as $property) {
            $result = $this->calculateForProperty($property, $year);
            $propertyResults[] = $result;

            $totalCollected += $result['collected'];
            $totalDeductible += $result['deductible'];

            // Agrégation par taux
            foreach ($result['by_rate'] as $rate => $amounts) {
                if (! isset($byRate[$rate])) {
                    $byRate[$rate] = ['collected' => 0, 'deductible' => 0];
                }
                $byRate[$rate]['collected'] += $amounts['collected'];
                $byRate[$rate]['deductible'] += $amounts['deductible'];
            }

            // Agrégation par trimestre
            foreach ($result['quarters'] as $q => $amounts) {
                $quarters[$q]['collected'] += $amounts['collected'];
                $quarters[$q]['deductible'] += $amounts['deductible'];
            }
        }

        return [
            'properties' => $propertyResults,
            'totals'     => [
                'collected'  => $totalCollected,
                'deductible' => $totalDeductible,
                'balance'    => $totalCollected - $totalDeductible,
            ],
            'by_rate'  => $byRate,
            'quarters' => $quarters,
        ];
    }

    /**
     * Calcule la TVA pour un bien spécifique.
     */
    private function calculateForProperty(Property $property, int $year): array
    {
        $collected = 0;
        $deductible = 0;
        $byRate = [];
        $quarters = [1 => ['collected' => 0, 'deductible' => 0], 2 => ['collected' => 0, 'deductible' => 0], 3 => ['collected' => 0, 'deductible' => 0], 4 => ['collected' => 0, 'deductible' => 0]];

        // TVA collectée (revenus)
        $incomes = $property->incomes()
            ->whereYear('income_date', $year)
            ->where('tva_rate', '>', 0)
            ->get();

        foreach ($incomes as $income) {
            $collected += $income->tva_collected;
            $rate = $income->tva_rate;
            $quarter = (int) ceil($income->income_date->month / 3);

            if (! isset($byRate[$rate])) {
                $byRate[$rate] = ['collected' => 0, 'deductible' => 0];
            }
            $byRate[$rate]['collected'] += $income->tva_collected;
            $quarters[$quarter]['collected'] += $income->tva_collected;
        }

        // TVA déductible (charges)
        $expenses = $property->expenses()
            ->whereYear('expense_date', $year)
            ->where('tva_rate', '>', 0)
            ->get();

        foreach ($expenses as $expense) {
            $effectiveTva = $expense->is_dedicated
                ? $expense->amount_tva
                : (int) bcmul((string) $expense->amount_tva, $property->quota_share, 0);

            $deductible += $effectiveTva;
            $rate = $expense->tva_rate;
            $quarter = (int) ceil($expense->expense_date->month / 3);

            if (! isset($byRate[$rate])) {
                $byRate[$rate] = ['collected' => 0, 'deductible' => 0];
            }
            $byRate[$rate]['deductible'] += $effectiveTva;
            $quarters[$quarter]['deductible'] += $effectiveTva;
        }

        // TVA déductible (travaux) — pas de ventilation trimestrielle, on prend la date des travaux
        $works = $property->works()
            ->where('tva_rate', '>', 0)
            ->get();

        foreach ($works as $work) {
            $workYear = (int) $work->work_date->format('Y');
            if ($workYear !== $year) {
                continue;
            }

            $deductible += $work->amount_tva;
            $rate = $work->tva_rate;
            $quarter = (int) ceil($work->work_date->month / 3);

            if (! isset($byRate[$rate])) {
                $byRate[$rate] = ['collected' => 0, 'deductible' => 0];
            }
            $byRate[$rate]['deductible'] += $work->amount_tva;
            $quarters[$quarter]['deductible'] += $work->amount_tva;
        }

        // TVA déductible (mobilier)
        $furniture = $property->furniture()
            ->where('tva_rate', '>', 0)
            ->get();

        foreach ($furniture as $item) {
            $itemYear = (int) $item->purchase_date->format('Y');
            if ($itemYear !== $year) {
                continue;
            }

            $deductible += $item->amount_tva;
            $rate = $item->tva_rate;
            $quarter = (int) ceil($item->purchase_date->month / 3);

            if (! isset($byRate[$rate])) {
                $byRate[$rate] = ['collected' => 0, 'deductible' => 0];
            }
            $byRate[$rate]['deductible'] += $item->amount_tva;
            $quarters[$quarter]['deductible'] += $item->amount_tva;
        }

        return [
            'property_id'   => $property->id,
            'property_name' => $property->name,
            'collected'     => $collected,
            'deductible'    => $deductible,
            'balance'       => $collected - $deductible,
            'by_rate'       => $byRate,
            'quarters'      => $quarters,
        ];
    }
}
