<?php

namespace App\Models;

use App\Support\DocumentStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Document extends Model
{
    protected $fillable = [
        'label',
        'amount',
        'document_date',
        'file_path',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
        ];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getAmountEurosAttribute(): ?string
    {
        if ($this->amount === null) {
            return null;
        }

        return bcdiv((string) $this->amount, '100', 2);
    }

    public function getFileUrlAttribute(): ?string
    {
        return DocumentStorage::temporaryUrl($this->file_path);
    }
}
