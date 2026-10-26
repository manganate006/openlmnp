<?php

namespace App\Filament\Pages\Concerns;

use App\Services\UpdateService;
use Illuminate\Support\Str;

/**
 * Version installée, dernière version publiée et notes des dernières releases.
 *
 * Partagé par la page « Nouveautés » (tout utilisateur) et par « Mises à jour »
 * (administrateurs) : une seule implémentation, un seul relevé, un seul cache.
 *
 * ⚠️ Le montage NE FAIT AUCUN APPEL RÉSEAU : il ne lit que le cache. Attendre GitHub au
 * montage coûtait jusqu'à 10 s d'écran blanc par affichage sur une instance hors ligne
 * (`Http::timeout(10)` × 3 relevés). Le relevé part ensuite du navigateur, par `wire:init`,
 * et seulement si le cache est froid.
 */
trait ShowsVersionInfo
{
    /** Dernière release publiée. `null` tant qu'aucun relevé n'est disponible. */
    public ?array $updateInfo = null;

    /** Notes des dernières releases. `null` tant qu'aucun relevé n'est disponible. */
    public ?array $changelog = null;

    public function mountVersionInfo(): void
    {
        $service = new UpdateService();

        $this->updateInfo = $service->cached(UpdateService::KIND_RELEASE);
        $this->changelog = $service->cached(UpdateService::KIND_CHANGELOG);
    }

    /** Un relevé manque-t-il ? Seul cas où la vue déclenche un `wire:init`. */
    public function needsVersionFetch(): bool
    {
        return $this->updateInfo === null || $this->changelog === null;
    }

    /**
     * Relève les versions publiées.
     *
     * Appelée sans argument par `wire:init` (cache froid), avec `true` par le bouton
     * « Vérifier », qui doit repartir sur le réseau même si le cache est chaud.
     */
    public function refreshVersionInfo(bool $force = false): void
    {
        $service = new UpdateService();

        $this->updateInfo = $service->checkForUpdates($force);
        $this->changelog = $service->getChangelog(5, $force);
    }

    public function currentVersion(): string
    {
        return (new UpdateService())->getCurrentVersion();
    }

    /**
     * `version.json` est-il exploitable ?
     *
     * Faux sur un checkout où le fichier manque : mieux vaut dire « inconnue » qu'annoncer
     * un « v0.0.0 » que personne n'a jamais publié.
     */
    public function versionIsKnown(): bool
    {
        return isset((new UpdateService())->getVersionInfo()['version']);
    }

    /** Date de sortie de la version installée, au format français. */
    public function releasedAt(): ?string
    {
        $date = (new UpdateService())->getVersionInfo()['released_at'] ?? null;

        if (! $date) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($date)->format('d/m/Y');
        } catch (\Exception) {
            return null;
        }
    }

    /** Le relevé des versions publiées est-il autorisé à sortir sur le réseau ? */
    public function versionCheckEnabled(): bool
    {
        return (new UpdateService())->checkEnabled();
    }

    /**
     * Annonce-t-on qu'une version plus récente existe ?
     *
     * Non sur une instance qui ne peut pas se mettre à jour elle-même (cloud, image Docker
     * officielle : `UPDATE_SELF_APPLY=false`) pour qui n'est pas administrateur. Le badge
     * décrirait alors une action que l'utilisateur ne peut pas faire, et le seul effet
     * possible est un message au support. La version installée et les notes des dernières
     * versions restent affichées : c'est par elles qu'on voit ce qui a changé.
     *
     * L'administrateur, lui, le voit toujours — c'est lui qui lance le `docker pull`.
     */
    public function showsAvailableUpdateBadge(): bool
    {
        return (new UpdateService())->selfApplyEnabled()
            || (bool) (auth()->user()?->is_admin);
    }

    /** Cette release est-elle postérieure à la version installée ? */
    public function releaseIsNewerThanInstalled(?string $version): bool
    {
        return $version !== null
            && $version !== ''
            && version_compare($version, $this->currentVersion(), '>');
    }

    /**
     * Notes de release GitHub (Markdown) rendues en HTML.
     *
     * Appelée depuis la vue, et non stockée dans une propriété Livewire : le HTML rendu
     * n'a rien à faire dans l'état sérialisé de la page.
     *
     * ⚠️ `html_input: strip` n'est pas décoratif — le corps d'une release est du texte
     * rédigé sur GitHub, qui peut contenir du HTML arbitraire ; il est retiré, comme les
     * liens `javascript:`.
     */
    public function renderReleaseNotes(?string $markdown): string
    {
        if ($markdown === null || trim($markdown) === '') {
            return '';
        }

        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
}
