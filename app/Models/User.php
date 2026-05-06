<?php

namespace App\Models;

use App\Enums\NavMode;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'siren', 'is_admin', 'nav_mode', 'timezone', 'onboarding_dismissed_at', 'mcp_enabled'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function isSimpleMode(): bool
    {
        return $this->nav_mode === NavMode::Simple;
    }

    public function isAdvancedMode(): bool
    {
        return $this->nav_mode === NavMode::Advanced;
    }

    public function isGuidedMode(): bool
    {
        return $this->nav_mode === NavMode::Guided;
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    public function fiscalYears(): HasMany
    {
        return $this->hasMany(FiscalYear::class);
    }

    public function userBadges(): HasMany
    {
        return $this->hasMany(UserBadge::class);
    }

    public function badgeProgress(): HasMany
    {
        return $this->hasMany(BadgeProgress::class);
    }

    public function mcpAuditLogs(): HasMany
    {
        return $this->hasMany(McpAuditLog::class);
    }

    public function hasBadge(string $code, ?int $fiscalYear = null): bool
    {
        return $this->userBadges()
            ->whereHas('definition', fn ($q) => $q->where('code', $code))
            ->when($fiscalYear !== null, fn ($q) => $q->where('fiscal_year', $fiscalYear))
            ->when($fiscalYear === null, fn ($q) => $q->whereNull('fiscal_year'))
            ->exists();
    }

    public function awardBadge(BadgeDefinition $badge, ?int $fiscalYear = null, array $context = []): ?UserBadge
    {
        $fy = $badge->is_yearly ? $fiscalYear : null;

        if ($this->hasBadge($badge->code, $fy)) {
            return null;
        }

        return $this->userBadges()->create([
            'badge_definition_id' => $badge->id,
            'unlocked_at' => now(),
            'fiscal_year' => $fy,
            'context' => $context,
        ]);
    }

    public function badgeCount(): int
    {
        return $this->userBadges()->count();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'mcp_enabled' => 'boolean',
            'nav_mode' => NavMode::class,
            'onboarding_dismissed_at' => 'datetime',
        ];
    }
}
