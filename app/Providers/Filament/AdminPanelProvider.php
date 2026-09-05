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
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        // Un segment {propertyId} non numérique (lien cassé, faute de frappe) ne doit
        // jamais matcher : sinon Livewire tente d'hydrater une propriété publique typée
        // ?int avec une chaîne, ce qui lève un TypeError avant même mount() (issue #1).
        // Doit être posé ici, avant que le panel n'enregistre ses routes de ressources
        // (même timing que le pattern `tenant` interne de Filament::Panel::register()).
        Route::pattern('propertyId', '[0-9]+');

        $panel = $panel
            ->default()
            ->id('admin')
            ->path('/')
            ->login()
            ->passwordReset();

        // ALLOW_REGISTRATION : "auto" (défaut, ouverte jusqu'au premier compte),
        // true (toujours ouverte) ou false (toujours fermée). Évalué à chaque
        // requête (boot du panel), donc la porte se referme dès le compte créé.
        if (\App\Support\RegistrationGate::allows()) {
            $panel = $panel->registration();
        }

        return $panel
            ->profile(\App\Filament\Pages\EditProfile::class)
            // Lien légal secondaire : dans le menu de l'avatar, pas en bandeau de page.
            // Sans sort() explicite une Action vaut 0, donc l'item se pose tout seul entre
            // le sélecteur de thème et « Se déconnecter » (profile = -1, logout = PHP_INT_MAX).
            // url() en Closure : la route ne doit pas être résolue au boot du panel.
            ->userMenuItems([
                \Filament\Actions\Action::make('privacy')
                    ->label('Confidentialité')
                    ->icon(\Filament\Support\Icons\Heroicon::ShieldCheck)
                    ->url(fn (): string => route('legal.confidentialite'), shouldOpenInNewTab: true),
            ])
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
            // Jetons de couleur du panel (clair/sombre) — source unique des couleurs des
            // `<style>` scopés des vues. Doit être posé sur TOUTES les pages, y compris
            // celles d'authentification, d'où le hook `head.end` et non un layout.
            ->renderHook(\Filament\View\PanelsRenderHook::HEAD_END, fn () => view('filament.partials.theme-tokens'))
            ->renderHook('panels::sidebar.footer', fn () => view('livewire.nav-mode-toggle-hook'))
            ->renderHook('panels::body.end', fn () => view('livewire.contextual-help-hook'))
            // Invitation à donner son avis. Conditionnée ici plutôt que dans la vue : à
            // `FEEDBACK_ENABLED=false`, le composant n'est même pas monté — une instance
            // auto-hébergée qui l'éteint ne paie pas un aller-retour Livewire par page.
            ->renderHook('panels::body.end', fn () => config('feedback.enabled') ? view('livewire.feedback-prompt-hook') : '')
            // Compte à rebours du bac à sable. Conditionné ici, comme ci-dessus : hors mode
            // démonstration le composant n'est pas monté du tout, et une instance normale ne
            // paie pas un aller-retour Livewire par page pour un compteur qui n'existe pas.
            ->renderHook('panels::body.end', fn () => config('demo.enabled') ? view('livewire.demo-expiry-prompt-hook') : '')
            // Sort des données d'exemple après promotion. NON conditionné par `demo.enabled` :
            // un compte promu n'est plus une démonstration, et il doit pouvoir trancher même
            // si le mode démonstration a été coupé entre-temps sur l'instance.
            ->renderHook('panels::body.end', fn () => view('livewire.demo-seed-choice-hook'))
            ->renderHook(\Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn () => view('filament.auth.demo-button'))
            ->renderHook(\Filament\View\PanelsRenderHook::HEAD_START, fn () => config('services.gtm.id') ? view('partials.gtm-head') : '')
            ->renderHook(\Filament\View\PanelsRenderHook::BODY_START, fn () => config('services.gtm.id') ? view('partials.gtm-body') : '')
            // Pages d'authentification uniquement (login, inscription, mot de passe oublié) :
            // le visiteur doit pouvoir lire la politique AVANT de créer un compte. Une fois
            // connecté, le lien vit dans le menu utilisateur (->userMenuItems() ci-dessus).
            ->renderHook(\Filament\View\PanelsRenderHook::SIMPLE_PAGE_END, fn () => view('filament.partials.privacy-auth-footer-hook'))
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
