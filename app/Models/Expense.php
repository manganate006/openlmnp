<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    public const CATEGORY_PROPERTY_TAX = 'property_tax';
    public const CATEGORY_INSURANCE    = 'insurance';
    public const CATEGORY_ENERGY       = 'energy';
    public const CATEGORY_MAINTENANCE  = 'maintenance';
    public const CATEGORY_SUPPLIES     = 'supplies';
    public const CATEGORY_PLATFORM     = 'platform_fees';
    public const CATEGORY_ACCOUNTING   = 'accounting';
    public const CATEGORY_TELECOM      = 'telecom';
    public const CATEGORY_TRAVEL       = 'travel';
    public const CATEGORY_CLEANING     = 'cleaning';
    public const CATEGORY_OTHER        = 'other';

    protected $fillable = [
        'property_id',
        'expense_date',
        'amount',
        'category',
        'description',
        'is_dedicated',
        'receipt_path',
        'recurring_type',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'is_dedicated' => 'boolean',
        ];
    }

    public static function categoryLabels(): array
    {
        return [
            self::CATEGORY_PROPERTY_TAX => 'Taxe foncière',
            self::CATEGORY_INSURANCE    => 'Assurance',
            self::CATEGORY_ENERGY       => 'Énergie (élec, gaz, eau)',
            self::CATEGORY_MAINTENANCE  => 'Entretien / réparations',
            self::CATEGORY_SUPPLIES     => 'Fournitures / consommables',
            self::CATEGORY_PLATFORM     => 'Commissions plateformes',
            self::CATEGORY_ACCOUNTING   => 'Comptabilité / logiciel',
            self::CATEGORY_TELECOM      => 'Internet / téléphone',
            self::CATEGORY_TRAVEL       => 'Frais de déplacement',
            self::CATEGORY_CLEANING     => 'Ménage',
            self::CATEGORY_OTHER        => 'Divers',
        ];
    }

    public static function recurringLabels(): array
    {
        return [
            'once'      => 'Ponctuel',
            'monthly'   => 'Mensuel',
            'quarterly' => 'Trimestriel',
            'yearly'    => 'Annuel',
        ];
    }

    /**
     * Montant effectif après application de la quote-part.
     */
    public function getEffectiveAmountAttribute(): string
    {
        if ($this->is_dedicated) {
            return (string) $this->amount;
        }

        $quotaShare = $this->property->quota_share;
        return bcmul((string) $this->amount, $quotaShare, 0);
    }

    public function getAmountEurosAttribute(): string
    {
        return bcdiv((string) $this->amount, '100', 2);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
