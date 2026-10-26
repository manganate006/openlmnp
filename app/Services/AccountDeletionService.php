<?php

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\LinkedDossier;
use App\Models\LinkedDossierMember;
use App\Models\Property;
use App\Models\User;
use App\Support\DocumentStorage;
use App\Support\DossierEventScrubber;
use App\Support\DossierQuota;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Suppression définitive d'un compte, et de toutes ses données — droit à
 * l'effacement (RGPD, art. 17). Point UNIQUE, utilisé par la commande
 * `openlmnp:account-delete`, par l'action « Supprimer mon compte » du profil, et par
 * `App\Console\Commands\DemoCleanupCommand` (qui lui délègue la purge des données
 * d'un compte, sans en dupliquer la logique).
 *
 * ⚠️ Deux refus distincts, et c'est voulu :
 *   - `refusalReason()` : refus ABSOLU, quel que soit l'appelant (commande comprise) —
 *     compte de démonstration, dossier lié (ce n'est pas un compte personnel), dernier
 *     administrateur de l'instance.
 *   - `selfServiceRefusalReason()` : AJOUTE le refus propre à l'auto-suppression par
 *     l'utilisateur lui-même sur le cloud (abonnement potentiellement actif, voir plus
 *     bas) — un administrateur qui lance la commande à la demande d'un utilisateur
 *     n'est pas concerné par cette seconde couche, lui seul sait s'il a déjà vérifié
 *     Stripe.
 *
 * ⚠️ **Dossiers d'indivision partagés — la règle, tranchée noir sur blanc :**
 *   - un dossier dont ce compte est le SEUL membre actif (`accepted_at` non nul,
 *     `revoked_at` nul) est supprimé AVEC lui : ses propres données (biens, exercices,
 *     justificatifs) partent, comme pour un compte ordinaire ;
 *   - un dossier qui compte au moins un AUTRE membre actif reste intact pour lui :
 *     seule la ligne d'appartenance du compte supprimé disparaît (automatique, DB
 *     `cascadeOnDelete` sur `linked_dossier_members.user_id` — rien à coder ici).
 *   Alternative écartée : fermer le dossier (`isClosed()`) sans le supprimer même sans
 *   membre actif restant, par cohérence avec la résiliation (lot 3, `actingOwner()`).
 *   Mais ici il ne s'agit pas d'un compte suspendu ou qui part : le compte N'EXISTERA
 *   PLUS. Un dossier fantôme, qu'aucun compte ne peut plus jamais rouvrir ni réclamer,
 *   n'aurait plus aucune raison de rester en base — à la différence d'une résiliation,
 *   réversible par un autre abonnement.
 *
 * ⚠️ **Journaux d'audit (`dossier_events`, `mcp_audit_logs`) :**
 *   - `dossier_events.user_id` est `nullOnDelete()` (migration `create_dossier_events_table`) :
 *     la ligne survit, l'auteur devient anonyme. Mais la colonne `meta` porte, elle,
 *     l'adresse d'un invité et le SIREN du dossier : l'anonymisation de `user_id` ne
 *     suffisait pas (constat de la bêta du 2026-10-17, une adresse survivait à
 *     l'effacement). `purgeUserData()` délègue donc à `DossierEventScrubber` :
 *     journal SUPPRIMÉ pour un dossier qui disparaît, `meta` EXPURGÉ (type et date
 *     gardés) des lignes qui désignent un compte supprimé dans un dossier qui lui
 *     survit, et ses invitations dans ces dossiers supprimées.
 *   - `mcp_audit_logs.user_id` est `cascadeOnDelete()` : la ligne part avec le compte.
 *     C'est exactement ce que fait déjà `DemoCleanupCommand` (jamais de purge
 *     explicite de ce journal, il suit le compte) : suivre la même règle pour une
 *     suppression de compte réel, plutôt qu'inventer un traitement différent pour ce
 *     seul journal, évite que les deux mécanismes divergent sans raison.
 *   Ce second journal ne porte aucune adresse : il suit sa contrainte de clé
 *   étrangère dès que `User::delete()` est appelé, sans rien à faire de plus.
 */
class AccountDeletionService
{
    public const REFUSAL_DEMO = 'demo';

    public const REFUSAL_LINKED_DOSSIER = 'linked_dossier';

    public const REFUSAL_LAST_ADMIN = 'last_admin';

    public const REFUSAL_CLOUD_SUBSCRIPTION = 'cloud_subscription';

