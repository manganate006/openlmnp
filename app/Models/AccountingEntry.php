<?php

namespace App\Models;

use App\Models\Scopes\BelongsToUserThroughFiscalYearScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([BelongsToUserThroughFiscalYearScope::class])]
class AccountingEntry extends Model
{
    protected $fillable = [
        'fiscal_year_id',
        'property_id',
        'entry_date',
        'account_code',
        'label',
        'debit',
        'credit',
        'piece_ref',
        'journal',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
        ];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
