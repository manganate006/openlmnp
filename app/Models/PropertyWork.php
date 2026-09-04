<?php

namespace App\Models;

use App\Helpers\TvaHelper;
use App\Models\Concerns\HasDocuments;
use App\Models\Scopes\BelongsToUserThroughPropertyScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Travaux immobiliers amortissables sur un bien LMNP.
 *
 * Les travaux sont amortis linéairement sur duration_years.
 * Si is_dedicated = false, la quote-part du bien est appliquée
 * au montant avant calcul de l'amortissement annuel.
 *
 * @property int         $id
 * @property int         $property_id
 * @property string      $description
 * @property int         $amount              centimes (montant total TTC)
 * @property \Carbon\Carbon $work_date
 * @property int         $duration_years      durée d'amortissement (défaut : 10)
 * @property bool        $is_dedicated        100 % dédié à la location ou au prorata
 * @property int         $annual_depreciation centimes
 * @property string      $depreciation_source computed|manual
 * @property int         $opening_accumulated_depreciation centimes, cumuls affichés UNIQUEMENT
 */
#[ScopedBy([BelongsToUserThroughPropertyScope::class])]
class PropertyWork extends Model
{
    use HasDocuments;

    /**
     * Symétrique du `base_source` des composants : `manual` protège une dotation recopiée
     * d'une liasse. Sans elle, `montant ÷ durée` reprenait toujours la main et l'arrondi
     * du cabinet précédent était impossible à conserver.
     */
    public const DEPRECIATION_SOURCE_COMPUTED = 'computed';
    public const DEPRECIATION_SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'property_id',
        'description',
        'amount',
        'tva_rate',
        'amount_ht',
        'amount_tva',
        'work_date',
        'duration_years',
        'is_dedicated',
        'annual_depreciation',
        'depreciation_source',
        'opening_accumulated_depreciation',
    ];

    protected static function booted(): void
    {
        static::saving(function (PropertyWork $work) {
            // Calcul TVA
            $result = TvaHelper::fromTtc((int) $work->amount, (int) $work->tva_rate);
            $work->amount_ht = $result['ht'];
            $work->amount_tva = $result['tva'];

            // Une dotation recopiée d'une liasse ne se recalcule pas : c'est tout l'objet
            // du mode manuel. Elle reste dérivée tant qu'aucun montant n'a été saisi.
            if ($work->hasManualDepreciation()) {
                return;
            }

            if ($work->amount > 0 && $work->duration_years > 0) {
                $work->computeAnnualDepreciation();
            }
        });
    }

    /** Vrai si la dotation a été fixée à la main et ne doit plus être recalculée. */
    public function hasManualDepreciation(): bool
    {
        return $this->depreciation_source === self::DEPRECIATION_SOURCE_MANUAL
            && (int) $this->annual_depreciation > 0;
    }

    protected function casts(): array
    {
        return [
            'work_date'    => 'date',
            'is_dedicated' => 'boolean',
        ];
    }

    // -------------------------------------------------------------------------
    // Accesseurs euros
    // -------------------------------------------------------------------------

    public function getAmountEurosAttribute(): string
    {
        return bcdiv((string) $this->amount, '100', 2);
    }

    public function getAmountHtEurosAttribute(): string
    {
        return bcdiv((string) $this->amount_ht, '100', 2);
    }

    public function getAmountTvaEurosAttribute(): string
    {
        return bcdiv((string) $this->amount_tva, '100', 2);
    }

    public function getAnnualDepreciationEurosAttribute(): string
    {
        return bcdiv((string) $this->annual_depreciation, '100', 2);
    }

    // -------------------------------------------------------------------------
    // Méthodes de calcul
    // -------------------------------------------------------------------------

    /**
     * Calcule annual_depreciation selon la quote-part du bien.
     *
     * Si is_dedicated = true  → base = amount
     * Si is_dedicated = false → base = amount * quota_share
     */
    public function computeAnnualDepreciation(): void
    {
        $this->annual_depreciation = $this->expectedAnnualDepreciation();
    }

    /**
     * Dotation que la règle actuelle donnerait, SANS rien écrire.
     *
     * C'est ce qui permet à `openlmnp:recompute-depreciation` de décrire un écart sans
     * le corriger : la formule reste écrite une seule fois.
     */
    public function expectedAnnualDepreciation(): int
    {
        $property = $this->property;

        // Si TVA-liable, amortir sur le HT (TVA récupérée)
        $base = ($property !== null && $property->isTvaLiable())
            ? (string) $this->amount_ht
            : (string) $this->amount;

        if (! $this->is_dedicated && $property !== null) {
            $base = bcmul($base, $property->quota_share, 0);
        }

        return $this->duration_years > 0
            ? (int) bcdiv($base, (string) $this->duration_years, 0)
            : 0;
    }

    /**
     * Retourne l'amortissement pour une année fiscale donnée.
     * Prorata temporis sur la première année.
     */
    public function getDepreciationForYear(int $year): int
    {
        $startYear = (int) $this->work_date->format('Y');

        if ($year < $startYear) {
            return 0;
        }

        $endYear = $startYear + $this->duration_years - 1;

        if ($year > $endYear) {
            return 0;
        }

        // Première année : prorata temporis
        if ($year === $startYear) {
            $monthsInYear = 12 - (int) $this->work_date->format('n') + 1;

            return (int) bcmul(
                (string) $this->annual_depreciation,
                bcdiv((string) $monthsInYear, '12', 10),
                0
            );
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
