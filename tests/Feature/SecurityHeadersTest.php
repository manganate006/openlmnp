<?php

// F8 — en-têtes de sécurité posés côté application (défense en profondeur).
// GHSA-j4gm-g8m8-93x2, point 20 — CSP, HSTS seulement en HTTPS, cookie de session Secure.

use App\Support\ContentSecurityPolicy;
use Symfony\Component\HttpFoundation\Cookie;

function sessionCookie(\Illuminate\Testing\TestResponse $response): ?Cookie
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === config('session.cookie')) {
            return $cookie;
        }
    }

    return null;
}

/** @return array<string, string> directive => sources */
function cspDirectives(string $policy): array
{
    $directives = [];
    foreach (array_filter(array_map('trim', explode(';', $policy))) as $directive) {
        [$name, $sources] = array_pad(explode(' ', $directive, 2), 2, '');
        $directives[$name] = $sources;
    }

    return $directives;
}

it('sets security headers on web responses', function () {
    $response = $this->get('/login');

    $response->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    expect($response->headers->get('Permissions-Policy'))->toContain('geolocation=()');
});

it('pose une CSP appliquée sur la page de connexion, compatible avec le panel', function () {
    $response = $this->get('/login');

    $response->assertOk();
    expect($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();

    $csp = cspDirectives((string) $response->headers->get('Content-Security-Policy'));

    // Ce qu'elle protège.
    expect($csp['default-src'])->toBe("'self'")
        ->and($csp['object-src'])->toBe("'none'")
        ->and($csp['base-uri'])->toBe("'self'")
        ->and($csp['form-action'])->toBe("'self'")
        ->and($csp['frame-ancestors'])->toBe("'self'")
        ->and($csp['connect-src'])->toBe("'self'");

    // Ce dont le panel a besoin : Alpine standard (new Function) et scripts/styles en ligne
    // de Filament et Livewire, Chart.js depuis jsDelivr.
    expect($csp['script-src'])->toContain("'self'")
        ->toContain("'unsafe-inline'")
        ->toContain("'unsafe-eval'")
        ->toContain('https://cdn.jsdelivr.net')
        ->and($csp['style-src'])->toContain("'unsafe-inline'")
        ->and($csp['img-src'])->toContain('blob:')->toContain('data:');

    // Aucune origine Google sur une instance sans GTM.
    expect($response->headers->get('Content-Security-Policy'))->not->toContain('google');
});

it('autorise GTM et son serveur dans la CSP quand GTM_CONTAINER_ID est réglé', function () {
    config([
        'services.gtm.id' => 'GTM-TEST',
        'services.gtm.server_url' => 'https://sgtm.example.test/',
    ]);

    $csp = cspDirectives(ContentSecurityPolicy::policy());

    expect($csp['script-src'])->toContain('https://sgtm.example.test')->toContain('https://www.googletagmanager.com')
        ->and($csp['connect-src'])->toContain('https://sgtm.example.test')->toContain('https://*.google-analytics.com');
});

it('autorise en cadre les offres de fin de démo et de recueil d\'avis', function () {
    config(['demo.links.pro' => 'https://offre.example.test/abonnement?x=1']);

    expect(cspDirectives(ContentSecurityPolicy::policy())['frame-src'])->toContain('https://offre.example.test');
});

it('ajoute les origines de CSP_EXTRA_SOURCES, sans laisser passer de mot-clé ni de directive', function () {
    config(['security.csp.extra_sources' => "https://stats.example.test, 'unsafe-hashes' https://x.test;script-src *"]);

    $policy = ContentSecurityPolicy::policy();
    $csp = cspDirectives($policy);

    expect($csp['script-src'])->toContain('https://stats.example.test')
        ->and($csp['connect-src'])->toContain('https://stats.example.test')
        ->and($policy)->not->toContain('unsafe-hashes')
        ->and($policy)->not->toContain('x.test;')
        ->and(substr_count($policy, 'script-src'))->toBe(1);
});

it('passe la CSP en mode rapport, la remplace ou la coupe par configuration', function () {
    config(['security.csp.report_only' => true]);
    $response = $this->get('/login');
    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($response->headers->get('Content-Security-Policy-Report-Only'))->toContain("default-src 'self'");

    config(['security.csp.report_only' => false, 'security.csp.policy' => "default-src 'self' https:"]);
    expect($this->get('/login')->headers->get('Content-Security-Policy'))->toBe("default-src 'self' https:");

    config(['security.csp.enabled' => false]);
    $response = $this->get('/login');
    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
});

it('ne remplace pas la politique propre d\'une réponse', function () {
    \Illuminate\Support\Facades\Route::get('/__csp-own', fn () => response('x', 200, [
        'Content-Security-Policy' => "default-src 'none'",
    ]));

    expect($this->get('/__csp-own')->headers->get('Content-Security-Policy'))->toBe("default-src 'none'");
});

it('pose HSTS et un cookie de session Secure sur une requête HTTPS', function () {
    config(['session.secure' => null]);

    $response = $this->get('https://localhost/login');

    expect($response->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000')
        ->and(sessionCookie($response)?->isSecure())->toBeTrue();
});

it('reconnaît le HTTPS annoncé par le proxy de confiance (NPMplus)', function () {
    config(['session.secure' => null]);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'])
        ->get('http://localhost/login');

    expect($response->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000')
        ->and(sessionCookie($response)?->isSecure())->toBeTrue();
});

it('ne croit pas un X-Forwarded-Proto venu d\'une adresse publique', function () {
    config(['session.secure' => null]);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->get('http://localhost/login');

    expect($response->headers->has('Strict-Transport-Security'))->toBeFalse()
        ->and(sessionCookie($response)?->isSecure())->toBeFalse();
});

it('ne pose ni HSTS ni cookie Secure sur une instance du réseau local servie en HTTP', function () {
    config(['session.secure' => null, 'app.url' => 'http://192.168.1.50:8090']);

    $response = $this->get('http://192.168.1.50/login');

    expect($response->headers->has('Strict-Transport-Security'))->toBeFalse()
        ->and(sessionCookie($response))->not->toBeNull()
        ->and(sessionCookie($response)->isSecure())->toBeFalse();
});

it('rend le cookie Secure sur le nom d\'hôte d\'un APP_URL en https, pas sur une autre adresse', function () {
    config(['session.secure' => null, 'app.url' => 'https://lmnp.example.test']);

    expect(sessionCookie($this->get('http://lmnp.example.test/login'))?->isSecure())->toBeTrue();

    config(['session.secure' => null]);
    expect(sessionCookie($this->get('http://localhost/login'))?->isSecure())->toBeFalse();
});

it('laisse SESSION_SECURE_COOKIE l\'emporter', function () {
    config(['session.secure' => false]);
    expect(sessionCookie($this->get('https://localhost/login'))?->isSecure())->toBeFalse();

    config(['session.secure' => true]);
    expect(sessionCookie($this->get('http://localhost/login'))?->isSecure())->toBeTrue();
});

it('coupe HSTS avec HSTS_MAX_AGE=0', function () {
    config(['security.hsts_max_age' => 0]);

    expect($this->get('https://localhost/login')->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('propage chaque réglage d\'en-têtes par l\'allowlist de l\'entrypoint Docker', function () {
    preg_match_all("/env\(\s*'([A-Z0-9_]+)'/", file_get_contents(config_path('security.php')), $matches);
    $variables = [...array_unique($matches[1]), 'SESSION_SECURE_COOKIE'];

    preg_match('/for var in (.*?); do/s', file_get_contents(base_path('docker-entrypoint.sh')), $loop);
    $allowlist = preg_split('/[\s\\\\]+/', $loop[1], -1, PREG_SPLIT_NO_EMPTY);

    expect($variables)->toContain('CSP_ENABLED')
        ->and(array_values(array_diff($variables, $allowlist)))->toBe([]);
});
