<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;

class OnboardingChecklistService
{
    public function getChecklist(User $user, int $year): array
    {
        $propertyIds = $user->properties()->pluck('id');
        $hasProperties = $propertyIds->isNotEmpty();
        $hasIncomes = $hasProperties && Income::whereIn('property_id', $propertyIds)
            ->whereYear('income_date', $year)->exists();
        $hasExpenses = $hasProperties && Expense::whereIn('property_id', $propertyIds)
            ->whereYear('expense_date', $year)->exists();
        $hasComponents = $hasProperties && PropertyComponent::whereIn('property_id', $propertyIds)->exists();
        $fiscalYear = $user->fiscalYears()->where('year', $year)->first();
        $hasFiscalYear = $fiscalYear !== null;
        $hasPdf = $hasFiscalYear && $fiscalYear->pdf_path !== null;

        $steps = [
            [
                'key' => 'property',
                'label' => 'Créer votre bien',
                'description' => 'Renseignez votre bien immobilier, ses surfaces et son acquisition.',
                'icon' => 'heroicon-o-home-modern',
                'done' => $hasProperties,
                'url' => $hasProperties
                    ? '/properties'
                    : '/onboarding-wizard',
            ],
            [
                'key' => 'incomes',
                'label' => 'Ajouter vos recettes ' . $year,
                'description' => 'Importez votre CSV Airbnb ou saisissez manuellement.',
                'icon' => 'heroicon-o-banknotes',
                'done' => $hasIncomes,
                'url' => '/annual-import-wizard',
            ],
            [
                'key' => 'expenses',
                'label' => 'Saisir vos charges ' . $year,
                'description' => 'Taxe foncière, assurance, énergie, entretien…',
                'icon' => 'heroicon-o-receipt-percent',
                'done' => $hasExpenses,
                'url' => '/annual-import-wizard',
            ],
            [
                'key' => 'depreciation',
                'label' => 'Vérifier les amortissements',
                'description' => 'Composants du bien, travaux et mobilier.',
                'icon' => 'heroicon-o-building-library',
                'done' => $hasComponents,
                'url' => '/property-components',
            ],
            [
                'key' => 'fiscal_year',
                'label' => 'Clôturer l\'exercice ' . $year,
                'description' => 'Calculer le résultat fiscal et les amortissements plafonnés.',
                'icon' => 'heroicon-o-calculator',
                'done' => $hasFiscalYear,
                'url' => '/fiscal-year-wizard',
            ],
            [
                'key' => 'pdf',
                'label' => 'Générer la liasse fiscale',
                'description' => 'Formulaires 2031, 2033 et 2042 en PDF.',
                'icon' => 'heroicon-o-document-text',
                'done' => $hasPdf,
                'url' => '/teledeclaration',
            ],
        ];

        $currentFound = false;
        foreach ($steps as &$step) {
            if ($step['done']) {
                $step['status'] = 'completed';
            } elseif (! $currentFound) {
                $step['status'] = 'current';
                $currentFound = true;
            } else {
                $step['status'] = 'pending';
            }
        }
        unset($step);

        return $steps;
    }

    public function getProgress(User $user, int $year): int
    {
        $steps = $this->getChecklist($user, $year);
        $done = count(array_filter($steps, fn ($s) => $s['status'] === 'completed'));

        return (int) round($done / count($steps) * 100);
    }

    public function isComplete(User $user, int $year): bool
    {
        return $this->getProgress($user, $year) === 100;
    }
}
