<?php

namespace App\Services;

use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\PropertyWork;

/**
 * Service de calcul des amortissements LMNP.
 *
 * Tous les montants sont en centimes (entiers).
 * Tous les calculs utilisent bcmath pour la précision.
 */
class DepreciationService
{
    /**
     * Composants par défaut avec pourcentage et durée.
     */
    public const DEFAULT_COMPONENTS = [
        ['name' => 'Gros œuvre',                'percentage' => 50, 'duration_years' => 50, 'sort_order' => 1],
        ['name' => 'Toiture',                   'percentage' => 10, 'duration_years' => 25, 'sort_order' => 2],
        ['name' => 'Installations électriques', 'percentage' => 10, 'duration_years' => 25, 'sort_order' => 3],
        ['name' => 'Étanchéité',                'percentage' =>  5, 'duration_years' => 15, 'sort_order' => 4],
        ['name' => 'Agencements intérieurs',    'percentage' => 15, 'duration_years' => 15, 'sort_order' => 5],
        ['name' => 'Plomberie / sanitaire',     'percentage' => 10, 'duration_years' => 15, 'sort_order' => 6],
    ];

    /**
     * Composants optionnels (maison individuelle).
     * Sources : BOFiP BOI-ANNX-000115, pratique experts-comptables LMNP.
     */
    public const OPTIONAL_COMPONENTS = [
        ['name' => 'Piscine',                      'percentage' =>  7, 'duration_years' => 15, 'sort_order' => 7],
        ['name' => 'Climatisation / chauffage',     'percentage' =>  5, 'duration_years' => 20, 'sort_order' => 8],
        ['name' => 'Cuisine équipée',               'percentage' =>  5, 'duration_years' => 10, 'sort_order' => 9],
        ['name' => 'VRD (voirie, réseaux)',          'percentage' =>  3, 'duration_years' => 15, 'sort_order' => 10],
        ['name' => 'Aménagements extérieurs',        'percentage' =>  5, 'duration_years' => 15, 'sort_order' => 11],
    ];

    /**
     * Catalogue complet : standards + optionnels.
     */
    public const FULL_CATALOG = [
        ['name' => 'Gros œuvre',                'percentage' => 50, 'duration_years' => 50, 'sort_order' => 1,  'optional' => false],
        ['name' => 'Toiture',                   'percentage' => 10, 'duration_years' => 25, 'sort_order' => 2,  'optional' => false],
        ['name' => 'Installations électriques', 'percentage' => 10, 'duration_years' => 25, 'sort_order' => 3,  'optional' => false],
        ['name' => 'Étanchéité',                'percentage' =>  5, 'duration_years' => 15, 'sort_order' => 4,  'optional' => false],
        ['name' => 'Agencements intérieurs',    'percentage' => 15, 'duration_years' => 15, 'sort_order' => 5,  'optional' => false],
        ['name' => 'Plomberie / sanitaire',     'percentage' => 10, 'duration_years' => 15, 'sort_order' => 6,  'optional' => false],
        ['name' => 'Piscine',                   'percentage' =>  7, 'duration_years' => 15, 'sort_order' => 7,  'optional' => true],
        ['name' => 'Climatisation / chauffage',  'percentage' =>  5, 'duration_years' => 20, 'sort_order' => 8, 'optional' => true],
        ['name' => 'Cuisine équipée',            'percentage' =>  5, 'duration_years' => 10, 'sort_order' => 9, 'optional' => true],
        ['name' => 'VRD (voirie, réseaux)',       'percentage' =>  3, 'duration_years' => 15, 'sort_order' => 10, 'optional' => true],
        ['name' => 'Aménagements extérieurs',     'percentage' =>  5, 'duration_years' => 15, 'sort_order' => 11, 'optional' => true],
    ];

    /**
     * Génère les composants d'amortissement par défaut pour un bien.
     */
    public function generateDefaultComponents(Property $property): void
    {
        $depreciableBase = $property->depreciable_base;

        foreach (self::DEFAULT_COMPONENTS as $comp) {
            $baseAmount = bcmul($depreciableBase, bcdiv((string) $comp['percentage'], '100', 10), 0);
            $annualDepreciation = bcdiv($baseAmount, (string) $comp['duration_years'], 0);

            PropertyComponent::create([
                'property_id'         => $property->id,
                'name'                => $comp['name'],
                'percentage'          => $comp['percentage'],
                'duration_years'      => $comp['duration_years'],
                'base_amount'         => (int) $baseAmount,
                'annual_depreciation' => (int) $annualDepreciation,
                'sort_order'          => $comp['sort_order'],
            ]);
        }
    }

