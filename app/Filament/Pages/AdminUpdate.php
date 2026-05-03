<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\RequiresAdmin;
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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;
    protected static string|UnitEnum|null $navigationGroup = 'Administration';
    protected static ?string $navigationLabel = 'Mises à jour';
    protected static ?string $title = 'Mises à jour';
    protected static ?int $navigationSort = 2;
    protected string $view = 'filament.pages.admin-update';

    // Releases
    public ?array $updateInfo = null;
    public ?array $changelog = null;
    public ?array $updateResult = null;

    // Branche (dev)
    public ?array $branchInfo = null;
    public ?array $deployResult = null;

    // Configuration GitHub
    public string $githubToken = '';
    public string $githubRepo = '';

    public function mount(): void
    {
        $this->githubToken = Setting::get('github_token', '') ?? '';
        $this->githubRepo = Setting::get('github_repo', '') ?? '';
        $this->checkBranch();
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

        // Re-check avec les nouveaux paramètres
        $this->checkBranch();
    }

    // -------------------------------------------------------------------------
    // Branche (développement)
    // -------------------------------------------------------------------------

    public function checkBranch(): void
    {
        $service = new UpdateService();
        $this->branchInfo = $service->checkBranchUpdates();
        $this->deployResult = null;
        $this->cacheBranchBadge();
    }

    public function applyBranchUpdate(): void
    {
        $service = new UpdateService();
        $this->deployResult = $service->applyBranchUpdate();

        if ($this->deployResult['success'] ?? false) {
            $this->branchInfo = $service->checkBranchUpdates();
            $this->cacheBranchBadge();
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

    public function checkUpdate(): void
    {
        $service = new UpdateService();
        $this->updateInfo = $service->checkForUpdates();
        $this->changelog = $service->getChangelog();
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

    public function getCurrentVersion(): string
    {
        return (new UpdateService())->getCurrentVersion();
    }

    public function getCurrentCommit(): ?string
    {
        return (new UpdateService())->getCurrentCommit();
    }
}
