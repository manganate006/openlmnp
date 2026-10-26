<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\NavigationAware;
use App\Filament\Pages\Concerns\ShowsVersionInfo;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Version installée et notes des dernières versions — pour TOUS les utilisateurs.
 *
 * Ces informations vivaient uniquement sur `AdminUpdate`, réservée aux administrateurs :
 * un utilisateur du cloud ne pouvait ni savoir quelle version il utilisait, ni lire ce qui
 * avait changé. Appliquer une mise à jour, en revanche, reste une action d'administration.
 */
class WhatsNew extends Page
{
    use NavigationAware;
    use ShowsVersionInfo;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;
    protected static string|UnitEnum|null $navigationGroup = null;
    protected static ?string $navigationLabel = 'Nouveautés';
    protected static ?string $title = 'Version et nouveautés';
    protected static ?int $navigationSort = 97;
    protected static ?string $slug = 'nouveautes';
    protected string $view = 'filament.pages.whats-new';

    public function mount(): void
    {
        $this->mountVersionInfo();
    }

    /** L'utilisateur peut-il, en plus de lire, agir sur les mises à jour ? */
    public function canManageUpdates(): bool
    {
        return AdminUpdate::canAccess();
    }

    /**
     * Carte « Nouveauté » (maquette écran 8, variante B) : mémorisée PAR VERSION, pas par un
     * simple booléen — sinon, une fois fermée, elle resterait masquée pour toujours, y compris
     * à la prochaine release qui aurait quelque chose à annoncer. Même patron que
     * `onboarding_dismissed_at` (`AlertBanner`/`OnboardingChecklist`).
     */
    public function showsNoveltyCard(): bool
    {
        return auth()->user()->whats_new_dismissed_version !== $this->currentVersion();
    }

    public function dismissNoveltyCard(): void
    {
        auth()->user()->update(['whats_new_dismissed_version' => $this->currentVersion()]);
    }

    /** Les dossiers liés sont-ils actifs sur cette instance ? La carte adapte son contenu. */
    public function linkedDossiersEnabled(): bool
    {
        return (bool) config('dossiers.enabled');
    }
}
