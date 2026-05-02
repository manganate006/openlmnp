<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBadge extends Model
{
    protected $fillable = [
        'user_id',
        'badge_definition_id',
        'unlocked_at',
        'fiscal_year',
        'context',
        'is_notified',
    ];

    protected function casts(): array
    {
        return [
            'unlocked_at' => 'datetime',
            'context' => 'array',
            'is_notified' => 'boolean',
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
}
