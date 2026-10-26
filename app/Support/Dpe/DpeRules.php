<?php

namespace App\Support\Dpe;

/**
 * Ce que la classe d'un DPE permet, pour une location meublée, à une date donnée.
 *
 * Copie conforme de `openlmnp-vitrine` (`app/Support/Dpe/DpeRules.php`, source de vérité de
 * `/outils/dpe-location-meublee`) : même docblock, mêmes sources, mêmes calendriers. Toute
 * correction de calendrier se fait d'abord là-bas, puis se recopie ici.
 *
 * Textes lus sur Légifrance le 2026-09-29 (via FlareSolverr) :
 *
 *  - Bail meublé, résidence principale du locataire : article 6 de la loi n° 89-462 du
 *    6 juillet 1989 (version en vigueur depuis le 1er janvier 2025), rendu applicable aux
 *    meublés par l'article 25-3. Le logement décent a une classe comprise entre A et F à
 *    compter du 1er janvier 2025, A et E à compter du 1er janvier 2028, A et D à compter du
 *    1er janvier 2034. Outre-mer (Guadeloupe, Martinique, Guyane, La Réunion, Mayotte) :
 *    A à F à compter du 1er janvier 2028, A à E à compter du 1er janvier 2031. « Les logements
 *    qui ne répondent pas aux critères précités aux échéances fixées sont considérés comme non
 *    décents. »
 *  - Meublé de tourisme : article L. 324-2-2 du code du tourisme, créé par l'article 3 de la
 *    loi n° 2024-1039 du 19 novembre 2024 (dite Le Meur) — il respecte les niveaux de
 *    performance d'un logement décent de l'article 6, SAUF s'il est la résidence principale du
 *    loueur. Le III de cet article 3 fait entrer ce I en vigueur le 1er janvier 2034.
 *  - Autorisation de changement d'usage pour louer en meublé de tourisme (art. L. 631-7 ou
 *    L. 631-7-1 A du CCH) : article L. 631-10 du CCH, créé par le II du même article 3, en
 *    vigueur sans report — DPE entre A et E, entre A et D à compter du 1er janvier 2034, en
 *    France métropolitaine seulement.
 *
 * ⚠️ La classe du DPE (`etiquette_dpe`) est déjà la classe « au sens de l'article L. 173-1-1 »,
 * qui combine énergie et gaz à effet de serre : ne jamais comparer l'étiquette GES seule.
 */
final class DpeRules
{
    public const CLASSES = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

    /** Échéances de décence énergétique (art. 6 de la loi de 1989) : date → pire classe admise. */
    public const DECENCY = [
        'metropole' => ['2025-01-01' => 'F', '2028-01-01' => 'E', '2034-01-01' => 'D'],
        'outre_mer' => ['2028-01-01' => 'F', '2031-01-01' => 'E'],
    ];

    /** Entrée en vigueur de l'obligation des meublés de tourisme (art. 3, III, loi 2024-1039). */
    public const TOURISM_FROM = '2034-01-01';

    /** Autorisation de changement d'usage (art. L. 631-10 du CCH), métropole seulement. */
    public const CHANGE_OF_USE = ['2024-11-21' => 'E', '2034-01-01' => 'D'];

    /** Départements d'outre-mer visés par l'article 6 (codes INSEE). */
    public const OVERSEAS = ['971', '972', '973', '974', '976'];

    /**
     * Pire classe admise à une date, ou null si aucune exigence ne s'applique encore.
     *
     * @param  array<string, string>  $calendar
     */
    public static function worstAllowed(array $calendar, string $date): ?string
    {
        $allowed = null;
        foreach ($calendar as $from => $class) {
            if ($date >= $from) {
                $allowed = $class;
            }
        }

        return $allowed;
    }

    /**
     * Verdict pour un usage.
     *
     * @param  string  $usage  bail_meuble | tourisme | tourisme_residence_principale | changement_usage
     * @return array{applies: bool, allowed: bool|null, worst_allowed: string|null, next: array{date: string, worst_allowed: string}|null}
     */
    public static function verdict(string $label, string $usage, string $date, bool $overseas = false): array
    {
        $calendar = match ($usage) {
            'bail_meuble' => self::DECENCY[$overseas ? 'outre_mer' : 'metropole'],
            'tourisme' => array_filter(
                self::DECENCY[$overseas ? 'outre_mer' : 'metropole'],
                fn ($from) => $from >= self::TOURISM_FROM,
                ARRAY_FILTER_USE_KEY
            ) + [self::TOURISM_FROM => self::worstAllowed(self::DECENCY[$overseas ? 'outre_mer' : 'metropole'], self::TOURISM_FROM)],
            'changement_usage' => $overseas ? [] : self::CHANGE_OF_USE,
            'tourisme_residence_principale' => [],
            default => throw new \InvalidArgumentException("Usage inconnu : {$usage}"),
        };
        ksort($calendar);

        $worst = self::worstAllowed($calendar, $date);
        $next = null;
        foreach ($calendar as $from => $class) {
            if ($from > $date) {
                $next = ['date' => $from, 'worst_allowed' => $class];
                break;
            }
        }

        return [
            'applies' => $worst !== null,
            'allowed' => $worst === null ? null : self::rank($label) <= self::rank($worst),
            'worst_allowed' => $worst,
            'next' => $next,
        ];
    }

    private static function rank(string $class): int
    {
        $rank = array_search(strtoupper($class), self::CLASSES, true);
        if ($rank === false) {
            throw new \InvalidArgumentException("Classe DPE inconnue : {$class}");
        }

        return $rank;
    }
}
