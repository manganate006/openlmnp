<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\RequiresAdmin;
use App\Filament\Pages\Concerns\ShowsVersionInfo;
use App\Models\Setting;
use App\Services\UpdateService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class AdminUpdate extends Page
{
    use RequiresAdmin;
    use ShowsVersionInfo;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;
    protected static string|UnitEnum|null $navigationGroup = 'Administration';
    protected static ?string $navigationLabel = 'Mises à jour';
    protected static ?string $title = 'Mises à jour';
    protected static ?int $navigationSort = 2;
    protected string $view = 'filament.pages.admin-update';

    // Auto-update
    public bool $autoUpdateEnabled = false;

    // Releases — $updateInfo et $changelog viennent de ShowsVersionInfo
    public ?array $updateResult = null;

    // Branche (dev)
    public ?array $branchInfo = null;
    public ?array $deployResult = null;

    // Configuration GitHub
    public string $githubToken = '';
    public string $githubRepo = '';

    /**
     * ⚠️ Aucun appel réseau ici : le montage ne lit que le cache.
     *
     * Jusqu'au 2026-09-05, `mount()` appelait `checkBranchUpdates()`, donc GitHub, à chaque
     * affichage de la page : 10 s d'attente sur une instance hors ligne, et trois requêtes
     * prélevées sur les 60/h de l'API non authentifiée. Le relevé part désormais du
     * navigateur (`wire:init`), et seulement si le cache est froid.
     */
    public function mount(): void
    {
        $this->githubToken = Setting::get('github_token', '') ?? '';
        $this->githubRepo = Setting::get('github_repo', '') ?? '';
        $this->autoUpdateEnabled = Setting::get('auto_update_enabled') === '1';

        $this->mountVersionInfo();
        $this->branchInfo = (new UpdateService())->cached(UpdateService::KIND_BRANCH);
    }

    /** Vrai si l'un des trois relevés manque — la vue déclenche alors un `wire:init`. */
    public function needsVersionFetch(): bool
    {
        return $this->updateInfo === null || $this->changelog === null || $this->branchInfo === null;
    }

    /** Relève les releases ET la branche, en un seul aller-retour. */
    public function refreshVersionInfo(bool $force = false): void
    {
        $service = new UpdateService();

        $this->updateInfo = $service->checkForUpdates($force);
        $this->changelog = $service->getChangelog(5, $force);

        $this->branchInfo = $service->checkBranchUpdates('main', $force);
        $this->cacheBranchBadge();
    }

    public function toggleAutoUpdate(): void
    {
        $this->autoUpdateEnabled = ! $this->autoUpdateEnabled;
        Setting::set('auto_update_enabled', $this->autoUpdateEnabled ? '1' : '0');

        Notification::make()
            ->success()
            ->title($this->autoUpdateEnabled ? 'Mise à jour automatique activée' : 'Mise à jour automatique désactivée')
            ->body($this->autoUpdateEnabled ? 'Vérification toutes les heures via app:auto-update.' : '')
            ->send();
    }

    // -------------------------------------------------------------------------
    // Configuration GitHub
    // -------------------------------------------------------------------------

    public function saveGithubSettings(): void
    {
        Setting::set('github_token', $this->githubToken ?: null);
        Setting::set('github_repo', $this->githubRepo ?: null);

        Notification::make()
            ->success()
            ->title('Configuration sauvegardée')
            ->send();

        // Re-check avec les nouveaux paramètres. Le cache est indexé par dépôt : changer de
        // dépôt repart d'un relevé neuf sans qu'on ait à l'invalider.
        $this->refreshVersionInfo(true);
    }

    // -------------------------------------------------------------------------
    // Branche (développement)
    // -------------------------------------------------------------------------

    /** Bouton « Vérifier » : force un relevé neuf, cache chaud ou non. */
    public function checkBranch(): void
    {
        $service = new UpdateService();
        $this->branchInfo = $service->checkBranchUpdates('main', fresh: true);
        $this->deployResult = null;
        $this->cacheBranchBadge();
    }

    public function applyBranchUpdate(): void
    {
        $service = new UpdateService();
        $this->deployResult = $service->applyBranchUpdate();

        if ($this->deployResult['success'] ?? false) {
            // Le déploiement a invalidé le cache : on repart d'un relevé neuf, sinon la page
            // continuerait d'annoncer les commits qu'elle vient d'appliquer.
            $this->refreshVersionInfo(true);
        }
    }

    private function cacheBranchBadge(): void
    {
        $count = $this->branchInfo['ahead_by'] ?? 0;
        Setting::set('update_behind_count', (string) $count);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Setting::get('update_behind_count', '0');

        return $count && $count !== '0' ? $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $count = (int) Setting::get('update_behind_count', '0');

        return $count >= 5 ? 'danger' : 'warning';
    }

    // -------------------------------------------------------------------------
    // Releases (production)
    // -------------------------------------------------------------------------

    /** Bouton « Vérifier les releases » : force un relevé neuf, cache chaud ou non. */
    public function checkUpdate(): void
    {
        $service = new UpdateService();
        $this->updateInfo = $service->checkForUpdates(fresh: true);
        $this->changelog = $service->getChangelog(5, fresh: true);
    }

    public function applyUpdate(): void
    {
        if (! $this->updateInfo || ! ($this->updateInfo['download_url'] ?? null)) {
            return;
        }

        $service = new UpdateService();
        $this->updateResult = $service->applyUpdate($this->updateInfo['download_url']);

        if ($this->updateResult['success'] ?? false) {
            $this->updateInfo['available'] = false;
            Setting::set('update_behind_count', '0');
        }
    }

    /**
     * Faux sur l'image Docker officielle : on masque les boutons d'application et on
     * affiche la marche à suivre (`docker pull`) plutôt que de laisser l'utilisateur
     * lancer un déploiement qui ne peut pas aboutir.
     */
    public function canSelfApply(): bool
    {
        return (new UpdateService())->selfApplyEnabled();
    }

    public function dockerUpdateCommand(): string
    {
        return (new UpdateService())->dockerUpdateInstructions();
    }

    public function getCurrentVersion(): string
    {
        return (new UpdateService())->getCurrentVersion();
    }

    public function getCurrentCommit(): ?string
    {
        return (new UpdateService())->getCurrentCommit();
    }
}
