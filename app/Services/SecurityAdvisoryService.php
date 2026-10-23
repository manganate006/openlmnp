<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Avis de sécurité reçus en réponse au check-in de télémétrie.
 *
 * La vitrine peut joindre à sa réponse une liste `advisories`. Ces données viennent du
 * réseau : chaque entrée est validée strictement (types, longueurs, formats, URL en
 * https sur un hôte de confiance) et les entrées invalides sont ignorées. Le bandeau du
 * panel n'affiche ensuite que les avis qui concernent la version installée : il disparaît
 * de lui-même après la mise à jour.
 *
 * Forme stockée dans `settings.security_advisories` (JSON) :
 *   {"received_at": "2026-10-23T08:00:00+00:00", "advisories": [{id, severity, title,
 *    message, url|null, affected_from, fixed_in}, ...]}
 */
class SecurityAdvisoryService
{
    public const SETTING_KEY = 'security_advisories';

    public const MAX_ADVISORIES = 10;

    public const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    /** Hôtes autorisés pour le lien « Lire l'avis ». */
    public const ALLOWED_HOSTS = ['openlmnp.fr', 'www.openlmnp.fr', 'github.com'];

    private const VERSION_PATTERN = '/^\d+(\.\d+)*$/';

    /**
     * Traite le corps JSON décodé de la réponse du check-in.
     *
     * Sans clé `advisories` (vitrine ancienne) : rien ne change. Clé présente, même vide :
     * la liste stockée est remplacée.
     */
    public function storeFromResponse(mixed $body): void
    {
        if (! is_array($body) || ! array_key_exists('advisories', $body)) {
            return;
        }

        if (! is_array($body['advisories']) || ! array_is_list($body['advisories'])) {
            // Clé présente mais inexploitable : on garde la liste connue plutôt que de
            // l'effacer sur une réponse malformée.
            return;
        }

        $advisories = [];

        foreach ($body['advisories'] as $entry) {
            $advisory = $this->sanitize($entry);

            if ($advisory === null) {
                continue;
            }

            $advisories[] = $advisory;

            if (count($advisories) >= self::MAX_ADVISORIES) {
                break;
            }
        }

        Setting::set(self::SETTING_KEY, json_encode([
            'received_at' => now()->toIso8601String(),
            'advisories' => $advisories,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Valide une entrée reçue. Retourne null si elle doit être ignorée.
     *
     * @return array{id: string, severity: string, title: string, message: string, url: ?string, affected_from: string, fixed_in: string}|null
     */
    public function sanitize(mixed $entry): ?array
    {
        if (! is_array($entry)) {
            return null;
        }

        $id = $entry['id'] ?? null;
        $severity = $entry['severity'] ?? null;
        $title = $entry['title'] ?? null;
        $message = $entry['message'] ?? null;
        $fixedIn = $entry['fixed_in'] ?? null;
        $affectedFrom = $entry['affected_from'] ?? '0';

        if (! is_string($id) || strlen($id) > 64 || ! preg_match('/^[a-z0-9-]+$/', $id)) {
            return null;
        }

        if (! is_string($severity) || ! in_array($severity, self::SEVERITIES, true)) {
            return null;
        }

        if (! is_string($title) || trim($title) === '' || mb_strlen($title) > 150) {
            return null;
        }

        if (! is_string($message) || trim($message) === '' || mb_strlen($message) > 600) {
            return null;
        }

        if (! is_string($fixedIn) || ! preg_match(self::VERSION_PATTERN, $fixedIn)) {
            return null;
        }

        if (! is_string($affectedFrom) || ! preg_match(self::VERSION_PATTERN, $affectedFrom)) {
            return null;
        }

        return [
            'id' => $id,
            'severity' => $severity,
            'title' => trim($title),
            'message' => trim($message),
            // Lien invalide : l'avis reste affiché, sans lien.
            'url' => $this->safeUrl($entry['url'] ?? null),
            'affected_from' => $affectedFrom,
            'fixed_in' => $fixedIn,
        ];
    }

    /** URL en https sur un hôte de la liste blanche, sinon null. */
    public function safeUrl(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port'])
            || ! in_array(strtolower($parts['host'] ?? ''), self::ALLOWED_HOSTS, true)) {
            return null;
        }

        return $url;
    }

    /**
     * Liste stockée (revalidée à la lecture : la base n'est pas une source de confiance
     * plus sûre que le réseau qui l'a remplie).
     *
     * @return list<array{id: string, severity: string, title: string, message: string, url: ?string, affected_from: string, fixed_in: string}>
     */
    public function stored(): array
    {
        $data = json_decode((string) Setting::get(self::SETTING_KEY), true);

        if (! is_array($data) || ! is_array($data['advisories'] ?? null)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($entry) => $this->sanitize($entry),
            array_slice($data['advisories'], 0, self::MAX_ADVISORIES),
        )));
    }

    /**
     * Avis qui concernent la version donnée (par défaut, la version installée).
     *
     * @return list<array{id: string, severity: string, title: string, message: string, url: ?string, affected_from: string, fixed_in: string}>
     */
    public function applicable(?string $version = null): array
    {
        $version ??= (new UpdateService())->getCurrentVersion();

        return array_values(array_filter(
            $this->stored(),
            fn (array $a) => version_compare($version, $a['affected_from'], '>=')
                && version_compare($version, $a['fixed_in'], '<'),
        ));
    }
}
