<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mobilier et équipement amortissable d'un bien LMNP.
 *
 * Durée d'amortissement standard : 5 à 10 ans selon la nature du bien.
 * Le mobilier d'occasion (is_second_hand = true) peut bénéficier
 * d'une durée réduite (généralement 3 ans).
 *
 * @property int         $id
 * @property int         $property_id
 * @property string      $description
 * @property int         $amount              centimes
 * @property \Carbon\Carbon $purchase_date
 * @property int         $duration_years      durée d'amortissement (défaut : 5)
 * @property bool        $is_dedicated        100 % dédié ou prorata
 * @property bool        $is_second_hand      mobilier d'occasion
 * @property int         $annual_depreciation centimes
 */
class Furniture extends Model
{
    // Durées d'amortissement recommandées
    public const DURATION_NEW       = 5;   // mobilier neuf standard
    public const DURATION_SECOND_HAND = 3; // mobilier d'occasion
    public const DURATION_EQUIPMENT = 7;   // gros équipements (cuisine équipée…)
    public const DURATION_LINEN     = 3;   // linge de maison

    protected $fillable = [
        'property_id',
        'description',
        'amount',
        'purchase_date',
        'duration_years',
        'is_dedicated',
        'is_second_hand',
        'annual_depreciation',
        'invoice_path',
    ];

    protected static function booted(): void
    {
        static::saving(function (Furniture $furniture) {
            if ($furniture->amount > 0 && $furniture->duration_years > 0) {
                $furniture->computeAnnualDepreciation();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'purchase_date'  => 'date',
            'is_dedicated'   => 'boolean',
            'is_second_hand' => 'boolean',
        ];
    }

    // -------------------------------------------------------------------------
    // Accesseurs euros
    // -------------------------------------------------------------------------

    public function getAmountEurosAttribute(): string
    {
        return bcdiv((string) $this->amount, '100', 2);
    }

    public function getAnnualDepreciationEurosAttribute(): string
    {
        return bcdiv((string) $this->annual_depreciation, '100', 2);
    }

    // -------------------------------------------------------------------------
    // Méthodes de calcul
    // -------------------------------------------------------------------------

    /**
     * Calcule annual_depreciation selon la quote-part du bien si non dédié.
     */
    public function computeAnnualDepreciation(): void
    {
        $property = $this->property;
        $base = (string) $this->amount;

        if (! $this->is_dedicated && $property !== null) {
            $base = bcmul($base, $property->quota_share, 0);
        }

        $this->annual_depreciation = $this->duration_years > 0
            ? (int) bcdiv($base, (string) $this->duration_years, 0)
            : 0;
    }

    /**
     * Retourne l'amortissement pour une année fiscale donnée.
     * Prorata temporis sur la première année.
     */
    public function getDepreciationForYear(int $year): int
    {
        $startYear = (int) $this->purchase_date->format('Y');

        if ($year < $startYear) {
            return 0;
        }

        $endYear = $startYear + $this->duration_years - 1;

        if ($year > $endYear) {
            return 0;
        }

        // Première année : prorata temporis
        if ($year === $startYear) {
            $monthsInYear = 12 - (int) $this->purchase_date->format('n') + 1;

            return (int) bcmul(
                (string) $this->annual_depreciation,
                bcdiv((string) $monthsInYear, '12', 10),
                0
            );
        }

        return $this->annual_depreciation;
    }

    // -------------------------------------------------------------------------
    // Query scopes
    // -------------------------------------------------------------------------

    public function scopeNewOnly(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where('is_second_hand', false);
    }

    public function scopeSecondHand(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where('is_second_hand', true);
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
