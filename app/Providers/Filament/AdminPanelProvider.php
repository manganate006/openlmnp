<?php

namespace App\Providers\Filament;

use App\Livewire\NavModeToggle;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use App\Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->default()
            ->id('admin')
            ->path('/')
            ->login()
            ->passwordReset();

        // Désactivable pour les instances où les comptes sont créés par un
        // administrateur (ALLOW_REGISTRATION=false). Défaut : inscription ouverte.
        if (config('app.allow_registration')) {
            $panel = $panel->registration();
        }

        return $panel
            ->profile(\App\Filament\Pages\EditProfile::class)
            ->brandName('OpenLMNP')
            ->colors([
                'primary' => Color::Emerald,
                'danger' => Color::Rose,
                'warning' => Color::Amber,
                'success' => Color::Green,
                'info' => Color::Sky,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups($this->getNavigationGroups())
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                \App\Filament\Widgets\OnboardingChecklist::class,
                \App\Filament\Widgets\FiscalOverview::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->renderHook('panels::sidebar.footer', fn () => view('livewire.nav-mode-toggle-hook'))
            ->renderHook('panels::body.end', fn () => view('livewire.contextual-help-hook'))
            ->renderHook(\Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn () => view('filament.auth.demo-button'))
            ->renderHook(\Filament\View\PanelsRenderHook::HEAD_START, fn () => config('services.gtm.id') ? view('partials.gtm-head') : '')
            ->renderHook(\Filament\View\PanelsRenderHook::BODY_START, fn () => config('services.gtm.id') ? view('partials.gtm-body') : '')
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    private function getNavigationGroups(): array
    {
        // All groups from all modes in desired order.
        // Filament hides empty groups automatically.
        // Cannot use Auth::user() here — called before authentication at boot.
        return [
            'Mes biens',
            'Mise en route',
            'Mes biens',
            'Comptabilité',
            'Au quotidien',
            'Fiscal',
            'Déclaration annuelle',
            'Outils',
            'Aide',
            'Paramètres',
            'Configuration',
            'Administration',
        ];
    }
}
