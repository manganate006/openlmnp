<?php

namespace App\Services;

use App\Enums\AccountKind;
use App\Enums\DossierMemberRole;
use App\Models\DossierEvent;
use App\Models\LinkedDossier;
use App\Models\LinkedDossierInvitation;
use App\Models\User;
use App\Notifications\LinkedDossierInvitation as LinkedDossierInvitationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Invitation du SECOND indivisaire à un dossier lié déjà créé (lot 3, plan § 6
 * et § 10). Même patron de jeton haché que App\Services\InvitationService
 * (sha256 sur App\Models\LinkedDossierInvitation::token_hash), mais un usage
 * différent : celle-ci ne crée JAMAIS de compte, elle rattache un principal
 * (déjà abonné, ou qui vient de se connecter/s'inscrire) à un dossier EXISTANT,
 * sans consommer son quota de dossiers liés.
 *
 * ⚠️ `create()` ne vérifie JAMAIS si l'adresse correspond à un compte déjà
 * existant sur l'instance. Contrairement à InvitationService (réservée aux
 * administrateurs, `RequiresAdmin`), cette création est ouverte à n'importe
 * quel propriétaire d'un dossier lié — un propriétaire malveillant pourrait
 * sinon s'en servir comme un oracle pour savoir quelles adresses sont déjà
 * inscrites sur l'instance. La seule chose vérifiable (et vérifiée) est interne
 * au dossier : est-ce déjà un membre actif de CE dossier.
 */
class LinkedDossierInvitationService
{
    /**
     * @return array{0: LinkedDossierInvitation, 1: string} l'invitation et son jeton EN CLAIR
     *
     * @throws RuntimeException
     */
    public function create(LinkedDossier $dossier, string $email, User $invitedBy): array
    {
        $email = mb_strtolower(trim($email));

        if ($dossier->isArchived()) {
            throw new RuntimeException('Ce dossier est archivé.');
        }

        if ($dossier->actingOwner()?->id !== $invitedBy->id) {
            throw new RuntimeException('Seul le responsable actuel de ce dossier peut y inviter quelqu\'un.');
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Adresse électronique invalide.');
        }

        // Aucun e-mail vers un dossier lié : son adresse technique @dossiers.invalid
        // n'existe pour personne et ne doit jamais recevoir de courriel.
        if (str_ends_with($email, '@dossiers.invalid')) {
            throw new RuntimeException('Cette adresse ne peut pas être invitée.');
        }

        // Ce contrôle-ci NE fuit rien d'instance-wide : il ne regarde que CE
        // dossier, que son propriétaire connaît déjà (la liste des membres lui
        // est affichée).
        $existingUser = User::where('email', $email)->first();

        if ($existingUser !== null
            && $dossier->members()->where('user_id', $existingUser->id)->whereNotNull('accepted_at')->whereNull('revoked_at')->exists()
        ) {
            throw new RuntimeException('Cette personne est déjà membre de ce dossier.');
        }

        if (LinkedDossierInvitation::query()->forDossier($dossier->id)->forEmail($email)->pending()->exists()) {
            throw new RuntimeException('Une invitation est déjà en attente pour cette adresse. Relancez-la ou révoquez-la.');
        }

        $plain = Str::random(48);

        $invitation = LinkedDossierInvitation::create([
            'linked_dossier_id' => $dossier->id,
            'email' => $email,
            'token_hash' => $this->hash($plain),
            'invited_by' => $invitedBy->id,
            'expires_at' => now()->addDays((int) config('dossiers.invitation_ttl_days', 14)),
        ]);

        DossierEvent::record($dossier->id, DossierEvent::TYPE_MEMBER_INVITED, $invitedBy, ['email' => $email]);

        return [$invitation, $plain];
    }

    /**
     * Régénère le jeton et repousse l'expiration. L'ANCIEN LIEN MEURT — même
     * patron qu'InvitationService::resend().
     *
     * @return string le nouveau jeton en clair
     *
     * @throws RuntimeException
     */
    public function resend(LinkedDossierInvitation $invitation, User $actor): string
    {
        if ($invitation->dossier->actingOwner()?->id !== $actor->id) {
            throw new RuntimeException('Seul le responsable actuel de ce dossier peut relancer cette invitation.');
        }

        if ($invitation->isAccepted()) {
            throw new RuntimeException('Cette invitation a déjà été acceptée.');
        }

        if ($invitation->isRevoked()) {
            throw new RuntimeException('Cette invitation a été révoquée. Émettez-en une nouvelle.');
        }

        // Réenvoi LIMITÉ, même patron qu'InvitationService::resend() : relancer
        // REMPLACE le jeton plutôt que d'en empiler un second — à tout instant,
        // au plus UN lien valide par invitation. Doublé du débit HTTP de la page
        // d'acceptation (`throttle:10,1`, voir routes/web.php) côté porteur du lien.
        $plain = Str::random(48);

        $invitation->update([
            'token_hash' => $this->hash($plain),
            'expires_at' => now()->addDays((int) config('dossiers.invitation_ttl_days', 14)),
        ]);

        return $plain;
    }

