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
            'form_data'      => 'array',
            'transmitted_at' => 'datetime',
        ];
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT  => 'Brouillon',
            self::STATUS_CLOSED => 'Clôturé',
        ];
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
