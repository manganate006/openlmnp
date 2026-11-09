<?php

namespace App\Providers;

use App\Models\McpPersonalAccessToken;
use App\Models\User;
use App\Support\ConsentState;
use App\Support\VaultSession;
use Filament\Forms\Components\DatePicker;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use App\Models\Property;
use App\Observers\PropertyObserver;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // La clé du coffre-fort est résolue une seule fois par requête, puis
        // mémorisée : sans cette instance partagée, chaque lecture relirait le
        // cookie et referait la vérification d'empreinte.
        $this->app->scoped(VaultSession::class);
    }

    /**
     * Retire du chiffrement le cookie de consentement, posé par le navigateur.
     *
     * ⚠️ POURQUOI. Laravel chiffre tous les cookies, et `EncryptCookies` met à `null` ceux
     * qu'il ne sait pas déchiffrer — silencieusement, sans exception ni journal. Le bandeau
     * écrit son choix en JavaScript, donc en clair, parce que le Consent Mode doit être
     * initialisé AVANT le conteneur GTM, sans attendre d'aller-retour serveur. Sans cette
     * exception, le serveur relirait ce cookie comme illisible, donc comme « pas de
     * réponse » : le bandeau reparaîtrait à chaque page, sans qu'aucune erreur ne soit levée.
     *
     * ⚠️ Déclaré ici plutôt que dans un middleware parce que l'application a DEUX piles :
     * le panel Filament ne passe pas par le groupe `web`, alors que `/d/{path}` si. C'est le
     * même piège que celui documenté pour `VaultSession`. La liste statique
     * d'`EncryptCookies`, elle, vaut pour les deux.
     */
    public static function exemptThirdPartyCookiesFromEncryption(): void
    {
        EncryptCookies::except([ConsentState::COOKIE]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        self::exemptThirdPartyCookiesFromEncryption();

        // Lot 5 des dossiers liés : le modèle de jeton Sanctum porte en plus
        // `dossier_user_id` (voir App\Models\McpPersonalAccessToken). Enregistré
        // ici pour que `createToken()`, `findToken()` etc. hydratent bien CE
        // modèle, lu ensuite par App\Http\Middleware\McpGuard.
        Sanctum::usePersonalAccessTokenModel(McpPersonalAccessToken::class);

        // Les composants ventilés en pourcentage suivent la valeur du bien (issue #11).
        Property::observe(PropertyObserver::class);

        // Dates : saisie ET affichage au format français partout (issue #6).
        //
        // ⚠️ `DatePicker` est NATIF par défaut (`CanBeNative::$isNative = true`) : il rend un
        // `<input type="date">` qui IGNORE `displayFormat()` et suit la locale du navigateur.
        // Les 16 champs de l'app déclaraient bien `->displayFormat('d/m/Y')` sans aucun effet :
        // la saisie partait en ISO alors que les colonnes des tables affichaient `d/m/Y`.
        // `native(false)` bascule sur le sélecteur JS de Filament, qui l'honore.
        // Configuré ici une fois pour toutes : un `->native(false)` par champ se réoublierait
        // au prochain formulaire. Une chaîne locale peut toujours surcharger ces valeurs.
        DatePicker::configureUsing(fn (DatePicker $picker) => $picker
            ->native(false)
            ->displayFormat('d/m/Y')
            ->firstDayOfWeek(1)
            ->closeOnDateSelection());

        // First registered user automatically becomes admin
        User::creating(function (User $user) {
            if (DB::table('users')->count() === 0) {
                $user->is_admin = true;
            }
        });

        // Queue analytics events (GA4 via GTM) for the next rendered page.
        // The queue survives the post-auth redirect and is consumed by
        // partials/gtm-head, which is only rendered when GTM is configured.
        Event::listen(Registered::class, function () {
            self::queueAnalyticsEvent([
                'event' => 'sign_up',
                'method' => 'email',
                // Drapeau posé par la route /demo : mesure la conversion
                // démo → inscription (le compte sandbox est un autre user).
                'from_demo' => request()->hasCookie('olmnp_demo_seen'),
            ]);
        });
        Event::listen(Login::class, function (Login $event) {
            self::queueAnalyticsEvent([
                'event' => 'login',
                'method' => ($event->user->is_demo ?? false) ? 'demo' : 'email',
            ]);
        });

        // Le coffre-fort se referme à la déconnexion : le cookie porteur de la clé
        // est effacé. Le même événement est levé par AuthenticateSession quand la
        // session est invalidée ailleurs, ce qui referme aussi les autres onglets.
        //
        // ⚠️ `$event->user` (PAS `Auth::user()`) : `SessionGuard::logout()` lève cet
        // événement AVANT de mettre `$this->user` à `null`, mais seul le payload de
        // l'événement garantit de viser le BON cookie depuis le lot 3 des dossiers
        // liés (un cookie par utilisateur, voir App\Support\VaultSession) — se fier
        // au garde authentifié courant resterait correct ici, mais le payload de
        // l'événement est la source explicite, documentée par Laravel pour ça.
        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->user instanceof User) {
                app(VaultSession::class)->lock($event->user);
            }
        });
        // Conversion HEIC → JPEG dans le navigateur (photos iPhone) : le script
        // d'accroche est chargé sur toutes les pages du panel (quelques Ko), la
        // bibliothèque heic2any (WebAssembly, ~1,3 Mo) uniquement à la demande,
        // quand un fichier HEIC est déposé. Rien n'est décodé côté serveur.
        FilamentAsset::register([
            Js::make('heic-upload', resource_path('js/heic-upload.js')),
            Js::make('heic2any', resource_path('js/lib/heic2any/heic2any.min.js'))->loadedOnRequest(),
        ], package: 'openlmnp');
        FilamentAsset::registerScriptData([
            'heic2anySrc' => FilamentAsset::getScriptSrc('heic2any', 'openlmnp'),
        ], package: 'openlmnp');

        // Allow private disk to generate temporary signed URLs for file previews
        Storage::disk('local')->buildTemporaryUrlsUsing(
            fn (string $path, \DateTimeInterface $expiration) => URL::temporarySignedRoute(
                'documents.show',
                $expiration,
                ['path' => $path],
            ),
        );
    }

    /**
     * Empile un événement analytics dans la session (flash) sans écraser
     * ceux déjà en attente — inscription + connexion arrivent dans la même requête.
     */
    public static function queueAnalyticsEvent(array $payload): void
    {
        $events = (array) session()->get('analytics', []);
        $events[] = $payload;
        session()->flash('analytics', $events);
    }
}
