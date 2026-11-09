<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;

/**
 * Invitation du second indivisaire à un dossier lié déjà créé (lot 3).
 *
 * ⚠️ `Notifiable` sur l'invitation et pas sur l'utilisateur : au moment où le
 * courriel part, l'invité n'a pas forcément de compte encore — même patron que
 * App\Models\Invitation. La ligne SURVIT à son acceptation (`accepted_at`
 * renseigné, jamais de suppression) : c'est la trace de comment le membre est
 * entré.
 */
class LinkedDossierInvitation extends Model
{
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'linked_dossier_id', 'email', 'token_hash', 'invited_by',
        'expires_at', 'accepted_at', 'revoked_at', 'declined_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    public function routeNotificationForMail(): string
    {
        return $this->email;
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(LinkedDossier::class, 'linked_dossier_id');
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Refusée par l'INVITÉ (`decline()`), à distinguer d'une révocation par le
     * propriétaire du dossier (`revoke()`) : même issue (le lien ne mène plus
     * nulle part), mais pas la même décision ni la même personne — défaut gênant
     * n°6 du test de bout en bout (2026-10-15), qui affichait « Révoquée » dans
     * les deux cas.
     */
    public function isDeclined(): bool
    {
        return $this->declined_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isRevoked() && ! $this->isDeclined() && ! $this->isExpired();
    }

    /** Invitations encore utilisables : ni acceptées, ni révoquées, ni refusées, ni expirées. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->whereNull('declined_at')
            ->where('expires_at', '>', now());
    }

    public function scopeForDossier(Builder $query, int $linkedDossierId): Builder
    {
        return $query->where('linked_dossier_id', $linkedDossierId);
    }

    public function scopeForEmail(Builder $query, string $email): Builder
    {
        return $query->where('email', $email);
    }
}
