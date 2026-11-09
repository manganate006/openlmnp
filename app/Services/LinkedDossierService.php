<?php

namespace App\Services;

use App\Enums\DossierMemberRole;
use App\Models\DossierEvent;
use App\Models\LinkedDossier;
use App\Models\User;
use App\Services\OpenData\SireneClient;
use App\Services\OpenData\SireneUnavailable;
use App\Support\DossierQuota;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Création et gestion des dossiers liés (sous-comptes d'indivision offerts).
 *
 * Toute la logique d'autorisation de la CRÉATION vit ici — l'assistant Filament (lot 2)
 * n'en est qu'une façade, comme `InvitationService` pour les invitations de compte.
 */
class LinkedDossierService
{
    public function __construct(private readonly SireneClient $sirene) {}

    /**
     * Vérifie un SIREN pour la création d'un dossier lié, SANS RIEN CRÉER : mêmes
     * contrôles que `create()`, jusqu'à et y compris l'appel réseau Sirene. Utilisée
     * par `create()` elle-même, et par l'étape 2 (« Sirene ») de l'assistant de
     * création (App\Filament\Pages\LinkedDossiers), qui doit pouvoir afficher le
     * résultat avant de s'engager à créer quoi que ce soit.
     *
     * @return array{siren: string, snapshot: array<string, mixed>|null}
     *
     * @throws LinkedDossierCreationException
     */
    public function inspect(User $owner, string $siren): array
    {
        if ($owner->isDemo()) {
            throw LinkedDossierCreationException::demo();
        }

        if (! DossierQuota::canCreate($owner)) {
            throw LinkedDossierCreationException::quotaExceeded();
        }

        $digits = preg_replace('/\s+/', '', $siren) ?? '';

        if (! preg_match('/^\d{9}$/', $digits) || ! SireneClient::luhn($digits)) {
            throw LinkedDossierCreationException::invalidSiren();
        }

        // Avant l'appel réseau : un SIREN déjà suivi est le cas le plus probable d'un
        // second indivisaire qui tente de créer SON dossier au lieu de rejoindre celui
        // qui existe déjà (lot 3, hors périmètre ici).
        if (LinkedDossier::where('siren', $digits)->exists()) {
            throw LinkedDossierCreationException::sirenTaken();
        }

        $snapshot = $this->verifySirene($digits);

        return ['siren' => $digits, 'snapshot' => $snapshot];
    }

    /**
     * @throws LinkedDossierCreationException
     */
    public function create(User $owner, string $siren, string $name): LinkedDossier
    {
        $inspected = $this->inspect($owner, $siren);
        $digits = $inspected['siren'];
        $snapshot = $inspected['snapshot'];

        $name = trim($name) !== '' ? trim($name) : "Indivision {$digits}";

        return DB::transaction(function () use ($owner, $digits, $name, $snapshot) {
            $dossierUser = new User;
            $dossierUser->forceFill([
                'name' => $name,
                'email' => 'dossier-'.Str::lower((string) Str::ulid()).'@dossiers.invalid',
                // Mot de passe aléatoire, jamais communiqué : la SEULE porte d'entrée
                // est la bascule (App\Support\DossierContext::switchTo()).
                'password' => Hash::make(Str::random(64)),
                'is_linked_dossier' => true,
                'is_indivision' => true,
                'siren' => $digits,
                // ⚠️ `$owner` vient parfois d'être créé dans la MÊME requête (tests, ou un
                // compte qui ouvre son premier dossier aussitôt après inscription) : tant
                // qu'il n'a pas été relu depuis la base, les colonnes posées par un DEFAULT
                // SQL (nav_mode, timezone) sont ABSENTES de l'instance en mémoire, et valent
                // `null` — jamais la valeur par défaut. Un `forceFill` qui les recopierait
                // telles quelles enverrait NULL dans des colonnes NOT NULL.
                'timezone' => $owner->timezone ?? 'Europe/Paris',
                'nav_mode' => $owner->nav_mode ?? \App\Enums\NavMode::Simple,
            ])->save();

            $dossier = LinkedDossier::create([
                'public_id' => (string) Str::ulid(),
                'user_id' => $dossierUser->id,
                'owner_id' => $owner->id,
                'kind' => 'indivision',
                'siren' => $digits,
                'sirene_snapshot' => $snapshot,
                'sirene_checked_at' => $snapshot !== null ? now() : null,
            ]);

            $dossier->members()->create([
                'user_id' => $owner->id,
                'role' => DossierMemberRole::Owner,
                'accepted_at' => now(),
            ]);

            DossierEvent::record($dossier->id, DossierEvent::TYPE_CREATED, $owner, ['siren' => $digits]);

            return $dossier;
        });
    }

    /**
     * @return array<string, mixed>|null le résultat de `SireneClient::describe()`, ou
     *                                   null si la vérification est facultative et
     *                                   coupée sur cette instance (auto-hébergement)
     *
     * @throws LinkedDossierCreationException
     */
    private function verifySirene(string $siren): ?array
    {
        if (! (bool) config('opendata.sirene.enabled')) {
            // Sur le cloud, Sirene reste OBLIGATOIRE (plan § décisions) : une instance
            // sans open data Sirene activé est une anomalie de configuration, pas une
            // facilité à offrir en douce.
            if (DossierQuota::isCloudInstance()) {
                throw LinkedDossierCreationException::sireneUnavailable(
                    'La vérification Sirene est désactivée sur cette instance : impossible de créer un dossier lié.'
                );
            }

            // Auto-hébergement, open data coupé : la vérification devient facultative
            // (plan § décisions) — aucun justificatif n'est disponible pour l'étayer.
            return null;
        }

        try {
            // `fresh: true` : une décision qui ACCORDE quelque chose (dossier offert) se
            // prend sur l'état du jour, jamais sur un relevé en cache.
            $company = $this->sirene->lookup($siren, fresh: true);
        } catch (SireneUnavailable $e) {
            throw LinkedDossierCreationException::sireneUnavailable($e->getMessage());
        }

        if ($company === null) {
            throw LinkedDossierCreationException::sirenNotFound();
        }

        $described = SireneClient::describe($company);

        if ($described['legal_form'] !== '2110') {
            throw LinkedDossierCreationException::wrongLegalForm($described['legal_form']);
        }

        if (! $described['active']) {
            throw LinkedDossierCreationException::closed();
        }

        return $described;
    }

    /**
     * Révoque un membre. Le créateur (owner) ne peut pas être révoqué par ce chemin :
     * voir décision n°5 du plan (transfert du lot 3), hors périmètre ici.
     */
    public function revokeMember(LinkedDossier $dossier, User $actor, User $member): void
    {
        $membership = $dossier->members()->where('user_id', $member->id)->whereNull('revoked_at')->first();

        if ($membership === null) {
            return;
        }

        if ($membership->role === DossierMemberRole::Owner) {
            throw new \RuntimeException('Le créateur du dossier ne peut pas être révoqué ici.');
        }

        $membership->update(['revoked_at' => now()]);

        DossierEvent::record($dossier->id, DossierEvent::TYPE_MEMBER_REVOKED, $actor, ['member_id' => $member->id]);
    }

    public function archive(LinkedDossier $dossier, User $actor): void
    {
        $dossier->update(['archived_at' => now()]);

        DossierEvent::record($dossier->id, DossierEvent::TYPE_ARCHIVED, $actor);
    }

    /**
     * Un membre quitte le dossier de son propre chef (lot 3, plan § 10 décision n°5
     * « si le créateur ... part »). Révoque SA PROPRE ligne, quel que soit son rôle —
     * y compris le créateur (owner) : contrairement à `revokeMember()` (révocation
     * PAR AUTRUI, qui protège explicitement le créateur), partir soi-même est
     * toujours permis.
     *
     * Aucune réécriture de `role`/`owner_id` : la ligne du partant est simplement
     * révoquée, donc exclue de `LinkedDossier::actingOwner()` dès la requête
     * suivante — c'est CE calcul, pas une transition écrite ici, qui fait passer
     * « l'administration » à un autre membre actif. Sans membre actif restant, le
     * dossier reste tel quel : conservé, jamais supprimé (`isClosed()` le dira).
     */
    public function leave(LinkedDossier $dossier, User $member): void
    {
        $membership = $dossier->members()->where('user_id', $member->id)->whereNull('revoked_at')->first();

        if ($membership === null) {
            return;
        }

        $membership->update(['revoked_at' => now()]);

        DossierEvent::record($dossier->id, DossierEvent::TYPE_LEFT, $member);
    }
}
