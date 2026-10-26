<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;

/**
 * Invitation à créer un compte sur cette instance.
 *
 * La ligne SURVIT à son acceptation (`accepted_at` + `user_id` renseignés, jamais de
 * suppression automatique) : c'est la seule trace de la façon dont un compte est entré.
 */
class Invitation extends Model
{
    use HasFactory;

    // ⚠️ `Notifiable` sur l'invitation et pas sur l'utilisateur : au moment où le
    // courriel part, le compte N'EXISTE PAS ENCORE. C'est aussi ce qui rend les
    // assertions lisibles (`assertSentTo($invitation, ...)`).
    use Notifiable;

    protected $fillable = [
        'email',
        'name',
        'token_hash',
        'expires_at',
        'accepted_at',
        'revoked_at',
        'sent_count',
        'last_sent_at',
        'invited_by_user_id',
        'user_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    public function routeNotificationForMail(): string
    {
        return $this->email;
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isRevoked() && ! $this->isExpired();
    }

    public function status(): InvitationStatus
    {
        return match (true) {
            $this->isAccepted() => InvitationStatus::Accepted,
            $this->isRevoked() => InvitationStatus::Revoked,
            $this->isExpired() => InvitationStatus::Expired,
            default => InvitationStatus::Pending,
        };
    }

    /** Invitations encore utilisables : ni acceptées, ni révoquées, ni expirées. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public function scopeForEmail(Builder $query, string $email): Builder
    {
        return $query->where('email', $email);
    }
}
