<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Composant d'amortissement par ventilation d'un bien immobilier.
 *
 * Conforme à la doctrine fiscale LMNP régime réel :
 * le bien est décomposé en plusieurs composants, chacun amorti
 * sur une durée propre.
 *
 * Composants standards recommandés :
 *   - Gros œuvre       : 50 %, 50 ans
 *   - Toiture          : 10 %, 25 ans
 *   - Électricité      : 10 %, 25 ans
 *   - Étanchéité       : 5 %,  15 ans
 *   - Agencements      : 15 %, 15 ans
 *   - Plomberie        : 10 %, 15 ans
 *
 * @property int    $id
 * @property int    $property_id
 * @property string $name
 * @property int    $percentage          % de la base amortissable
 * @property int    $duration_years      durée d'amortissement
 * @property int    $base_amount         centimes
 * @property int    $annual_depreciation centimes
 * @property int    $sort_order
 */
class PropertyComponent extends Model
{
    // Composants standards avec leurs paramètres par défaut
    public const STANDARD_COMPONENTS = [
        [
            'name'           => 'Gros œuvre',
            'percentage'     => 50,
            'duration_years' => 50,
            'sort_order'     => 1,
        ],
        [
            'name'           => 'Toiture',
            'percentage'     => 10,
            'duration_years' => 25,
            'sort_order'     => 2,
        ],
        [
            'name'           => 'Électricité',
            'percentage'     => 10,
            'duration_years' => 25,
            'sort_order'     => 3,
        ],
        [
            'name'           => 'Étanchéité',
            'percentage'     => 5,
            'duration_years' => 15,
            'sort_order'     => 4,
        ],
        [
            'name'           => 'Agencements intérieurs',
            'percentage'     => 15,
            'duration_years' => 15,
            'sort_order'     => 5,
        ],
        [
            'name'           => 'Plomberie',
            'percentage'     => 10,
            'duration_years' => 15,
            'sort_order'     => 6,
        ],
    ];

    protected $fillable = [
        'property_id',
        'name',
        'percentage',
        'duration_years',
        'base_amount',
        'annual_depreciation',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            // Pas de casts spéciaux ; les entiers sont nativement entiers
        ];
    }

    // -------------------------------------------------------------------------
    // Accesseurs euros
    // -------------------------------------------------------------------------

    public function getBaseAmountEurosAttribute(): string
    {
        return bcdiv((string) $this->base_amount, '100', 2);
    }

    public function getAnnualDepreciationEurosAttribute(): string
    {
        return bcdiv((string) $this->annual_depreciation, '100', 2);
    }

    // -------------------------------------------------------------------------
    // Méthodes de calcul
    // -------------------------------------------------------------------------

    /**
     * Calcule et met à jour base_amount et annual_depreciation
     * à partir de la base amortissable du bien parent.
     *
     * @param  string $depreciableBase  Base amortissable en centimes (bcmath string)
     */
    public function computeFromBase(string $depreciableBase): void
    {
        $base = bcmul(
            $depreciableBase,
            bcdiv((string) $this->percentage, '100', 10),
            0
        );

        $this->base_amount = (int) $base;

        $this->annual_depreciation = $this->duration_years > 0
            ? (int) bcdiv($base, (string) $this->duration_years, 0)
            : 0;
    }

    /**
     * Retourne l'amortissement pour une année donnée (pro-rata si première année).
     *
     * @param  int    $year       Année fiscale
     * @param  \Carbon\Carbon $startDate  Date de début de location
     */
    public function getDepreciationForYear(int $year, \Carbon\Carbon $startDate): int
    {
        $startYear = (int) $startDate->format('Y');

        if ($year < $startYear) {
            return 0;
        }

        // Première année : prorata temporis (mois restants / 12)
        if ($year === $startYear) {
            $monthsInYear = 12 - (int) $startDate->format('n') + 1;

            return (int) bcmul(
                (string) $this->annual_depreciation,
                bcdiv((string) $monthsInYear, '12', 10),
                0
            );
        }

        // Années complètes dans la durée d'amortissement
        $endYear = $startYear + $this->duration_years - 1;

        if ($year > $endYear) {
            return 0;
        }

        return $this->annual_depreciation;
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
