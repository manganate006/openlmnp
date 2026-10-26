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
     * Formats essayés, dans l'ordre. `d/m/Y` passe AVANT `m/d/Y` : les exports
     * français sont majoritaires et, pour les douze premiers jours du mois, les
     * deux formats sont RÉELLEMENT indiscernables ligne à ligne (« 04/05/2026 »
     * est un 4 mai valide dans les deux lectures — rien dans la chaîne elle-même
     * ne permet de trancher). Ne pas réordonner sans repasser par
     * `detectPreferMonthFirst()` : c'est elle qui porte la seule exception
     * légitime, au niveau du FICHIER entier.
     */
    private const DATE_FORMATS = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'Y/m/d', 'd-m-Y', 'M d, Y', 'd M Y'];

    /** Même famille, `m/d/Y` d'abord : un fichier signalé anglo-saxon par `detectPreferMonthFirst()`. */
    private const DATE_FORMATS_MONTH_FIRST = ['Y-m-d', 'm/d/Y', 'd/m/Y', 'Y/m/d', 'd-m-Y', 'M d, Y', 'd M Y'];

    /**
     * Date vers `Y-m-d`.
     *
     * ⚠️ L'ancienne garde testait `$date->month <= 12 && $date->day <= 31` sur le
     * résultat APRÈS le report de Carbon : `createFromFormat('d/m/Y', '04/30/2026')`
     * ne rend pas `false`, il ACCEPTE un « mois 30 » et le reporte sur juin 2028 —
     * la garde y voyait alors mois=6, jour=4, deux valeurs parfaitement valides, et
     * laissait passer une date fausse de deux ans. Elle ne pouvait matériellement
     * rien rejeter : un report de Carbon produit toujours un mois et un jour dans
     * les bornes. Remplacée par un ALLER-RETOUR strict : un format n'est retenu
     * que s'il reproduit EXACTEMENT la chaîne d'origine une fois reformaté.
     *
     * @param  bool  $preferMonthFirst  vrai si `detectPreferMonthFirst()` a établi,
     *                                  ailleurs dans le MÊME fichier, qu'au moins
     *                                  une date n'est lisible qu'en `m/d/Y` — le
     *                                  seul signal qui ne devine pas. S'applique
     *                                  alors à TOUTES les dates du fichier, y
     *                                  compris celles des douze premiers jours du
     *                                  mois, insuffisantes à elles seules pour
     *                                  lever l'ambiguïté (l'aller-retour ne la lève
     *                                  pas non plus : les deux lectures sont
     *                                  également valides pour ces jours-là).
     */
    public static function date(string $raw, bool $preferMonthFirst = false): string
    {
        $raw = trim($raw);

        foreach ($preferMonthFirst ? self::DATE_FORMATS_MONTH_FIRST : self::DATE_FORMATS as $format) {
            if ($date = self::parseExact($raw, $format)) {
                return $date->format('Y-m-d');
            }
        }

        try {
            return Carbon::parse($raw)->format('Y-m-d');
        } catch (\Exception) {
            throw new \RuntimeException("Date illisible : « {$raw} »");
        }
    }

    /**
     * Un fichier doit-il être lu en `m/d/Y` plutôt qu'en `d/m/Y` ?
     *
     * Vrai dès qu'AU MOINS UNE date du fichier est IMPOSSIBLE en `d/m/Y` (aucun
     * aller-retour ne réussit) mais lisible en `m/d/Y` — par construction, une
     * telle date porte un premier ou second nombre supérieur à 12 qui ne peut être
     * qu'un jour. C'est le seul signal qui ne devine pas : un export dont toutes
     * les dates tombent dans les douze premiers jours du mois ne lève jamais ce
     * drapeau, faute de preuve, et reste lu en `d/m/Y` par défaut — l'ambiguïté
     * réelle n'est pas résolue, seulement le cas où elle ne l'est pas.
     *
     * Décision au niveau du FICHIER, pas de la ligne : un cabinet exporte dans un
     * seul format, jamais un mélange des deux dans le même CSV.
     *
     * @param  iterable<string>  $rawDates  toutes les dates brutes du fichier — y
     *                                      compris les colonnes secondaires (fin
     *                                      de séjour, réservation…) : une seule
     *                                      suffit à trancher pour tout le fichier.
     */
    public static function detectPreferMonthFirst(iterable $rawDates): bool
    {
        foreach ($rawDates as $raw) {
            $raw = trim((string) $raw);

            if ($raw === '') {
                continue;
            }

            if (! self::parseExact($raw, 'd/m/Y') && self::parseExact($raw, 'm/d/Y')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tente `$format` sur `$raw` et exige un aller-retour EXACT : reformater le
     * résultat doit reproduire la chaîne d'origine à l'identique. Rend `null` sur
     * tout échec — format inapplicable, ou report silencieux de Carbon.
     */
    private static function parseExact(string $raw, string $format): ?Carbon
    {
        try {
            $date = Carbon::createFromFormat($format, $raw);
        } catch (\Exception) {
            return null;
        }

        if (! $date || $date->format($format) !== $raw) {
            return null;
        }

        return $date;
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