    /**
     * Révoque une invitation. La ligne est conservée.
     *
     * ⚠️ Ne retire PAS l'accès d'un membre déjà accepté : révoquer ici ferme un
     * lien, pas une adhésion déjà effective (même garde qu'InvitationService).
     *
     * @throws RuntimeException
     */
    public function revoke(LinkedDossierInvitation $invitation, User $actor): void
    {
        if ($invitation->dossier->actingOwner()?->id !== $actor->id) {
            throw new RuntimeException('Seul le responsable actuel de ce dossier peut révoquer cette invitation.');
        }

        if ($invitation->isAccepted()) {
            throw new RuntimeException('Cette invitation a déjà été acceptée : révoquez le membre depuis la liste plutôt.');
        }

        $invitation->update(['revoked_at' => now()]);
    }

    /** Retrouve une invitation depuis un jeton en clair, quel que soit son état. */
    public function resolve(string $plainToken): ?LinkedDossierInvitation
    {
        // Pas de hash_equals : on n'oppose pas deux secrets en mémoire, on interroge un index.
        return LinkedDossierInvitation::where('token_hash', $this->hash($plainToken))->first();
    }

    /**
     * Consomme l'invitation et rattache l'invité au dossier — SANS jamais créer
     * de compte ni consommer son quota de dossiers liés (plan § 6).
     *
     * ⚠️ Toutes les conditions d'éligibilité de L'INVITÉ (connecté avec la bonne
     * adresse, compte actif, pas un dossier lié lui-même...) sont vérifiées par
     * l'APPELANT (le contrôleur) : ce service ne fait que la partie « écriture
     * atomique », comme InvitationService::claim().
     *
     * @throws RuntimeException
     */
    public function accept(LinkedDossierInvitation $invitation, User $acceptor): \App\Models\LinkedDossierMember
    {
        return DB::transaction(function () use ($invitation, $acceptor) {
            $consumed = LinkedDossierInvitation::whereKey($invitation->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update(['accepted_at' => now()]);

            if ($consumed === 0) {
                throw new RuntimeException('Ce lien n\'est plus valable.');
            }

            $dossier = $invitation->dossier;

            $membership = $dossier->members()->where('user_id', $acceptor->id)->first();

            if ($membership !== null && $membership->revoked_at === null) {
                // Double acceptation concurrente (deux onglets) : rien à refaire.
                return $membership;
            }

            if ($membership !== null) {
                // Un ancien membre révoqué qui revient par une NOUVELLE invitation :
                // on réactive SA MÊME ligne plutôt que d'en recréer une — l'unicité
                // (linked_dossier_id, user_id) l'imposerait de toute façon.
                $membership->update([
                    'role' => DossierMemberRole::Member,
                    'invited_by' => $invitation->invited_by,
                    'accepted_at' => now(),
                    'revoked_at' => null,
                ]);
            } else {
                $membership = $dossier->members()->create([
                    'user_id' => $acceptor->id,
                    'role' => DossierMemberRole::Member,
                    'invited_by' => $invitation->invited_by,
                    'accepted_at' => now(),
                ]);
            }

            DossierEvent::record($dossier->id, DossierEvent::TYPE_MEMBER_ACCEPTED, $acceptor, [
                'invitation_id' => $invitation->id,
            ]);

            return $membership;
        });
    }

    /**
     * Crée un compte « membre » (App\Enums\AccountKind::Member) à partir de CETTE
     * invitation, et le rattache au dossier — en une seule étape (lot « dossiers
     * membre », décision de Jérémie du 2026-10-15) : « un indivisaire sans biens à
     * lui doit pouvoir accéder gratuitement au dossier d'indivision partagé sur
     * lequel il est invité, sans abonnement ».
     *
     * ⚠️ L'e-mail est CELUI DE L'INVITATION, jamais un champ du formulaire : `$data`
     * ne doit contenir aucune clé `email` lue depuis la requête (voir
     * App\Http\Controllers\Dossier\CreateMemberAccountController, qui ne valide même
     * pas une telle clé). C'est ce qui rend tout « forçage » de l'adresse sans effet,
     * et ce qui garde cette création étanche à toute surface d'énumération : le seul
     * e-mail en jeu est celui que le porteur du jeton connaît déjà (affiché par la
     * page avant ce formulaire).
     *
     * ⚠️ Contourne volontairement App\Support\RegistrationGate (ALLOW_REGISTRATION
     * `false` ou `invite`) — MAIS seulement avec un jeton d'invitation de DOSSIER
     * valide (non expiré, non révoqué, non déjà utilisé) : c'est l'appelant qui a
     * déjà vérifié ces trois conditions via `resolve()`/`isRevoked()`/`isAccepted()`/
     * `isExpired()`, cette méthode revérifie l'état de la ligne de façon ATOMIQUE
     * (UPDATE conditionnel, même patron que `accept()` et qu'`InvitationService::
     * claim()`) pour fermer la fenêtre de course entre l'affichage du formulaire et
     * sa soumission.
     *
     * @param  array{name: string, password: string}  $data
     *
     * @throws RuntimeException
     */
    public function acceptAsNewMember(LinkedDossierInvitation $invitation, array $data): User
    {
        return DB::transaction(function () use ($invitation, $data) {
            $consumed = LinkedDossierInvitation::whereKey($invitation->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update(['accepted_at' => now()]);

            if ($consumed === 0) {
                throw new RuntimeException('Ce lien n\'est plus valable.');
            }

            // Course avec une AUTRE voie de création de compte (inscription libre
            // rouverte entre-temps, provisioning de la vitrine...) : DANS la
            // transaction, pas à l'affichage — même garde qu'InvitationService::claim().
            // Message volontairement orienté vers la connexion, jamais formulé comme
            // une découverte : le porteur du jeton connaît déjà cette adresse (c'est
            // la sienne), ce n'est donc PAS un oracle d'énumération.
            if (User::where('email', $invitation->email)->exists()) {
                throw new RuntimeException('Un compte existe déjà pour cette adresse : connectez-vous plutôt.');
            }

            $user = new User;
            $user->forceFill([
                'name' => $data['name'],
                'email' => $invitation->email,
                // Cast `'password' => 'hashed'` sur User : la valeur en clair est
                // hachée à l'enregistrement, même patron qu'InvitationService::claim().
                'password' => $data['password'],
                'account_kind' => AccountKind::Member,
            ])->save();

            $dossier = $invitation->dossier;

            $dossier->members()->create([
                'user_id' => $user->id,
                'role' => DossierMemberRole::Member,
                'invited_by' => $invitation->invited_by,
                'accepted_at' => now(),
            ]);

            DossierEvent::record($dossier->id, DossierEvent::TYPE_MEMBER_ACCEPTED, $user, [
                'invitation_id' => $invitation->id,
                'account_created' => true,
            ]);

            return $user;
        });
    }

    /**
     * L'invité refuse explicitement son invitation (écran 7, variante B, décision du
     * 2026-10-15) — distincte de `revoke()` : celle-ci est réservée au responsable du
     * dossier et vérifie qu'il l'est encore ; ici, c'est l'INVITÉ LUI-MÊME qui ferme
     * son propre lien, aucune appartenance à vérifier. Idempotente : refuser une
     * invitation déjà révoquée ne fait rien de plus.
     *
     * @throws RuntimeException si elle a déjà été acceptée (rien à « refuser » d'un
     *                           rattachement déjà effectif)
     */
    public function decline(LinkedDossierInvitation $invitation): void
    {
        if ($invitation->isAccepted()) {
            throw new RuntimeException('Cette invitation a déjà été acceptée.');
        }

        if ($invitation->isRevoked() || $invitation->isDeclined()) {
            return;
        }

        // `declined_at`, PAS `revoked_at` : c'est l'invité qui ferme son propre
        // lien, pas le responsable du dossier qui le révoque — deux décisions
        // distinctes, affichées distinctement dans la liste (« Refusée » /
        // « Révoquée »).
        $invitation->update(['declined_at' => now()]);
    }

    /** Envoie (ou renvoie) le courriel d'invitation. Rend false si rien n'est parti. */
    public function notify(LinkedDossierInvitation $invitation, string $plainToken): bool
    {
        try {
            $invitation->notify(new LinkedDossierInvitationMail(
                LinkedDossierInvitationMail::urlFor($plainToken),
                $invitation->expires_at,
                $invitation->invitedBy?->name,
                (string) $invitation->dossier->user?->name,
                $invitation->dossier->siren,
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
