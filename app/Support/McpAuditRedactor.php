<?php

namespace App\Support;

/**
 * Ce que le journal MCP garde des arguments d'un appel.
 *
 * Il les enregistrait tels quels (sauf `file_base64`) : un CSV Airbnb complet avec les
 * noms des voyageurs, des URL signées, des notes, sans limite de taille — trois appels
 * de 1 Mo faisaient grossir la base d'autant (GHSA-j4gm-g8m8-93x2, points 10 et 13).
 * On garde de quoi diagnostiquer (les noms des arguments, les identifiants, les valeurs
 * courtes) et rien de ce qui ne sert qu'à transporter un contenu.
 */
class McpAuditRedactor
{
    /** Arguments qui transportent un fichier ou un contenu : seule leur taille est gardée. */
    public const CONTENT_KEYS = ['file_base64', 'csv_base64', 'csv_content', 'content'];

    /** Au-delà, une chaîne est remplacée par sa seule longueur. */
    public const MAX_STRING = 500;

    /** Au-delà, l'ensemble des arguments est remplacé par un résumé. */
    public const MAX_TOTAL_BYTES = 16384;

    public static function redact(mixed $params): mixed
    {
        if (! is_array($params)) {
            return is_string($params) ? self::string($params) : $params;
        }

        $redacted = self::walk($params);
        $json = json_encode($redacted, JSON_UNESCAPED_UNICODE);

        if ($json === false || strlen($json) > self::MAX_TOTAL_BYTES) {
            return ['_masque' => 'arguments trop volumineux (' . ($json === false ? '?' : strlen($json)) . ' octets)', 'cles' => array_keys($params)];
        }

        return $redacted;
    }

    private static function walk(array $params): array
    {
        foreach ($params as $key => $value) {
            if (is_string($key) && in_array($key, self::CONTENT_KEYS, true) && is_string($value)) {
                $params[$key] = '[masqué — ' . strlen($value) . ' caractères]';
            } elseif (is_string($key) && str_ends_with($key, 'url') && is_string($value)) {
                // Une URL signée porte son jeton dans la requête : on n'en garde que l'adresse.
                $params[$key] = strtok($value, '?') . (str_contains($value, '?') ? '?[masqué]' : '');
            } elseif (is_array($value)) {
                $params[$key] = self::walk($value);
            } elseif (is_string($value)) {
                $params[$key] = self::string($value);
            }
        }

        return $params;
    }

    private static function string(string $value): string
    {
        return mb_strlen($value) > self::MAX_STRING
            ? '[masqué — ' . mb_strlen($value) . ' caractères]'
            : $value;
    }
}
