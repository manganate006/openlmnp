<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\RequiresAdmin;
use App\Services\UpdateService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class AdminUpdate extends Page
{
    use RequiresAdmin;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;
    protected static string | UnitEnum | null $navigationGroup = 'Administration';
    protected static ?string $navigationLabel = 'Mises à jour';
    protected static ?string $title = 'Mises à jour';
    protected static ?int $navigationSort = 2;
    protected string $view = 'filament.pages.admin-update';

    public ?array $updateInfo = null;
    public ?array $changelog = null;
    public ?array $updateResult = null;
    public bool $checking = false;
    public bool $updating = false;

    public function mount(): void
    {
        $this->checkUpdate();
    }

    public function checkUpdate(): void
    {
        $this->checking = true;
        $service = new UpdateService();
        $this->updateInfo = $service->checkForUpdates();
        $this->changelog = $service->getChangelog();
        $this->checking = false;
    }

    public function applyUpdate(): void
    {
        if (! $this->updateInfo || ! ($this->updateInfo['download_url'] ?? null)) {
            return;
        }

        $this->updating = true;
        $service = new UpdateService();
        $this->updateResult = $service->applyUpdate($this->updateInfo['download_url']);
        $this->updating = false;

        if ($this->updateResult['success'] ?? false) {
            $this->updateInfo['available'] = false;
        }
    }

    public function getCurrentVersion(): string
    {
        return (new UpdateService())->getCurrentVersion();
    }
}
