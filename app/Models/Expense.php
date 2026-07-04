<?php

namespace App\Models;

use App\Helpers\TvaHelper;
use App\Models\Concerns\HasDocuments;
use App\Models\Scopes\BelongsToUserThroughPropertyScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([BelongsToUserThroughPropertyScope::class])]
class Expense extends Model
{
    use HasDocuments;

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
        'tva_rate',
        'amount_ht',
        'amount_tva',
        'category',
        'description',
        'is_dedicated',
        'recurring_type',
        'notes',
    ];

    protected static function booted(): void
    {
        static::saving(function (Expense $expense) {
            $result = TvaHelper::fromTtc((int) $expense->amount, (int) $expense->tva_rate);
            $expense->amount_ht = $result['ht'];
            $expense->amount_tva = $result['tva'];
        });
    }

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
            self::CATEGORY_PROPERTY_TAX => '🏛️ Taxe foncière',
            self::CATEGORY_INSURANCE    => '🛡️ Assurance',
            self::CATEGORY_ENERGY       => '⚡ Énergie (élec, gaz, eau)',
            self::CATEGORY_MAINTENANCE  => '🔧 Entretien / réparations',
            self::CATEGORY_SUPPLIES     => '🧹 Fournitures / consommables',
            self::CATEGORY_PLATFORM     => '💳 Commissions plateformes',
            self::CATEGORY_ACCOUNTING   => '📊 Comptabilité / logiciel',
            self::CATEGORY_TELECOM      => '📡 Internet / téléphone',
            self::CATEGORY_TRAVEL       => '🚗 Frais de déplacement',
            self::CATEGORY_CLEANING     => '🧽 Ménage',
            self::CATEGORY_OTHER        => '📦 Divers',
        ];
    }

    public static function categoryEmojis(): array
    {
        return [
            self::CATEGORY_PROPERTY_TAX => '🏛️',
            self::CATEGORY_INSURANCE    => '🛡️',
            self::CATEGORY_ENERGY       => '⚡',
            self::CATEGORY_MAINTENANCE  => '🔧',
            self::CATEGORY_SUPPLIES     => '🧹',
            self::CATEGORY_PLATFORM     => '💳',
            self::CATEGORY_ACCOUNTING   => '📊',
            self::CATEGORY_TELECOM      => '📡',
            self::CATEGORY_TRAVEL       => '🚗',
            self::CATEGORY_CLEANING     => '🧽',
            self::CATEGORY_OTHER        => '📦',
        ];
    }

    public static function categoryShortLabels(): array
    {
        return [
            self::CATEGORY_PROPERTY_TAX => 'TF',
            self::CATEGORY_INSURANCE    => 'Assur.',
            self::CATEGORY_ENERGY       => 'Énergie',
            self::CATEGORY_MAINTENANCE  => 'Entret.',
            self::CATEGORY_SUPPLIES     => 'Fourn.',
            self::CATEGORY_PLATFORM     => 'Commis.',
            self::CATEGORY_ACCOUNTING   => 'Compta',
            self::CATEGORY_TELECOM      => 'Télécom',
            self::CATEGORY_TRAVEL       => 'Déplac.',
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

    public function getAmountHtEurosAttribute(): string
    {
        return bcdiv((string) $this->amount_ht, '100', 2);
    }

    public function getAmountTvaEurosAttribute(): string
    {
        return bcdiv((string) $this->amount_tva, '100', 2);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
