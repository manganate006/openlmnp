<?php

namespace App\Models;

use App\Models\Scopes\BelongsToUserScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([BelongsToUserScope::class])]
class FiscalYear extends Model
{
    public const STATUS_DRAFT  = 'draft';
    public const STATUS_CLOSED = 'closed';

    /**
     * Durée de report d'un déficit LMNP : les DIX années suivant celle de sa réalisation
     * (CGI art. 156, I-1° ter ; BOI-BIC-CHAMP-40-20 § 250 et BOI-BIC-DEF-20-20 § 120).
     * Un déficit né en 2015 est imputable de 2016 à 2025 inclus, perdu à compter de 2026.
     *
     * ⚠️ Ne pas confondre avec le 1° bis du même article (BIC non professionnels en général),
     * qui n'ouvre que SIX ans et ne concerne pas la location meublée.
     */
    public const DEFICIT_CARRYFORWARD_YEARS = 10;

    /** Provenance des soldes d'ouverture d'un exercice de reprise. */
    public const OPENING_SOURCE_LIASSE = 'liasse';
    public const OPENING_SOURCE_MANUAL = 'manuel';
    public const OPENING_SOURCE_AI     = 'ia';

    protected $fillable = [
        'user_id',
        'year',
        'status',
        'total_income',
        'total_expenses',
        'total_depreciation',
        'capped_depreciation',
        'deferred_depreciation',
        'previous_deferred',
        'opening_deferred_depreciation',
        'opening_deficits',
        'opening_accumulated_depreciation',
        'opening_source',
        'previous_deficit',
        'deficit_imputed',
        'deficit_carryforward',
        'deficit_detail',
        'fiscal_result',
        'total_tva_collected',
        'total_tva_deductible',
        'tva_balance',
        'form_data',
        'pdf_path',
        'fec_path',
        'transmitted_at',
        'ack_number',
    ];

    protected function casts(): array
    {
        return [
            'form_data'        => 'array',
            'opening_deficits' => 'array',
            'deficit_detail'   => 'array',
            'transmitted_at'   => 'datetime',
        ];
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT  => 'Brouillon',
            self::STATUS_CLOSED => 'Clôturé',
        ];
    }

    public static function openingSourceLabels(): array
    {
        return [
            self::OPENING_SOURCE_LIASSE => 'Liasse fiscale',
            self::OPENING_SOURCE_MANUAL => 'Saisie manuelle',
            self::OPENING_SOURCE_AI     => 'Lecture assistée',
        ];
    }

    /**
     * Soldes d'ouverture saisis : l'exercice est un exercice de REPRISE.
     *
     * `opening_accumulated_depreciation` n'entre pas dans ce test : c'est une donnée de
     * contrôle, elle ne suffit pas à faire d'un exercice une reprise.
     */
    public function hasOpeningBalances(): bool
    {
        return (int) $this->opening_deferred_depreciation > 0
            || $this->openingDeficitsTotal() > 0;
    }

    /** Somme des déficits d'ouverture, tous millésimes confondus (centimes). */
    public function openingDeficitsTotal(): int
    {
        $total = '0';

        foreach ($this->opening_deficits ?? [] as $deficit) {
            $total = bcadd($total, (string) (int) ($deficit['amount'] ?? 0), 0);
        }

        return (int) $total;
    }

    /**
     * Exercice sans aucune donnée calculée.
     *
     * Un tel exercice n'a pas « un report de 0 € » : il n'a PAS DE REPORT DU TOUT. La nuance
     * décide du sort d'un solde d'ouverture — voir `FiscalYearService::carriedForwardDeferred()`.
     */
    public function hasNoComputedData(): bool
    {
        return (int) $this->total_income === 0
            && (int) $this->total_expenses === 0
            && (int) $this->total_depreciation === 0
            && (int) $this->capped_depreciation === 0
            && (int) $this->deferred_depreciation === 0
            && (int) $this->fiscal_result === 0;
    }

    public function getFiscalResultEurosAttribute(): string
    {
        return bcdiv((string) $this->fiscal_result, '100', 2);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accountingEntries(): HasMany
    {
        return $this->hasMany(AccountingEntry::class)->orderBy('entry_date');
    }
}
