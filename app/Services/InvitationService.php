<?php

namespace App\Services;

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Inscription sur invitation — toute la logique d'état vit ici.
 *
 * Les quatre appelants (route publique, page Livewire d'inscription, commande artisan, page
 * d'administration) n'en sont que des façades : c'est ce qui garantit qu'une invitation
 * révoquée l'est partout, et qu'un jeton ne sert qu'une fois quel que soit le chemin.
 */
class InvitationService
{
    /**
     * Crée une invitation et rend le jeton EN CLAIR — la seule fois où il existera.
     *
     * @return array{0: Invitation, 1: string}
     */
    public function create(string $email, ?string $name = null, ?User $invitedBy = null, ?int $days = null, ?string $note = null): array
    {
        $email = mb_strtolower(trim($email));

        if (User::where('email', $email)->exists()) {
            throw new RuntimeException("Un compte existe déjà pour {$email}.");
        }

        if (Invitation::query()->forEmail($email)->pending()->exists()) {
            throw new RuntimeException(
                "Une invitation est déjà en attente pour {$email}. Relancez-la (--resend) ou révoquez-la (--revoke)."
            );
        }

        $plain = Str::random(48);

        $invitation = Invitation::create([
            'email' => $email,
            'name' => $name,
            'token_hash' => $this->hash($plain),
            // ⚠️ Figée à la création : changer INVITATION_TTL_DAYS ne doit pas rallonger
            // rétroactivement les invitations déjà envoyées.
            'expires_at' => now()->addDays($days ?? (int) config('invitations.ttl_days', 14)),
            'invited_by_user_id' => $invitedBy?->id,
            'note' => $note,
            'sent_count' => 1,
            'last_sent_at' => now(),
        ]);

        return [$invitation, $plain];
    }

    /**
     * Régénère le jeton et repousse l'expiration. L'ANCIEN LIEN MEURT.
     *
     * Relancer, c'est remplacer : deux liens vivants pour la même personne, ce sont deux
     * chemins à révoquer le jour où il faut fermer.
     *
     * @return string Le nouveau jeton en clair.
     */
    public function resend(Invitation $invitation, ?int $days = null): string
    {
        if ($invitation->isAccepted()) {
            throw new RuntimeException('Cette invitation a déjà été acceptée.');
        }

        if ($invitation->isRevoked()) {
            throw new RuntimeException('Cette invitation a été révoquée. Émettez-en une nouvelle.');
        }

        $plain = Str::random(48);

        $invitation->update([
            'token_hash' => $this->hash($plain),
            'expires_at' => now()->addDays($days ?? (int) config('invitations.ttl_days', 14)),
            'sent_count' => $invitation->sent_count + 1,
            'last_sent_at' => now(),
        ]);

        return $plain;
    }

    /**
     * Révoque une invitation. La ligne est conservée.
     *
     * ⚠️ NE TOUCHE PAS à un compte déjà créé : c'est `users.suspended_at` qui suspend un
     * accès, pas ceci. À dire dans l'aide ET dans la confirmation de la page d'administration,
     * sans quoi on croit couper un accès qu'on n'a pas coupé.
     */
    public function revoke(Invitation $invitation): void
    {
        if ($invitation->isAccepted()) {
            throw new RuntimeException('Cette invitation a déjà été acceptée : révoquer ne fermerait aucun accès. Suspendez le compte.');
        }

        $invitation->update(['revoked_at' => now()]);
    }

    /** Retrouve une invitation ENCORE UTILISABLE depuis un jeton en clair, ou null. */
    public function resolve(string $plainToken): ?Invitation
    {
        // Pas de hash_equals : on n'oppose pas deux secrets en mémoire, on interroge un index.
        return Invitation::where('token_hash', $this->hash($plainToken))->first();
    }

    /**
     * Consomme l'invitation et crée le compte, dans une seule transaction.
     *
     * ⚠️ L'adresse vient de la LIGNE, jamais du formulaire : une invitation émise pour A ne
     * peut pas créer un compte pour B, même si le navigateur envoie autre chose.
     *
     * ⚠️ UPDATE conditionnel plutôt que lecture-puis-écriture : c'est un compare-and-set
     * atomique y compris sous SQLite, où `lockForUpdate()` ne fait rien. Deux soumissions
     * simultanées du même lien ne créent qu'un compte.
     */
    public function claim(Invitation $invitation, array $userData): User
    {
        return DB::transaction(function () use ($invitation, $userData) {
            $consumed = Invitation::whereKey($invitation->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update(['accepted_at' => now()]);

            if ($consumed === 0) {
                throw new RuntimeException('Ce lien n\'est plus valable.');
            }

            // Une invitation valide n'est pas une autorisation : l'adresse a pu recevoir un
            // compte entre-temps (API de provisionnement, inscription libre sur une instance
            // qui a changé de mode). Le contrôle est DANS la transaction, pas à l'affichage.
            if (User::where('email', $invitation->email)->exists()) {
                throw new RuntimeException('Un compte existe déjà pour cette adresse.');
            }

            $user = User::create([
                'name' => $userData['name'] ?? $invitation->name ?? Str::before($invitation->email, '@'),
                'email' => $invitation->email,
                'password' => $userData['password'],
            ]);

            $invitation->forceFill(['accepted_at' => now(), 'user_id' => $user->id])->save();

            return $user;
        });
    }

    /** Envoie (ou renvoie) le courriel d'invitation. Rend false si rien n'est parti. */
    public function notify(Invitation $invitation, string $plainToken): bool
    {
        try {
            $invitation->notify(new AccountInvitation(
                AccountInvitation::urlFor($plainToken),
                $invitation->expires_at,
                $invitation->invitedBy?->name,
            ));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
