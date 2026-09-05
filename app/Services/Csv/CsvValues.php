<?php

namespace App\Services\Csv;

use Illuminate\Support\Carbon;

/**
 * Conversions d'une cellule de CSV vers nos types.
 *
 * ⚠️ Ces règles vivaient en `private` dans `AirbnbImportService`, qui reste leur premier
 * client : elles sont recopiées ICI et nulle part ailleurs, et l'import Airbnb délègue.
 * Les dupliquer ferait diverger deux lectures de « 1 234,56 € » — le genre d'écart qui
 * ne se voit qu'au moment de la déclaration.
 */
class CsvValues
{
    /**
     * Montant monétaire vers des centimes (entier).
     *
     * Gère « 1,234.56 », « 1234.56 », « 1 234,56 », « 252,26 € », « -56.78 ».
     * La détection de format repose sur le DERNIER séparateur : deux chiffres après une
     * virgule finale valent européen, tout le reste vaut anglo-saxon.
     */
    public static function money(string $raw): int
    {
        $raw = trim($raw);
        // Symboles monétaires et espaces Unicode
        $raw = preg_replace('/[€$£\x{00A0}\x{202F}]/u', '', $raw);
        $raw = trim($raw);

        if ($raw === '') {
            return 0;
        }

        if (preg_match('/,\d{2}$/', $raw)) {
            // Format européen : 1.234,56 ou 1 234,56
            $raw = str_replace(['.', ' '], '', $raw);
            $raw = str_replace(',', '.', $raw);
        } else {
            // Format anglo-saxon : 1,234.56
            $raw = str_replace([',', ' '], '', $raw);
        }

        if (! is_numeric($raw)) {
            throw new \RuntimeException("Montant illisible : « {$raw} »");
        }

        return (int) bcmul($raw, '100', 0);
    }

    /**
     * Date vers `Y-m-d`.
     *
     * ⚠️ `d/m/Y` est essayé AVANT `m/d/Y` : les exports français sont majoritaires et,
     * pour les douze premiers jours du mois, les deux formats sont indiscernables.
     */
    public static function date(string $raw): string
    {
        $raw = trim($raw);

        foreach (['Y-m-d', 'd/m/Y', 'm/d/Y', 'Y/m/d', 'd-m-Y', 'M d, Y', 'd M Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $raw);
                if ($date && $date->month <= 12 && $date->day <= 31) {
                    return $date->format('Y-m-d');
                }
            } catch (\Exception) {
                continue;
            }
        }

        try {
            return Carbon::parse($raw)->format('Y-m-d');
        } catch (\Exception) {
            throw new \RuntimeException("Date illisible : « {$raw} »");
        }
    }

    /** Entier tolérant (« 10 ans » → 10), 0 si rien d'exploitable. */
    public static function integer(string $raw): int
    {
        return (int) preg_replace('/[^\-0-9]/', '', trim($raw));
    }

    /**
     * Booléen à la française.
     *
     * « oui / o / vrai / true / 1 / x » sont vrais ; tout le reste est faux. Un tableur
     * de cabinet coche volontiers une colonne d'une croix, et lire « x » comme faux
     * ferait taire silencieusement une option choisie.
     */
    public static function boolean(string $raw): bool
    {
        $value = mb_strtolower(trim($raw));

        return in_array($value, ['1', 'x', 'o', 'oui', 'yes', 'y', 'true', 'vrai'], true);
    }
}
