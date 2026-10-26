<?php

namespace App\Support;

use App\Models\DossierEvent;
use App\Models\LinkedDossier;
use App\Models\LinkedDossierInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Effacement des données personnelles du journal `dossier_events` (RGPD, art. 17).
 *
 * Constat sur la bêta du 2026-10-17 : après `openlmnp:account-delete`, le journal
 * gardait les lignes d'un dossier supprimé (auteur anonymisé par `nullOnDelete()`,
 * mais `meta` intact), et `meta` porte l'adresse de l'invité (`member_invited`) et le
 * SIREN (`created`). Une adresse e-mail survivait donc à l'effacement demandé.
 *
 * Trois règles, appliquées par `AccountDeletionService::purgeUserData()` (donc aussi
 * par `openlmnp:demo-cleanup`) et rejouables sur une base existante par
 * `openlmnp:dossier-events-purge` :
 *   - un dossier supprimé emporte TOUT son journal (`forgetDossier()`), invitations à
 *     des adresses sans compte comprises : rien ne reste à auditer ;
 *   - un compte supprimé alors qu'un dossier partagé lui survit (`forgetPerson()`) :
 *     les lignes qui le désignent gardent leur type et leur date (l'historique du
 *     dossier restant), mais `meta` est remplacé par `{"redacted": true}`. Ses
 *     invitations dans ces dossiers (affichées au responsable, avec l'adresse)
 *     sont supprimées ;
 *   - `purgeLeftovers()` : reliquats d'avant ce correctif.
 */
class DossierEventScrubber
{
    public const REDACTED = ['redacted' => true];

    /** Clés de `meta` qui désignent un compte par son identifiant. */
    private const ID_KEYS = ['member_id', 'user_id'];

    /** Supprime tout le journal d'un dossier qui disparaît. */
    public function forgetDossier(int $linkedDossierId): int
    {
        return DossierEvent::query()->where('linked_dossier_id', $linkedDossierId)->delete();
    }

    /**
     * Expurge le journal et les invitations des dossiers qui survivent à un compte
     * supprimé. À appeler AVANT `User::delete()` (l'identifiant de l'auteur est
     * encore en base).
     *
     * @return array{events_redacted: int, invitations_deleted: int}
     */
    public function forgetPerson(User $user): array
    {
        $email = mb_strtolower(trim((string) $user->email));
        $name = trim((string) $user->name);

        $redacted = 0;

        DossierEvent::query()->whereNotNull('meta')->orderBy('id')
            ->each(function (DossierEvent $event) use ($user, $email, $name, &$redacted): void {
                if ($this->mentions((array) $event->meta, $user->id, $email, $name)) {
                    $this->redact($event);
                    $redacted++;
                }
            });

        $invitations = $email === ''
            ? 0
            : LinkedDossierInvitation::query()->whereRaw('lower(email) = ?', [$email])->delete();

        return ['events_redacted' => $redacted, 'invitations_deleted' => $invitations];
    }

    /**
     * Reliquats d'avant ce correctif, idempotent (une seconde passe ne trouve plus rien) :
     *   1. lignes d'un dossier qui n'existe plus : supprimées ;
     *   2. invitations ACCEPTÉES dont l'adresse ne correspond plus à aucun compte
     *      (l'invité a été supprimé) : supprimées ;
     *   3. `member_invited` d'un dossier restant dont l'adresse ne correspond ni à un
     *      compte ni à une invitation encore en base : `meta` expurgé ;
     *   4. lignes dont `meta` désigne un compte qui n'existe plus : `meta` expurgé.
     * Une invitation en attente vers une adresse sans compte, dans un dossier qui
     * existe, est légitime : elle n'est pas touchée.
     *
     * @return array{orphan_events_deleted: int, invitations_deleted: int, events_redacted: int}
     */
    public function purgeLeftovers(bool $dryRun = false): array
    {
        DB::beginTransaction();

        try {
            $orphans = DossierEvent::query()
                ->whereNotIn('linked_dossier_id', LinkedDossier::query()->select('id'))
                ->delete();

            $invitations = LinkedDossierInvitation::query()
                ->whereNotNull('accepted_at')
                ->whereNotExists(fn ($q) => $q->from('users')->whereRaw('lower(users.email) = lower(linked_dossier_invitations.email)'))
                ->delete();

            $redacted = 0;

            DossierEvent::query()->whereNotNull('meta')->orderBy('id')
                ->each(function (DossierEvent $event) use (&$redacted): void {
                    if ($this->isLeftover($event)) {
                        $this->redact($event);
                        $redacted++;
                    }
                });

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return [
            'orphan_events_deleted' => $orphans,
            'invitations_deleted' => $invitations,
            'events_redacted' => $redacted,
        ];
    }

    /**
     * Remplace `meta` sans toucher `updated_at` : la date de l'événement reste celle
     * de l'action, pas celle de l'effacement.
     */
    private function redact(DossierEvent $event): void
    {
        DossierEvent::query()->whereKey($event->id)->toBase()
            ->update(['meta' => json_encode(self::REDACTED)]);
    }

    private function isLeftover(DossierEvent $event): bool
    {
        $meta = (array) $event->meta;

        foreach (self::ID_KEYS as $key) {
            if (isset($meta[$key]) && is_numeric($meta[$key]) && ! User::query()->whereKey((int) $meta[$key])->exists()) {
                return true;
            }
        }

        foreach ($meta as $value) {
            if (! is_string($value) || ! str_contains($value, '@')) {
                continue;
            }

            $email = mb_strtolower(trim($value));

            $known = User::query()->whereRaw('lower(email) = ?', [$email])->exists()
                || LinkedDossierInvitation::query()
                    ->where('linked_dossier_id', $event->linked_dossier_id)
                    ->whereRaw('lower(email) = ?', [$email])
                    ->exists();

            if (! $known) {
                return true;
            }
        }

        return false;
    }

    /** `meta` désigne-t-il ce compte (adresse, nom, identifiant) ? */
    private function mentions(array $meta, int $userId, string $email, string $name): bool
    {
        foreach ($meta as $key => $value) {
            if (in_array($key, self::ID_KEYS, true) && is_numeric($value) && (int) $value === $userId) {
                return true;
            }

            if (! is_string($value)) {
                continue;
            }

            $normalized = mb_strtolower(trim($value));

            if ($email !== '' && $normalized === $email) {
                return true;
            }

            if ($name !== '' && is_string($key) && str_contains($key, 'name') && $normalized === mb_strtolower($name)) {
                return true;
            }
        }

        return false;
    }
}