    /**
     * Calcule l'amortissement annuel total d'un bien pour une année donnée.
     *
     * Inclut : composants immeuble + travaux + mobilier
     * Applique le prorata temporis pour la 1ère année.
     *
     * @return array{
     *     building: string,
     *     works: string,
     *     furniture: string,
     *     total: string,
     *     details: array
     * }
     */
    public function calculateAnnualDepreciation(Property $property, int $year): array
    {
        $rentalStart = $property->rental_start_date;
        $yearStart = "{$year}-01-01";
        $yearEnd = "{$year}-12-31";

        // Amortissement immeuble (composants)
        $buildingTotal = '0';
        $details = [];

        foreach ($property->components as $component) {
            $annual = $this->calculateComponentForYear($component, $property, $year);
            $buildingTotal = bcadd($buildingTotal, $annual, 0);
            $details[] = [
                'type'   => 'building',
                'name'   => $component->name,
                'amount' => $annual,
            ];
        }

        // Amortissement travaux
        $worksTotal = '0';
        foreach ($property->works as $work) {
            $annual = $this->calculateWorkForYear($work, $property, $year);
            $worksTotal = bcadd($worksTotal, $annual, 0);
            $details[] = [
                'type'   => 'work',
                'name'   => $work->description,
                'amount' => $annual,
            ];
        }

        // Amortissement mobilier
        $furnitureTotal = '0';
        foreach ($property->furniture as $item) {
            $annual = $this->calculateFurnitureForYear($item, $property, $year);
            $furnitureTotal = bcadd($furnitureTotal, $annual, 0);
            $details[] = [
                'type'   => 'furniture',
                'name'   => $item->description,
                'amount' => $annual,
            ];
        }

        $total = bcadd(bcadd($buildingTotal, $worksTotal, 0), $furnitureTotal, 0);

        return [
            'building'  => $buildingTotal,
            'works'     => $worksTotal,
            'furniture' => $furnitureTotal,
            'total'     => $total,
            'details'   => $details,
        ];
    }

    /**
     * Calcule l'amortissement d'un composant immeuble pour une année.
     */
    private function calculateComponentForYear(PropertyComponent $component, Property $property, int $year): string
    {
        $startDate = $property->rental_start_date;
        $startYear = (int) $startDate->format('Y');
        $endYear = $startYear + $component->duration_years - 1;

        // Pas encore commencé ou déjà terminé
        if ($year < $startYear || $year > $endYear) {
            return '0';
        }

        $annual = (string) $component->annual_depreciation;

        // Prorata temporis la 1ère année
        if ($year === $startYear) {
            $daysInYear = $startDate->isLeapYear() ? 366 : 365;
            $remainingDays = $startDate->diffInDays($startDate->copy()->endOfYear()) + 1;
            $annual = bcmul($annual, bcdiv((string) $remainingDays, (string) $daysInYear, 10), 0);
        }

        return $annual;
    }

    /**
     * Calcule l'amortissement de travaux pour une année.
     */
    private function calculateWorkForYear(PropertyWork $work, Property $property, int $year): string
    {
        $workDate = $work->work_date;
        $startYear = (int) $workDate->format('Y');
        $endYear = $startYear + $work->duration_years - 1;

        if ($year < $startYear || $year > $endYear) {
            return '0';
        }

        // Montant annuel, avec quote-part si non dédié
        $annual = (string) $work->annual_depreciation;
        if (! $work->is_dedicated) {
            $annual = bcmul($annual, $property->quota_share, 0);
        }

        // Prorata temporis la 1ère année
        if ($year === $startYear) {
            $daysInYear = $workDate->isLeapYear() ? 366 : 365;
            $remainingDays = $workDate->diffInDays($workDate->copy()->endOfYear()) + 1;
            $annual = bcmul($annual, bcdiv((string) $remainingDays, (string) $daysInYear, 10), 0);
        }

        return $annual;
    }

    /**
     * Calcule l'amortissement d'un meuble pour une année.
     */
    private function calculateFurnitureForYear(Furniture $item, Property $property, int $year): string
    {
        $purchaseDate = $item->purchase_date;
        $startYear = (int) $purchaseDate->format('Y');
        $endYear = $startYear + $item->duration_years - 1;

        if ($year < $startYear || $year > $endYear) {
            return '0';
        }

        $annual = (string) $item->annual_depreciation;
        if (! $item->is_dedicated) {
            $annual = bcmul($annual, $property->quota_share, 0);
        }

        // Prorata temporis
        if ($year === $startYear) {
            $daysInYear = $purchaseDate->isLeapYear() ? 366 : 365;
            $remainingDays = $purchaseDate->diffInDays($purchaseDate->copy()->endOfYear()) + 1;
            $annual = bcmul($annual, bcdiv((string) $remainingDays, (string) $daysInYear, 10), 0);
        }

        return $annual;
    }
}
