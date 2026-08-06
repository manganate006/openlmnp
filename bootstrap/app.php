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

        // En-têtes de sécurité côté app (F8) : défense en profondeur quand l'app est
        // atteinte en direct (sans passer par NPM qui les pose habituellement).
        // Middleware GLOBAL : le panel Filament ne passe pas par le groupe « web »,
        // il faut donc l'enregistrer globalement pour couvrir toutes les réponses.
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('mcp') || $request->is('mcp/*')) {
                return response()->json(['error' => 'Unauthenticated. Provide a valid Bearer token.'], 401);
            }
        });
    })->create();
