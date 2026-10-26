<?php

namespace App\Services\OpenData;

use RuntimeException;

/**
 * La « Carte des loyers » (ANIL / ministère de la Transition écologique), millésime 2025 :
 * ce qu'elle mesure, comment on la lit, et comment on la cite.
 *
 * Même source que celle déjà exploitée côté `../vitrine` (`App\Support\CarteLoyers\CarteLoyersSource`,
 * colonne « Loyer vide / m² » du tableau `/lmnp`) — code volontairement DUPLIQUÉ, pas partagé :
 * `app/` reste autonome et AGPL, `../vitrine` est un dépôt séparé. Les deux lisent le même
 * format de CSV, mais celui-ci importe les QUATRE fichiers (la vitrine n'en lit qu'un) et les
 * conserve en base pour n'importe quelle commune, pas seulement les 56 du tableau.
 *
 * -- Ce que mesure l'indicateur, cité et paginé (lu le 2026-09-30)
 *
 * Guide d'utilisation des données (PDF de la fiche data.gouv.fr) :
 *  - p. 1 : « Les indicateurs de loyers sont fournis charges comprises pour des biens types
 *    non meublés mis en location au 3ème trimestre 2025 ».
 *  - p. 2 : « Les indicateurs de loyers sont calculés charges comprises, sur des données
 *    d'annonces non meublées, donc mesurent des loyers de flux uniquement. » Et : « Il ne
 *    s'agit donc pas de loyers "médians" ou "moyens". »
 *  - p. 3 (dictionnaire) : `loypredm2` = « Loyer d'annonces, charges comprises pour un bien
 *    de référence non meublé, pour une annonce mise en ligne au T3 2025 » ; `lwr.IPm2` /
 *    `upr.IPm2` = bornes de l'intervalle de prédiction à 95 % ; `TYPPRED` = niveau de la
 *    prédiction ; `nbobs_com` / `nbobs_mail` = observations dans la commune / la maille ;
 *    `R2_adj` = coefficient de détermination ajusté.
 *
 * Note méthodologique : p. 28, « les annonces prises en compte sont circonscrites aux
 * locations non meublées » ; p. 6, les bases n'offrent pas toutes de quoi distinguer
 * « location saisonnière et location longue durée » (on n'écrit donc jamais « à l'année » ni
 * « longue durée » à son sujet) ; p. 27, le loyer d'annonce « peut différer du loyer inscrit
 * dans le bail ». **Les millésimes ne se comparent pas entre eux** (maillage et biens types
 * revus chaque année) : jamais d'évolution de loyer tirée de deux cartes.
 *
 * ⚠️ **C'est un loyer de location VIDE.** Le rapprocher tel quel des recettes d'un meublé de
 * tourisme serait comparer deux marchés différents. Partout où ce repère est affiché, il doit
 * être présenté comme un repère du marché non meublé — jamais comme ce que rapporterait le
 * même bien en meublé, et jamais mêlé à un calcul fiscal.
 *
 * -- Les 4 fichiers, et le choix de n'en utiliser que 2 à l'affichage
 *
 * Le jeu publie 4 indicateurs par bien type de référence (appartement toutes typologies,
 * appartement 1-2 pièces, appartement 3 pièces et plus, maison), chacun avec sa propre
 * surface de référence. Les 4 sont importés — c'est ce que demande la commande — mais
 * `App\Models\Property` ne connaît PAS le nombre de pièces d'un bien (seulement `total_area`
 * et `type`). Choisir T1-T2 ou T3+ à partir de la seule surface du bien obligerait à inventer
 * un seuil que la source ne fournit pas : `RentMapIndicator::forProperty()` retient donc
 * TOUJOURS l'appartement « toutes typologies » pour un bien de type appartement/chambre/studio/
 * autre — exactement le même regroupement que `MarketValueEstimator::TYPES` pour DVF — et la
 * maison pour un bien de type maison. Les fichiers T1-T2 et T3+ restent en base, prêts à être
 * exploités le jour où l'application gagnerait un nombre de pièces.
 *
 * -- Paris, Lyon, Marseille
 *
 * Le fichier ne publie AUCUNE ligne communale pour ces trois villes (pas de 75056, 69123 ni
 * 13055) : seulement leurs arrondissements, chacun prédit à son propre niveau. La jointure se
 * fait au code INSEE exact de `properties.insee_code` — qui, pour un bien parisien, lyonnais
 * ou marseillais, est DÉJÀ un code d'arrondissement : c'est `App\Services\OpenData\CommuneResolver`
 * qui le pose (seule écriture de cette colonne, via l'action « Estimer (DVF) »), et il
 * n'expose jamais le code de la commune globale. Un bien dont `insee_code` est encore `null`
 * — ou, en théorie, la commune globale elle-même — n'a simplement pas de repère : message
 * clair côté affichage, pas d'erreur.
 */