    /**
     * Refus ABSOLU, opposable à n'importe quel appelant (commande comprise).
     */
    public function refusalReason(User $user): ?string
    {
        if ($user->is_linked_dossier) {
            return self::REFUSAL_LINKED_DOSSIER;
        }

        if ($user->isDemo()) {
            return self::REFUSAL_DEMO;
        }

        if ($user->is_admin && User::query()->where('is_admin', true)->where('id', '!=', $user->id)->doesntExist()) {
            return self::REFUSAL_LAST_ADMIN;
        }

        return null;
    }

    /**
     * Refus opposable à l'AUTO-suppression (action du profil), qui ajoute un cas au
     * refus absolu : un compte STANDARD sur le cloud peut porter un abonnement payant
     * dont l'application elle-même n'a aucune visibilité (Stripe est connu de la
     * seule vitrine). Refuser ici est le choix le plus sûr : laisser passer
     * supprimerait silencieusement le compte d'un abonné sans jamais résilier son
     * paiement récurrent, et la vitrine recréerait un compte vierge au prochain appel
     * de webhook — compte perdu, abonnement non résilié, aucun des deux systèmes ne
     * s'en apercevrait. Un compte MEMBRE (`AccountKind::Member`) n'a jamais de
     * facturation propre (quota toujours nul, jamais promu que par paiement) : il
     * reste autorisé à se supprimer lui-même, même sur le cloud.
     */
    public function selfServiceRefusalReason(User $user): ?string
    {
        if ($reason = $this->refusalReason($user)) {
            return $reason;
        }

        if (DossierQuota::isCloudInstance() && ! $user->isMemberAccount()) {
            return self::REFUSAL_CLOUD_SUBSCRIPTION;
        }

        return null;
    }

    public function refusalMessage(string $code): string
    {
        return match ($code) {
            self::REFUSAL_DEMO => 'Les comptes de démonstration ne se suppriment pas par ce biais : '
                .'ils sont purgés automatiquement (commande openlmnp:demo-cleanup).',
            self::REFUSAL_LINKED_DOSSIER => 'Ce compte est un dossier lié, pas un compte personnel : '
                .'il n\'a pas de suppression qui lui soit propre.',
            self::REFUSAL_LAST_ADMIN => 'Ce compte est le dernier administrateur de cette instance. '
                .'Nommez un autre administrateur avant de le supprimer.',
            self::REFUSAL_CLOUD_SUBSCRIPTION => 'Votre compte est hébergé sur le cloud OpenLMNP, où un abonnement '
                .'payant peut être actif. Pour ne jamais le laisser courir après la suppression de votre compte, '
                .'résiliez-le d\'abord depuis '.$this->billingPortalUrl().', puis revenez supprimer votre compte.',
            default => 'Suppression refusée.',
        };
    }

    private function billingPortalUrl(): string
    {
        return rtrim((string) config('services.website.url'), '/').'/abonnement/gerer';
    }

    /**
     * Ce qui SERAIT supprimé, sans rien toucher — utilisée par `--dry-run` et par la
     * modale de confirmation du profil, pour nommer les dossiers d'indivision
     * partagés dont le sort diffère (supprimés avec le compte, ou simplement quittés).
     *
     * @return array{properties: int, fiscal_years: int, mcp_tokens: int, vault_enabled: bool,
     *               documents_on_disk: bool, dossiers_deleted: list<string>, dossiers_kept: list<string>}
     */
    public function plan(User $user): array
    {
        $dossiersDeleted = [];
        $dossiersKept = [];

        foreach ($this->activeMemberships($user) as $membership) {
            $dossier = $membership->dossier;

            if ($dossier === null || $dossier->user === null) {
                continue;
            }

            $name = $dossier->user->name ?: $dossier->public_id;

            if ($this->remainingActiveMembers($dossier, $user) === 0) {
                $dossiersDeleted[] = $name;
            } else {
                $dossiersKept[] = $name;
            }
        }

        return [
            'properties' => Property::withoutGlobalScopes()->where('user_id', $user->id)->count(),
            'fiscal_years' => FiscalYear::withoutGlobalScopes()->where('user_id', $user->id)->count(),
            'mcp_tokens' => $user->tokens()->count(),
            'vault_enabled' => $user->documentVault()->exists(),
            'documents_on_disk' => Storage::disk('local')->exists('documents/'.$user->id)
                || DocumentStorage::legacyDisk()->exists('documents/'.$user->id),
            'dossiers_deleted' => $dossiersDeleted,
            'dossiers_kept' => $dossiersKept,
        ];
    }

