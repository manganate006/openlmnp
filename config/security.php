<?php

/*
|--------------------------------------------------------------------------
| En-têtes de sécurité HTTP (GHSA-j4gm-g8m8-93x2, point 20)
|--------------------------------------------------------------------------
|
| Lus par App\Http\Middleware\SecurityHeaders. ⚠️ Toute variable ajoutée ici doit
| l'être aussi à l'allowlist de docker-entrypoint.sh : tests/Feature/SecurityHeadersTest.php
| relit les env() de ce fichier et échoue sinon.
|
*/

return [

    'csp' => [
        // Coupe la politique de sécurité du contenu (CSP_ENABLED=false) : échappatoire d'une
        // instance où un ajout local (extension, traceur) serait bloqué.
        'enabled' => (bool) env('CSP_ENABLED', true),

        // true : en-tête Content-Security-Policy-Report-Only. Le navigateur ne bloque
        // rien et signale dans sa console ce qu'il aurait refusé — pour calibrer
        // CSP_EXTRA_SOURCES avant d'appliquer.
        'report_only' => (bool) env('CSP_REPORT_ONLY', false),

        // Origines supplémentaires (séparées par des espaces ou des virgules), ajoutées aux
        // directives script-src, style-src, connect-src, font-src, img-src et frame-src.
        'extra_sources' => env('CSP_EXTRA_SOURCES', ''),

        // Politique complète qui REMPLACE celle calculée par l'application. Vide = politique
        // par défaut (App\Support\ContentSecurityPolicy).
        'policy' => env('CSP_POLICY', ''),
    ],

    // Durée de l'en-tête Strict-Transport-Security, posé seulement sur une requête HTTPS
    // (directe, ou derrière un proxy de confiance qui l'annonce). 0 le coupe.
    'hsts_max_age' => (int) env('HSTS_MAX_AGE', 31536000),

];
