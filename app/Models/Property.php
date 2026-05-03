<?php

namespace App\Models;

use App\Models\Scopes\BelongsToUserScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle représentant un bien immobilier en LMNP.
 *
 * Tous les montants sont stockés en centimes (entiers).
 * Les accesseurs *_euros convertissent en euros pour l'affichage.
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $name
 * @property string      $address
 * @property string      $city
 * @property string      $postal_code
 * @property string      $type
 * @property int         $total_area             m² total du logement
 * @property int         $rented_area            m² loués (quote-part)
 * @property \Carbon\Carbon $acquisition_date
 * @property int         $acquisition_price      centimes
 * @property int         $notary_fees            centimes
 * @property int|null    $market_value           centimes
 * @property \Carbon\Carbon|null $market_value_date
 * @property int         $land_percentage        % terrain non amortissable
 * @property \Carbon\Carbon $rental_start_date
 * @property string      $rental_type
 * @property bool        $is_primary_residence
 * @property string|null $notes
 */
#[ScopedBy([BelongsToUserScope::class])]
class Property extends Model
{
    // Types de biens
    public const TYPE_APARTMENT = 'apartment';
    public const TYPE_HOUSE     = 'house';
    public const TYPE_ROOM      = 'room';
    public const TYPE_STUDIO    = 'studio';
    public const TYPE_OTHER     = 'other';

    // Types de location
    public const RENTAL_SEASONAL   = 'seasonal';
    public const RENTAL_LONG_TERM  = 'long_term';
    public const RENTAL_MIXED      = 'mixed';

    // Régimes TVA
    public const TVA_EXEMPT  = 'exempt';
    public const TVA_LIABLE  = 'liable';

    protected $fillable = [
        'user_id',
        'name',
        'address',
        'city',
        'postal_code',
        'type',
        'total_area',
        'rented_area',
        'acquisition_date',
        'acquisition_price',
        'notary_fees',
        'market_value',
        'market_value_date',
        'land_percentage',
        'rental_start_date',
        'airbnb_commission_rate',
        'rental_type',
        'tva_regime',
        'is_primary_residence',
        'notes',
        'photo_path',
        'listing_urls',
    ];

    protected function casts(): array
    {
        return [
            'acquisition_date'    => 'date',
            'market_value_date'   => 'date',
            'rental_start_date'   => 'date',
            'is_primary_residence' => 'boolean',
            'listing_urls'        => 'array',
        ];
    }

    // -------------------------------------------------------------------------
    // Labels UI (français)
    // -------------------------------------------------------------------------

    public static function typeLabels(): array
    {
        return [
            self::TYPE_APARTMENT => 'Appartement',
            self::TYPE_HOUSE     => 'Maison',
            self::TYPE_ROOM      => 'Chambre',
            self::TYPE_STUDIO    => 'Studio',
            self::TYPE_OTHER     => 'Autre',
        ];
    }

    public static function rentalTypeLabels(): array
    {
        return [
            self::RENTAL_SEASONAL  => 'Saisonnier (Airbnb…)',
            self::RENTAL_LONG_TERM => 'Location longue durée',
            self::RENTAL_MIXED     => 'Mixte',
        ];
    }

    public function getTypeLabel(): string
    {
        return static::typeLabels()[$this->type] ?? $this->type;
    }

    public function getRentalTypeLabel(): string
    {
        return static::rentalTypeLabels()[$this->rental_type] ?? $this->rental_type;
    }

    public static function tvaRegimeLabels(): array
    {
        return [
            self::TVA_EXEMPT => 'Franchise en base (non assujetti)',
            self::TVA_LIABLE => 'Assujetti TVA (para-hôtelier)',
        ];
    }

    public function isTvaLiable(): bool
    {
        return $this->tva_regime === self::TVA_LIABLE;
    }

    // -------------------------------------------------------------------------
    // Accesseurs montants en euros
    // -------------------------------------------------------------------------

    public function getAcquisitionPriceEurosAttribute(): string
    {
        return bcdiv((string) $this->acquisition_price, '100', 2);
    }

    public function getNotaryFeesEurosAttribute(): string
    {
        return bcdiv((string) $this->notary_fees, '100', 2);
    }

    public function getMarketValueEurosAttribute(): ?string
    {
        if ($this->market_value === null) {
            return null;
        }

        return bcdiv((string) $this->market_value, '100', 2);
    }

    // -------------------------------------------------------------------------
    // Accesseurs calculés (financiers)
    // -------------------------------------------------------------------------

    /**
     * Retourne la quote-part de superficie louée (rented_area / total_area).
     * Exemple : 35m² loués / 126m² total = 0.2778
     */
    public function getQuotaShareAttribute(): string
    {
        if ($this->total_area <= 0) {
            return '0';
        }

        return bcdiv((string) $this->rented_area, (string) $this->total_area, 10);
    }

    /**
     * Retourne la base amortissable en centimes.
     *
     * Formule :
     *   base = (market_value ?: acquisition_price) * (1 - land_percentage/100) * quota_share
     *
     * Tous les calculs utilisent bcmath.
     */
    public function getDepreciableBaseAttribute(): string
    {
        // Valeur de référence : valeur vénale si renseignée, sinon prix d'acquisition
        $referenceValue = $this->market_value ?? $this->acquisition_price;

        // Fraction non-terrain : (100 - land_percentage) / 100
        $buildingFraction = bcdiv(
            bcsub('100', (string) $this->land_percentage, 10),
            '100',
            10
        );

        // Base = valeur * fraction_bâti * quote-part
        return bcmul(
            bcmul((string) $referenceValue, $buildingFraction, 10),
            $this->quota_share,
            0  // résultat en centimes entiers
        );
    }

    /**
     * Base amortissable en euros (pour l'affichage).
     */
    public function getDepreciableBaseEurosAttribute(): string
    {
        return bcdiv($this->depreciable_base, '100', 2);
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(PropertyComponent::class)->orderBy('sort_order');
    }

    public function works(): HasMany
    {
        return $this->hasMany(PropertyWork::class)->orderBy('work_date');
    }

    public function furniture(): HasMany
    {
        return $this->hasMany(Furniture::class)->orderBy('purchase_date');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class)->orderBy('start_date');
    }

    public function incomes(): HasMany
    {
        return $this->hasMany(Income::class)->orderBy('income_date');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class)->orderBy('expense_date');
    }

    public function accountingEntries(): HasMany
    {
        return $this->hasMany(AccountingEntry::class)->orderBy('entry_date');
    }

    // -------------------------------------------------------------------------
    // Query scopes
    // -------------------------------------------------------------------------

    /**
     * Filtre sur les biens de location saisonnière.
     */
    public function scopeSeasonal(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where('rental_type', self::RENTAL_SEASONAL);
    }

    /**
     * Filtre sur les biens actifs (mise en location démarrée).
     */
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where('rental_start_date', '<=', now()->toDateString());
    }
}