final class RentMapSource
{
    /**
     * Millésime de REPLI, utilisé uniquement au premier import d'une instance neuve (table
     * vide) si l'API de découverte ne répond pas — voir `RefreshRentMap`. Le millésime
     * RÉELLEMENT en base (affiché partout) est lu dans `rent_map_indicators`, jamais ici :
     * `RentMapIndicator::currentVintage()`. La page du jeu correspondant se calcule à partir
     * du slug (`RentMapDiscovery::datasetPageUrl()`), elle n'est plus fixée en dur non plus.
     */
    public const VINTAGE = 2025;

    /** Mention de source imposée par les conditions d'utilisation (guide p. 2). */
    public const ATTRIBUTION = 'Estimations ANIL, à partir des données du Groupe SeLoger et de leboncoin';

    public const TYPE_APARTMENT = 'apartment';

    public const TYPE_APARTMENT_1_2 = 'apartment_1_2';

    public const TYPE_APARTMENT_3_PLUS = 'apartment_3_plus';

    public const TYPE_HOUSE = 'house';

    /**
     * Les 4 fichiers du millésime de REPLI ci-dessus, avec leur surface de bien type (guide
     * p. 1, tableau 11) : URL exacte à l'heure où ce fichier a été écrit — data.gouv.fr encode
     * un horodatage d'upload dans le chemin, qui ne se déduit pas de l'année.
     *
     * ⚠️ Depuis l'automatisation de la découverte (`RentMapDiscovery`), ces URLs ne servent
     * plus qu'en dernier recours : bootstrap d'une instance neuve (table vide) dont l'appel à
     * l'API data.gouv.fr échoue. `openlmnp:refresh-rent-map --if-missing` interroge d'abord
     * `RentMapDiscovery::discoverUrls()`, qui retrouve les 4 URLs courantes en identifiant les
     * fichiers par la FIN de leur nom (`pred-app-mef-dhup.csv`, etc.), jamais par leur titre
     * (qui varie selon les millésimes) — le même gabarit qu'utilise déjà
     * `App\Support\CarteLoyers\CarteLoyersSource` côté vitrine pour son propre repli.
     *
     * @var array<string, array{url: string, label: string, reference_surface_m2: int}>
     */
    public const TYPES = [
        self::TYPE_APARTMENT => [
            'url' => 'https://static.data.gouv.fr/resources/carte-des-loyers-indicateurs-de-loyers-dannonce-par-commune-en-2025/20251211-145010/pred-app-mef-dhup.csv',
            'label' => 'Appartement (toutes typologies)',
            'reference_surface_m2' => 52,
        ],
        self::TYPE_APARTMENT_1_2 => [
            'url' => 'https://static.data.gouv.fr/resources/carte-des-loyers-indicateurs-de-loyers-dannonce-par-commune-en-2025/20251211-144934/pred-app12-mef-dhup.csv',
            'label' => 'Appartement 1 ou 2 pièces',
            'reference_surface_m2' => 37,
        ],
        self::TYPE_APARTMENT_3_PLUS => [
            'url' => 'https://static.data.gouv.fr/resources/carte-des-loyers-indicateurs-de-loyers-dannonce-par-commune-en-2025/20251211-144951/pred-app3-mef-dhup.csv',
            'label' => 'Appartement 3 pièces ou plus',
            'reference_surface_m2' => 72,
        ],
        self::TYPE_HOUSE => [
            'url' => 'https://static.data.gouv.fr/resources/carte-des-loyers-indicateurs-de-loyers-dannonce-par-commune-en-2025/20251211-145039/pred-mai-mef-dhup.csv',
            'label' => 'Maison',
            'reference_surface_m2' => 92,
        ],
    ];

    /**
     * Seuils de prudence de la note (p. 28) : R² sous 0,5, ou moins de 30 observations dans
     * la commune. Le troisième critère de la note (« intervalle très large ») n'est pas
     * chiffré par la source : on ne l'invente pas, l'intervalle est affiché et chacun en juge.
     */
    public const CAUTION_MIN_R2 = 0.5;

    public const CAUTION_MIN_OBSERVATIONS = 30;

