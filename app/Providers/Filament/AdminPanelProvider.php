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
        // "invite" (sur invitation seulement, une fois le premier compte créé),
        // true (toujours ouverte) ou false (toujours fermée). Évalué à chaque
        // requête (boot du panel), donc la porte se referme dès le compte créé.
        //
        // ⚠️ `exposesRoute()` et non `allows()` : en mode invitation, l'inscription
        // LIBRE est fermée mais la route doit exister — c'est là qu'atterrit le
        // porteur d'un lien. Les confondre rendrait 404 tous les liens émis, sans
        // le moindre message.
        if (\App\Support\RegistrationGate::exposesRoute()) {
            $panel = $panel->registration(\App\Filament\Auth\RegisterFromInvitation::class);
        } else {
            // Porte fermée : Filament ne déclare sa route `register` QUE si
            // hasRegistration(), donc /register n'existe plus du tout et Laravel rend un
            // 404 nu — un prospect qui tape l'adresse n'a aucun recours (constaté au
            // support). On repose la même URI sur un contrôleur de repli, qui emmène vers
            // la page de souscription si l'instance en déclare une (OPENLMNP_SIGNUP_URL)
            // et vers /login avec une explication sinon.
            //
            // ICI et pas dans routes/web.php, pour deux raisons :
            //  1. RegistrationGate::exposesRoute() vient d’être évalué à la ligne du dessus. En
            //     mode « auto » c'est une requête SQL : la reposer dans routes/web.php la
            //     paierait une seconde fois À CHAQUE requête de l'application, pas
            //     seulement sur /register.
            //  2. routes/web.php est chargé APRÈS les routes du panel (le
            //     RouteServiceProvider de l'application est enregistré dans un callback
            //     `booting`, donc booté en dernier) et Laravel indexe ses routes par URI :
            //     une /register posée là-bas ÉCRASERAIT la vraie page d'inscription si la
            //     porte venait à se rouvrir. Les fermetures passées à ->routes() sont au
            //     contraire jouées AVANT le bloc d'authentification de Filament — si les
            //     deux existaient, c'est la vraie page qui gagnerait.
            $panel = $panel->routes(static fn (Panel $panel) => Route::get(
                $panel->getRegistrationRouteSlug(),
                \App\Http\Controllers\SignupRedirectController::class,
            )->name('registration-redirect'));
        }

        return $panel
            ->profile(\App\Filament\Pages\EditProfile::class)
            /*
             * Aller-retour OAuth « Se connecter avec OpenRouter ».
             *
             * Déclaré ICI et non dans routes/web.php : le panel est monté à la
             * racine et ne passe pas par le groupe `web`. Une route protégée par
             * le middleware `auth` générique redirigerait vers route('login'),
             * nom que ce projet n'expose pas — Filament nomme la sienne
             * `filament.admin.auth.login`. Par authenticatedRoutes(), ces deux
             * routes héritent du middleware du panel ET de son garde, qui
             * conserve l'URL demandée et refuse déjà un compte suspendu.
             */
            ->authenticatedRoutes(function (): void {
                Route::prefix('ia/openrouter')
                    ->name('ai.openrouter.')
                    ->group(function (): void {
                        Route::get('connexion', [\App\Http\Controllers\Ai\OpenRouterOauthController::class, 'start'])
                            ->middleware('throttle:10,1')
                            ->name('start');

                        Route::get('retour', [\App\Http\Controllers\Ai\OpenRouterOauthController::class, 'callback'])
                            ->middleware('throttle:20,1')
                            ->name('callback');
                    });

                // Bascule vers un dossier lié / retour au principal (App\Support\DossierContext).
                // POST STRICTEMENT : une bascule en GET serait suivie par n'importe quel lien
                // ou prérendu de navigateur.
                //
                // ⚠️ Routes TOUJOURS enregistrées, à la différence de RegistrationGate : les
                // routes d'un PanelProvider sont enregistrées une seule fois, AU BOOT de
                // l'application (Filament::bootPanels()) — un `config('dossiers.enabled')`
                // évalué ICI ne verrait donc jamais un changement fait après coup (`config:cache`
                // mis à part, c'est aussi ce qui rend ce genre de garde invérifiable en test,
                // où `config()` se change librement PENDANT le test). Coupée au bon endroit :
                // dans chaque contrôleur (`abort_unless(config('dossiers.enabled'), 404)`),
                // comme les routes OAuth OpenRouter ci-dessus le font déjà pour `ai.oauth_enabled`.
                Route::prefix('dossiers')
                    ->name('dossiers.')
                    ->group(function (): void {
                        Route::post('{dossier}/ouvrir', \App\Http\Controllers\Dossier\OpenDossierController::class)
                            ->name('open');

                        Route::post('revenir', \App\Http\Controllers\Dossier\LeaveDossierController::class)
                            ->name('leave');
                    });
            })
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
            // Logo repris de la vitrine : même maison blanche sur carré émeraude.
            // `brandName` est conservé — il sert encore aux endroits qui n'affichent
            // pas de logo (titre de page, courriels, accessibilité).
            ->brandLogo(fn () => view('filament.partials.brand'))
            ->brandLogoHeight('2rem')
            // Sans déclaration, le navigateur demande /favicon.ico d'office (#18). En Closure :
            // `asset()` évalué au démarrage du panel ignore le schéma de la requête et
            // sortait `http://` derrière le proxy HTTPS (constaté sur la bêta le 2026-10-24).
            ->favicon(fn (): string => asset('favicon.svg'))
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
                // Session remplacée par une bascule dans un autre onglet : voir
                // App\Http\Middleware\GuardReplacedSession (place fixée dans bootstrap/app.php).
                \App\Http\Middleware\StripReplacedSessionCookie::class,
                StartSession::class,
                \App\Http\Middleware\GuardReplacedSession::class,
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
            // Vieil onglet (App\Support\DossierContext, App\Http\Middleware\VerifyDossierContext) :
            // balise <meta> + hook JS Livewire posant l'en-tête X-OpenLMNP-Context sur chaque
            // requête. Posé pour TOUT utilisateur authentifié (pas seulement en dossier lié) :
            // la vue se rend vide sinon, à coût quasi nul pour une instance qui n'utilise jamais
            // les dossiers liés (aucune bascule n'y change jamais l'identité en session, donc le
            // middleware ne refuse jamais rien — mais le laisser toujours actif évite un angle
            // mort si un futur mécanisme vient, lui aussi, changer l'identité en session).
            ->renderHook(\Filament\View\PanelsRenderHook::HEAD_END, fn () => view('filament.partials.dossier-context-meta'))
            ->renderHook('panels::sidebar.footer', fn () => view('livewire.nav-mode-toggle-hook'))
            // Sélecteur de dossier (menu utilisateur) et bandeau permanent en dossier lié —
            // tous deux coupés avec le reste de la fonctionnalité (LINKED_DOSSIERS_ENABLED).
            //
            // Écran 6, variante B (+ rappel de la variante C, 2026-10-15) : dans un dossier
            // lié, l'interface doit être IMPOSSIBLE à confondre avec le dossier personnel —
            // bandeau plein (BODY_START), carte d'identité en tête de barre latérale
            // (SIDEBAR_START, `dossier-banner.blade.php` rendue une seconde fois avec
            // `slot: 'sidebar'` — même vue, deux emplacements, pour ne garder qu'un seul
            // fichier qui sait « on est dans un dossier lié ») et rappel sur la barre
            // d'actions des formulaires (BODY_END, repris de la variante C : aucun render
            // hook Filament ne cible spécifiquement « la barre d'actions de CE formulaire »,
            // donc ce rappel est un bandeau sticky en bas de l'écran plutôt qu'un texte
            // inséré à côté des boutons Annuler/Créer — écart assumé par rapport à la
            // maquette, qui le montre juxtaposé).
            ->renderHook(\Filament\View\PanelsRenderHook::USER_MENU_BEFORE, fn () => config('dossiers.enabled') ? view('filament.partials.dossier-selector') : '')
            ->renderHook(\Filament\View\PanelsRenderHook::BODY_START, fn () => config('dossiers.enabled') ? view('filament.partials.dossier-banner', ['slot' => 'topbar']) : '')
            ->renderHook(\Filament\View\PanelsRenderHook::SIDEBAR_START, fn () => config('dossiers.enabled') ? view('filament.partials.dossier-banner', ['slot' => 'sidebar']) : '')
            ->renderHook('panels::body.end', fn () => config('dossiers.enabled') ? view('filament.partials.dossier-banner', ['slot' => 'form-reminder']) : '')
            ->renderHook('panels::body.end', fn () => view('livewire.contextual-help-hook'))
            // Invitation à donner son avis. Conditionnée ici plutôt que dans la vue : à
            // `FEEDBACK_ENABLED=false`, le composant n'est même pas monté — une instance
            // auto-hébergée qui l'éteint ne paie pas un aller-retour Livewire par page.
            ->renderHook('panels::body.end', fn () => config('feedback.enabled') ? view('livewire.feedback-prompt-hook') : '')
            // Compte à rebours du bac à sable. Conditionné ici, comme ci-dessus : hors mode
            // démonstration le composant n'est pas monté du tout, et une instance normale ne
            // paie pas un aller-retour Livewire par page pour un compteur qui n'existe pas.
            ->renderHook('panels::body.end', fn () => config('demo.enabled') ? view('livewire.demo-expiry-prompt-hook') : '')
            // Déverrouillage du coffre-fort dans le navigateur. La vue se rend
            // vide si le coffre n'est pas activé sur le compte, et le module
            // libsodium (~730 Ko) n'est chargé qu'au premier clic sur un cadenas.
            ->renderHook('panels::body.end', fn () => config('vault.enabled') ? view('livewire.vault-unlock-hook') : '')
            // Sort des données d'exemple après promotion. NON conditionné par `demo.enabled` :
            // un compte promu n'est plus une démonstration, et il doit pouvoir trancher même
            // si le mode démonstration a été coupé entre-temps sur l'instance.
            ->renderHook('panels::body.end', fn () => view('livewire.demo-seed-choice-hook'))
            // Autocomplétion d'adresse (Base Adresse Nationale). Posée ici plutôt que dans
            // chacun des trois formulaires qui portent une adresse : un branchement par
            // écran se périmerait écran par écran, et il en manquerait un au prochain ajout.
            // La vue se rend VIDE si `ADDRESS_LOOKUP_ENABLED=false` — aucun script, donc
            // aucun appel sortant possible.
            ->renderHook('panels::body.end', fn () => view('filament.partials.address-autocomplete'))

            // Explication affichée à qui arrive de /register sur une instance dont
            // l'inscription est fermée sans page de souscription (cf.
            // SignupRedirectController). AVANT le formulaire : après, personne ne la verrait.
            ->renderHook(\Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, fn () => view('filament.auth.registration-closed-notice'))

            // Avis de sécurité reçus par le check-in de télémétrie : administrateurs seulement,
            // et seulement s'ils concernent la version installée (le bandeau disparaît après
            // la mise à jour). Le test d'admin passe AVANT la lecture en base : un compte
            // ordinaire ne paie aucune requête.
            ->renderHook(\Filament\View\PanelsRenderHook::CONTENT_START, function () {
                if (! auth()->user()?->isAdmin()) {
                    return '';
                }

                $advisories = (new \App\Services\SecurityAdvisoryService())->applicable();

                if ($advisories === []) {
                    return '';
                }

                return view('filament.partials.security-advisories-banner', [
                    'advisories' => $advisories,
                    'updateCommand' => (new \App\Services\UpdateService())->dockerUpdateInstructions(),
                    'updateUrl' => \App\Filament\Pages\AdminUpdate::getUrl(),
                ]);
            })
            ->renderHook(\Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn () => view('filament.auth.demo-button'))
            ->renderHook(\Filament\View\PanelsRenderHook::HEAD_START, fn () => config('services.gtm.id') ? view('partials.gtm-head') : '')
            ->renderHook(\Filament\View\PanelsRenderHook::BODY_START, fn () => config('services.gtm.id') ? view('partials.gtm-body') : '')
            // Bandeau de consentement, sous LA MÊME condition que le conteneur ci-dessus :
            // une instance auto-hébergée ne charge aucun traceur, donc ne pose aucune
            // question. Les deux portes doivent rester la même — en poser une sans l'autre,
            // c'est soit mesurer sans demander, soit demander sans mesurer.
            ->renderHook('panels::body.end', fn () => config('services.gtm.id') ? view('partials.consent-banner') : '')
            // Pages d'authentification uniquement (login, inscription, mot de passe oublié) :
            // le visiteur doit pouvoir lire la politique AVANT de créer un compte. Une fois
            // connecté, le lien vit dans le menu utilisateur (->userMenuItems() ci-dessus).
            ->renderHook(\Filament\View\PanelsRenderHook::SIMPLE_PAGE_END, fn () => view('filament.partials.privacy-auth-footer-hook'))
            // ⚠️ ORDRE : EnsureDossierContext AVANT Authenticate. `canAccessPanel()` du
            // User d'un dossier invalide (principal suspendu, mot de passe changé
            // ailleurs...) rend déjà `false`, et Authenticate répondrait alors un 403 sec
            // — sans jamais laisser EnsureDossierContext ramener la session au principal
            // ou la clore proprement. Posé ICI, il corrige l'identité AVANT que
            // Authenticate ne la juge. Revalidé à CHAQUE mise à jour Livewire
            // (isPersistent), pas seulement au chargement d'une page : un membre révoqué
            // en cours de session ne doit pas pouvoir continuer à saisir par de simples
            // requêtes AJAX du composant déjà monté.
            ->authMiddleware([
                \App\Http\Middleware\EnsureDossierContext::class,
                // Vieil onglet (voir App\Support\DossierContext et
                // App\Http\Middleware\VerifyDossierContext) : refuse, par un 419, toute
                // écriture (Livewire ou POST du panel) dont l'identifiant de contexte ne
                // correspond plus à la session courante — le symptôme exact du bug du
                // 2026-10-15 (un bien enregistré dans le mauvais dossier, sans erreur).
                \App\Http\Middleware\VerifyDossierContext::class,
            ], isPersistent: true)
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