    /**
     * Supprime définitivement le compte et toutes ses données.
     *
     * @return array{dossiers_deleted: int, dossiers_kept: int}
     *
     * @throws AccountDeletionRefusedException
     */
    public function delete(User $user): array
    {
        if ($reason = $this->refusalReason($user)) {
            throw new AccountDeletionRefusedException($reason, $this->refusalMessage($reason));
        }

        return DB::transaction(function () use ($user): array {
            $dossiersDeleted = 0;
            $dossiersKept = 0;

            foreach ($this->activeMemberships($user) as $membership) {
                $dossier = $membership->dossier;

                if ($dossier === null || $dossier->user === null) {
                    // Donnée incohérente (dossier déjà orphelin) : rien à faire de
                    // plus que laisser le cascade DB retirer cette ligne de membre.
                    continue;
                }

                if ($this->remainingActiveMembers($dossier, $user) === 0) {
                    // Seul membre actif restant : le dossier n'a plus aucune raison
                    // d'exister une fois ce compte parti (voir le commentaire de
                    // classe). On purge SES données puis on supprime SON compte
                    // (celui du dossier, jamais celui de l'utilisateur) : cela
                    // cascade la ligne `linked_dossiers` elle-même, ses membres et
                    // ses invitations.
                    $this->purgeUserData($dossier->user);
                    $dossier->user->delete();
                    $dossiersDeleted++;
                } else {
                    // Dossier partagé : il reste aux autres membres. La ligne
                    // d'appartenance de CE compte part automatiquement avec lui
                    // (cascadeOnDelete sur linked_dossier_members.user_id, voir plus
                    // bas) — rien à faire ici.
                    $dossiersKept++;
                }
            }

            $this->purgeUserData($user);
            $user->delete();

            return ['dossiers_deleted' => $dossiersDeleted, 'dossiers_kept' => $dossiersKept];
        });
    }

    /**
     * Purge les données d'UN compte (bien ou dossier) : biens, exercices fiscaux,
     * justificatifs sur disque, jetons MCP et mentions du compte dans le journal des
     * dossiers liés (voir le commentaire de classe). Reprise à l'identique de la logique
     * historique d'`openlmnp:demo-cleanup`, étendue sur deux points constatés
     * manquants lors du test de la bêta du 2026-10-15 :
     *   - le repli d'ANCIENNE racine des justificatifs (`DocumentStorage::legacyDisk()`,
     *     `storage/app/documents/{id}` d'avant Laravel 11) n'était jamais purgé ;
     *   - les jetons d'accès personnels (`personal_access_tokens`, MCP) ne sont liés à
     *     leur compte que par une relation MORPH, sans contrainte de clé étrangère en
     *     base : rien ne les retirait automatiquement, et ils restaient — la cause la
     *     plus probable du justificatif `documents/1` resté sur le disque après une
     *     suppression manuelle en tinker (aucun des deux n'existait avant ce chantier).
     *
     * ⚠️ `Property::delete()` est appelé MODÈLE PAR MODÈLE, jamais en suppression de
     * masse : le reste (recettes, charges, composants, travaux, mobilier, emprunts et
     * leurs échéances) part par le `cascadeOnDelete()` de `property_id`.
     */
    public function purgeUserData(User $user): void
    {
        Property::withoutGlobalScopes()->where('user_id', $user->id)->get()
            ->each(fn (Property $property) => $property->delete());

        FiscalYear::withoutGlobalScopes()->where('user_id', $user->id)->delete();

        Storage::disk('local')->deleteDirectory('documents/'.$user->id);
        DocumentStorage::legacyDisk()->deleteDirectory('documents/'.$user->id);

        $user->tokens()->delete();

        // Journal des dossiers liés (RGPD) : avant `User::delete()`, tant que
        // l'identifiant et l'adresse du compte sont encore lisibles.
        $scrubber = app(DossierEventScrubber::class);

        if ($user->is_linked_dossier) {
            $dossierId = LinkedDossier::query()->where('user_id', $user->id)->value('id');

            if ($dossierId !== null) {
                $scrubber->forgetDossier((int) $dossierId);
            }
        } else {
            $scrubber->forgetPerson($user);
        }
    }

    /**
     * Dossiers où ce compte est aujourd'hui un membre ACTIF (accepté, non révoqué).
     *
     * @return Collection<int, LinkedDossierMember>
     */
    private function activeMemberships(User $user): Collection
    {
        return LinkedDossierMember::query()
            ->where('user_id', $user->id)
            ->whereNotNull('accepted_at')
            ->whereNull('revoked_at')
            ->with('dossier.user')
            ->get();
    }

    /** Membres actifs d'un dossier, EXCLUS celui dont la suppression est en cours. */
    private function remainingActiveMembers(LinkedDossier $dossier, User $excluding): int
    {
        return $dossier->members()
            ->whereNotNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('user_id', '!=', $excluding->id)
            ->count();
    }
}
