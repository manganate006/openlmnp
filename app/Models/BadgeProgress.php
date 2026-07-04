<?php

namespace App\Models;

use App\Models\Scopes\BelongsToUserScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([BelongsToUserScope::class])]
class BadgeProgress extends Model
{
    protected $table = 'badge_progress';

    protected $fillable = [
        'user_id',
        'badge_definition_id',
        'fiscal_year',
        'current_value',
        'target_value',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(BadgeDefinition::class, 'badge_definition_id');
    }

    public function getPercentageAttribute(): int
    {
        if ($this->target_value === 0) {
            return 100;
        }

        return (int) min(100, round($this->current_value / $this->target_value * 100));
    }
}
