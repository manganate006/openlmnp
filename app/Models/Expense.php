<?php

namespace App\Models;

use App\Helpers\TvaHelper;
use App\Models\Concerns\HasDocuments;
use App\Models\Scopes\BelongsToUserThroughPropertyScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

#[ScopedBy([BelongsToUserThroughPropertyScope::class])]
class Expense extends Model
{
    use HasDocuments;

    /**
     * Part d'usage pour la location, en centièmes de pour cent (10000 = 100 %).
     * NULL vaut 100 % : la charge sert entièrement à la location.
     */
    public const FULL_USE_BP = 10000;

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
        'business_use_bp',
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
            'business_use_bp' => 'integer',
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
     * Montant effectif : part d'usage pour la location (#17), puis quote-part surface si la
     * charge n'est pas dédiée au bien.
     */
    public function getEffectiveAmountAttribute(): string
    {
        $amount = $this->usageAdjusted('amount');

        if ($this->is_dedicated) {
            return (string) $amount;
        }

        $quotaShare = $this->property->quota_share;
        return bcmul((string) $amount, $quotaShare, 0);
    }

    /**
     * Un montant de la charge (`amount`, `amount_ht` ou `amount_tva`, en centimes) réduit à
     * sa part d'usage pour la location. La quote-part surface s'applique ENSUITE, au total
     * des charges partagées, comme avant l'introduction de la part d'usage.
     */
    public function usageAdjusted(string $column = 'amount'): int
    {
        return self::applyUsage((int) $this->{$column}, $this->business_use_bp);
    }

    /**
     * Arrondi au centime le plus proche, en entiers : `(montant × part + 5000) / 10000`.
     * Formule identique à usageAdjustedSql(), pour qu'une somme SQL et une somme ligne à
     * ligne en PHP tombent au même centime (division entière tronquée vers zéro des deux
     * côtés, en SQLite comme en PostgreSQL).
     */
    public static function applyUsage(int $cents, ?int $bp): int
    {
        if ($bp === null) {
            return $cents;
        }

        return intdiv($cents * $bp + intdiv(self::FULL_USE_BP, 2), self::FULL_USE_BP);
    }

    /**
     * Expression SQL d'une colonne réduite à la part d'usage, ligne par ligne, à passer à
     * `->sum()` : `$property->expenses()->where('is_dedicated', false)->sum(Expense::usageAdjustedSql())`.
     */
    public static function usageAdjustedSql(string $column = 'amount'): Expression
    {
        return DB::raw(self::usageAdjustedSqlFragment($column));
    }

    /** Le même calcul en fragment SQL brut, pour un `selectRaw()` groupé. */
    public static function usageAdjustedSqlFragment(string $column = 'amount'): string
    {
        if (! in_array($column, ['amount', 'amount_ht', 'amount_tva'], true)) {
            throw new \InvalidArgumentException("Colonne de montant inconnue : {$column}");
        }

        $full = self::FULL_USE_BP;
        $half = intdiv($full, 2);

        return "CASE WHEN business_use_bp IS NULL THEN {$column} ELSE ({$column} * business_use_bp + {$half}) / {$full} END";
    }

    /** Saisie en pourcentage (« 30 », « 12,5 ») vers centièmes de pour cent ; vide ou 100 = NULL. */
    public static function percentToBp(mixed $percent): ?int
    {
        if ($percent === null || trim((string) $percent) === '') {
            return null;
        }

        $bp = (int) round(((float) str_replace(',', '.', (string) $percent)) * 100);

        return $bp >= self::FULL_USE_BP ? null : max(0, $bp);
    }

    /** Centièmes de pour cent vers pourcentage lisible, sans zéros inutiles (« 30 », « 12,5 »). */
    public static function formatUsagePercent(?int $bp, string $decimalSeparator = ','): ?string
    {
        if ($bp === null) {
            return null;
        }

        return rtrim(rtrim(number_format($bp / 100, 2, $decimalSeparator, ''), '0'), $decimalSeparator);
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
