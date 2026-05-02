<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BadgeDefinition extends Model
{
    protected $fillable = [
        'code',
        'category',
        'name',
        'description',
        'hint',
        'icon',
        'color',
        'is_yearly',
        'sort_order',
        'is_active',
        'unlock_conditions',
    ];

    protected function casts(): array
    {
        return [
            'is_yearly' => 'boolean',
            'is_active' => 'boolean',
            'unlock_conditions' => 'array',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function userBadges(): HasMany
    {
        return $this->hasMany(UserBadge::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(BadgeProgress::class);
    }
}
