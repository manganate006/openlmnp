<?php

namespace App\Support;

/**
 * Politique de sécurité du contenu (CSP) de l'application (GHSA-j4gm-g8m8-93x2, point 20).
 *
 * ⚠️ `'unsafe-inline'` et `'unsafe-eval'` dans script-src sont VOULUS, pas un oubli :
 *  - Filament 5 / Livewire 4 embarquent la version standard d'Alpine, qui évalue les
 *    expressions `x-data`, `x-on`… avec `new Function()` : sans `'unsafe-eval'`, le panel
 *    entier est inerte. La version « CSP » d'Alpine (`livewire.csp_safe`) n'accepte que des
 *    noms de composants enregistrés, alors que les vues de Filament écrivent des
 *    expressions en ligne : le panel n'y survit pas ;
 *  - le panel, nos vues (graphiques, GTM, consentement) et Livewire posent des `<script>`
 *    et des `style=""` en ligne, sans nonce.
 * La politique ne prétend donc pas arrêter un script injecté dans la page. Ce qu'elle
 * apporte : aucun script ni aucune connexion vers une origine non listée (exfiltration,
 * chargement d'une charge utile externe), pas de `<base>` détourné, pas de formulaire posté
 * ailleurs, pas de plugin, pas d'encadrement de l'application par un site tiers.
 *
 * Réglages (config/security.php) : CSP_ENABLED, CSP_REPORT_ONLY, CSP_EXTRA_SOURCES,
 * CSP_POLICY (remplacement complet).
 */
class ContentSecurityPolicy
{
    /** Graphiques Chart.js chargés par le simulateur, l'éditeur d'amortissement et le détail d'emprunt. */
    private const CDN = 'https://cdn.jsdelivr.net';

    /** Polices de la page d'accueil Laravel (welcome.blade.php). */
    private const FONTS = 'https://fonts.bunny.net';

    /** Origines de Google Tag Manager / GA4 : seulement si GTM_CONTAINER_ID est réglé. */
    private const GOOGLE_TAGS = [
        'https://www.googletagmanager.com',
        'https://*.googletagmanager.com',
        'https://www.google-analytics.com',
        'https://*.google-analytics.com',
        'https://*.analytics.google.com',
    ];

    /** Nom de l'en-tête à poser, ou null si la politique est coupée. */
    public static function headerName(): ?string
    {
        if (! config('security.csp.enabled', true)) {
            return null;
        }

        return config('security.csp.report_only', false)
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
    }

    public static function policy(): string
    {
        $override = trim((string) config('security.csp.policy', ''));

        if ($override !== '') {
            return $override;
        }

        $tags = self::googleTagSources();
        $extra = self::extraSources();

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'", self::CDN, ...$tags, ...$extra],
            'style-src' => ["'self'", "'unsafe-inline'", self::FONTS, ...$extra],
            'font-src' => ["'self'", 'data:', self::FONTS, ...$extra],
            // Aperçus d'images (blob: des téléversements), avatars, pixels de mesure.
            'img-src' => ["'self'", 'data:', 'blob:', 'https:', ...$extra],
            'connect-src' => ["'self'", ...$tags, ...$extra],
            // Offres affichées en cadre (fin de démo, recueil d'avis) : vides en auto-hébergé.
            'frame-src' => ["'self'", ...self::frameSources(), ...$tags, ...$extra],
            'worker-src' => ["'self'", 'blob:'],
            'frame-ancestors' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
        ];

        return implode('; ', array_map(
            fn (string $name, array $sources) => $name.' '.implode(' ', array_values(array_unique($sources))),
            array_keys($directives),
            $directives,
        ));
    }

    /** @return list<string> */
    private static function googleTagSources(): array
    {
        if (blank(config('services.gtm.id'))) {
            return [];
        }

        return array_values(array_filter([self::origin(config('services.gtm.server_url')), ...self::GOOGLE_TAGS]));
    }

    /** @return list<string> */
    private static function frameSources(): array
    {
        return array_values(array_filter([
            self::origin(config('demo.links.pro')),
            self::origin(config('feedback.links.pro')),
        ]));
    }

    /**
     * Origines ajoutées par CSP_EXTRA_SOURCES. On n'accepte que des sources d'hôte ou de
     * schéma : un mot-clé (`'unsafe-hashes'`…) ou un `;` glissé là ouvrirait une autre
     * directive.
     *
     * @return list<string>
     */
    private static function extraSources(): array
    {
        $raw = preg_split('/[\s,]+/', (string) config('security.csp.extra_sources', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($raw, fn (string $source) => preg_match('#^(https?:|wss?:)(//[A-Za-z0-9*.\-]+(:\d+|:\*)?(/[^\s;,\']*)?)?$#', $source) === 1));
    }

    private static function origin(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            return null;
        }

        return $scheme.'://'.$host.($port ? ':'.$port : '');
    }
}