    /**
     * Colonnes attendues du CSV (en-tête identique en 2023, 2024 et 2025, relevé le
     * 2026-09-30) — partagée par `rows()` (parsing complet) et `headerMatches()` (vérification
     * rapide d'un millésime nouvellement découvert, AVANT tout remplacement en base).
     *
     * @var array<int, string>
     */
    private const REQUIRED_COLUMNS = ['INSEE_C', 'loypredm2', 'lwr.IPm2', 'upr.IPm2', 'TYPPRED', 'nbobs_com', 'nbobs_mail', 'R2_adj'];

    public static function enabled(): bool
    {
        return (bool) config('opendata.rent_map.enabled');
    }

    /**
     * Type de bien de l'application → clé de `self::TYPES`.
     *
     * Même regroupement que `MarketValueEstimator::TYPES` pour DVF : une chambre, un studio ou
     * « autre » se comparent à un appartement, faute de mieux. Voir la note de classe sur
     * l'absence d'un nombre de pièces dans `Property`.
     */
    public static function typeForPropertyType(string $propertyType): string
    {
        return $propertyType === 'house' ? self::TYPE_HOUSE : self::TYPE_APARTMENT;
    }

    /**
     * Vrai quand la source elle-même invite à la prudence sur cet indicateur.
     *
     * @param  array{r2: float|null, observations: int|null}  $indicator
     */
    public static function callsForCaution(array $indicator): bool
    {
        return ($indicator['r2'] ?? 0.0) < self::CAUTION_MIN_R2
            || ($indicator['observations'] ?? 0) < self::CAUTION_MIN_OBSERVATIONS;
    }

    /**
     * Lit le CSV en flux, UNE COMMUNE À LA FOIS — jamais accumulé en mémoire : le fichier
     * porte environ 35 000 lignes (une par commune française), et l'appelant l'insère en base
     * par lots au fil de l'itération plutôt que d'en garder un tableau complet.
     *
     * Le fichier est en Windows-1252, séparé par des points-virgules, décimales à virgule —
     * mais les seules colonnes lues (codes, nombres) sont ASCII, donc l'encodage n'a aucun
     * effet sur ce que cette méthode retourne.
     *
     * @return iterable<int, array{insee_code: string, per_m2_cents: int, low_per_m2_cents: int, high_per_m2_cents: int, observations: int, mesh_observations: int, prediction_level: string, r2: float}>
     */
    public static function rows(string $path): iterable
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Fichier Carte des loyers illisible : {$path}");
        }

        try {
            $header = fgetcsv($handle, null, ';', '"', '');
            $index = [];

            foreach (self::REQUIRED_COLUMNS as $column) {
                $position = array_search($column, $header ?: [], true);
                if ($position === false) {
                    throw new RuntimeException("Colonne « {$column} » absente du CSV Carte des loyers : le format a changé.");
                }
                $index[$column] = $position;
            }

            while (($row = fgetcsv($handle, null, ';', '"', '')) !== false) {
                $code = $row[$index['INSEE_C']] ?? null;
                if ($code === null || $code === '') {
                    continue;
                }

                $number = fn (string $column): float => (float) str_replace(',', '.', (string) ($row[$index[$column]] ?? ''));

                yield [
                    'insee_code' => (string) $code,
                    'per_m2_cents' => (int) round($number('loypredm2') * 100),
                    'low_per_m2_cents' => (int) round($number('lwr.IPm2') * 100),
                    'high_per_m2_cents' => (int) round($number('upr.IPm2') * 100),
                    'observations' => (int) ($row[$index['nbobs_com']] ?? 0),
                    'mesh_observations' => (int) ($row[$index['nbobs_mail']] ?? 0),
                    // « commune », « maille » ou « epci » (dictionnaire) : stocké tel quel.
                    'prediction_level' => (string) ($row[$index['TYPPRED']] ?? ''),
                    'r2' => round($number('R2_adj'), 3),
                ];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Vrai si le CSV a bien les colonnes attendues — SANS le parser en entier (une seule
     * ligne lue). Utilisé par `RefreshRentMap` pour vérifier un millésime nouvellement
     * découvert AVANT tout remplacement en base : si un seul des 4 fichiers échoue à ce
     * contrôle, rien n'est remplacé, l'ancien millésime reste servi.
     */
    public static function headerMatches(string $path): bool
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return false;
        }

        try {
            $header = fgetcsv($handle, null, ';', '"', '') ?: [];
        } finally {
            fclose($handle);
        }

        foreach (self::REQUIRED_COLUMNS as $column) {
            if (! in_array($column, $header, true)) {
                return false;
            }
        }

        return true;
    }
}
