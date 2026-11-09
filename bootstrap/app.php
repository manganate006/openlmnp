<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            if (file_exists(base_path('routes/mcp.php'))) {
                require base_path('routes/mcp.php');
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Où renvoyer un visiteur non connecté. Sans cette ligne, Laravel cherche une route
        // « login » que l'application n'a pas : /mcp sans jeton ni Accept JSON répondait 500
        // au lieu de 401 (GHSA-j4gm-g8m8-93x2, point 21). Pour /mcp, pas de redirection : le
        // rendu de l'AuthenticationException plus bas répond en 401 JSON.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('mcp', 'mcp/*')
            ? null
            : (\Illuminate\Support\Facades\Route::has('filament.admin.auth.login') ? route('filament.admin.auth.login') : '/'));

        // Proxies de confiance (F7) : par défaut les plages privées + loopback — le
        // reverse proxy (NPM) est sur le LAN, on ne fait plus confiance à un proxy
        // arbitraire d'Internet pour les en-têtes X-Forwarded-*. Surchargeable via
        // TRUSTED_PROXIES (liste CSV, ou '*' pour restaurer l'ancien comportement).
        // Durcissement complet = régler TRUSTED_PROXIES sur l'IP exacte du proxy.
        $proxies = trim((string) env('TRUSTED_PROXIES', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1,::1'));
        $middleware->trustProxies(
            at: $proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        // Hôtes acceptés : celui d'APP_URL et ses sous-domaines, localhost, et TRUSTED_HOSTS
        // (CSV, `*` pour couper le filtre). Sans filtre, un en-tête Host forgé se retrouve
        // dans le lien du mail de réinitialisation. Inactif si APP_URL est localhost ou une
        // IP : voir App\Support\TrustedHosts.
        $middleware->trustHosts(
            at: fn () => \App\Support\TrustedHosts::patterns(config('app.url'), config('app.trusted_hosts')),
        );

        // En-têtes de sécurité côté app (F8) : défense en profondeur quand l'app est
        // atteinte en direct (sans passer par NPM qui les pose habituellement).
        // Middleware GLOBAL : le panel Filament ne passe pas par le groupe « web »,
        // il faut donc l'enregistrer globalement pour couvrir toutes les réponses.
        $middleware->append(SecurityHeaders::class);

        // Le middleware `auth` générique redirige un visiteur non connecté vers
        // route('login'), nom que ce projet n'expose pas : Filament nomme la sienne
        // `filament.admin.auth.login`. Sans cette ligne, /adresse/recherche et
        // /vault/key-material ouverts dans un navigateur rendaient une erreur 500.
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));

        // ⚠️ Laravel RÉORDONNE les middlewares selon sa liste de priorité interne
        // (`Illuminate\Routing\SortedMiddleware`), SANS ÉGARD pour l'ordre d'appel
        // d'AdminPanelProvider::authMiddleware() — vérifié : un EnsureDossierContext
        // déclaré AVANT Filament\Http\Middleware\Authenticate dans le panel se
        // retrouvait quand même APRÈS lui dans la pile réellement exécutée.
        //
        // ⚠️ ET la classe LITTÉRALE `Filament\Http\Middleware\Authenticate` n'apparaît
        // jamais dans la liste de priorité par défaut de Laravel — seule l'INTERFACE
        // qu'elle implémente (`AuthenticatesRequests`) y figure, et c'est PAR ELLE que
        // `SortedMiddleware` la repère (`class_implements()` sur la classe concrète).
        // `prependToPriorityList(before: Authenticate::class, ...)` est donc un NO-OP
        // silencieux (l'ancre recherchée n'existe pas dans la liste, l'insertion
        // retombe en fin de liste) : il faut viser l'INTERFACE, pas la classe.
        //
        // Sans cette ligne, `canAccessPanel()` (donc Authenticate, qui répond un 403
        // sec) jugeait un dossier lié invalide AVANT qu'EnsureDossierContext n'ait eu
        // la main pour ramener la session à son principal ou la clore proprement.
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \App\Http\Middleware\EnsureDossierContext::class,
        );

        // Même piège, même remède, pour le correctif du « vieil onglet » (voir
        // App\Http\Middleware\VerifyDossierContext et App\Support\DossierContext) :
        // sans cette ligne, VerifyDossierContext resterait soumis au même
        // réordonnancement imprévisible que n'importe quel middleware absent de la
        // liste de priorité de Laravel. Son fonctionnement ne dépend pas de l'ordre
        // exact avec Authenticate (il ne consulte que la session et l'utilisateur
        // déjà résolu), mais le fixer explicitement évite de devoir le redémontrer
        // à chaque nouvelle version de Laravel qui changerait cette liste interne.
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \App\Http\Middleware\VerifyDossierContext::class,
        );

        // Déconnexion intermittente en deux onglets (test bêta du 2026-10-15, voir
        // App\Http\Middleware\GuardReplacedSession) : une session remplacée par une bascule
        // ne doit jamais renvoyer son cookie. Les deux moitiés encadrent StartSession, sur le
        // panel (AdminPanelProvider::panel()) comme sur le groupe `web`, qui porte la route de
        // mise à jour de Livewire. Leur place exacte est fixée par la liste de priorité, pour la
        // même raison que ci-dessus, et vérifiée par SessionRemplaceeTest.
        $middleware->web(
            prepend: [\App\Http\Middleware\StripReplacedSessionCookie::class],
            append: [\App\Http\Middleware\GuardReplacedSession::class],
        );
        $middleware->prependToPriorityList(
            before: \Illuminate\Session\Middleware\StartSession::class,
            prepend: \App\Http\Middleware\StripReplacedSessionCookie::class,
        );
        $middleware->appendToPriorityList(
            after: \Illuminate\Session\Middleware\StartSession::class,
            append: \App\Http\Middleware\GuardReplacedSession::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Une phrase de passe de coffre-fort ne doit JAMAIS être renvoyée dans la
        // session en « ancienne saisie » : le pilote de session écrit en clair sur
        // le disque, précisément celui dont le coffre protège l'utilisateur.
        $exceptions->dontFlash([
            'passphrase',
            'passphrase_confirmation',
            'current_passphrase',
            'new_passphrase',
        ]);


        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('mcp') || $request->is('mcp/*')) {
                return response()->json(['error' => 'Unauthenticated. Provide a valid Bearer token.'], 401);
            }
        });
    })->create();
