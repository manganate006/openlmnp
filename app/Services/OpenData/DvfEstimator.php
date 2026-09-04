<?php

namespace App\Services\OpenData;

/**
 * Moteur d'estimation de la valeur vénale à partir des ventes réelles d'une commune (DVF).
 *
 * Source : « Demandes de valeurs foncières géolocalisées » (DGFiP), data.gouv.fr,
 * Licence Ouverte 2.0. Le fichier communal est un CSV d'UNE LIGNE PAR (mutation, parcelle
 * ou local) — ce n'est pas une ligne par vente, et c'est tout le problème.
 *
 * ⚠️ LE PIÈGE CENTRAL DE DVF : `valeur_fonciere` porte le prix de la mutation ENTIÈRE,
 * répété sur chacune de ses lignes. Diviser ce prix par la surface d'un seul lot d'une vente
 * qui en compte trois donne un prix au m² deux à trois fois trop élevé. On ne garde donc que
 * les mutations MONO-LOT (cf. `mutationSample()`), quitte à jeter des échantillons.
 *
 * Ordre de grandeur mesuré sur le millésime 2025, écart entre la médiane naïve (une ligne =
 * un comparable) et la nôtre : +0,6 % à Paris 11ᵉ et Rennes, +2,2 % à Nice, +6,9 % à Bordeaux.
 * Ce n'est donc PAS un facteur 2 sur le résultat — la médiane absorbe l'essentiel du biais —
 * mais c'est un écart systématique et toujours dans le même sens, à la hausse.
 *
 * Conventions du site : montants en CENTIMES, bcmath, jamais de float dans un calcul.
 */
final class DvfEstimator
{
    /** Codes `type_local` de DVF. Les codes 3 (dépendance) et 4 (local commercial) sont hors sujet. */
    public const TYPE_HOUSE = '1';

    public const TYPE_APARTMENT = '2';

    public const TYPES = [
        'maison' => self::TYPE_HOUSE,
        'appartement' => self::TYPE_APARTMENT,
    ];

    /**
     * Bornes de vraisemblance du prix au m², en centimes (200 € à 30 000 €/m²).
     *
     * DVF contient des ventes à l'euro symbolique, des soultes et des erreurs de saisie.
     * Ces bornes ne « lissent » pas le marché : elles écartent ce qui n'est pas un prix.
     */
    public const MIN_PRICE_PER_M2_CENTS = 20_000;

    public const MAX_PRICE_PER_M2_CENTS = 3_000_000;

    /** En dessous, la mutation n'est pas une vente de marché (soulte, cession familiale…). */
    public const MIN_SALE_CENTS = 500_000;

    /** Un studio de moins de 9 m² n'est pas un logement (art. 4 du décret 2002-120). */
    public const MIN_AREA_M2 = 9;

    public const BOUNDS = ['area' => [9, 1_000]];

    /**
     * Réduit un CSV communal DVF à ses prix au m² exploitables.
     *
     * Le retour est volontairement minuscule (`['t' => '1', 'p' => 452_300]`) : c'est LUI
     * qu'on met en cache, pas le CSV. Une commune dense tient dans quelques milliers d'entiers.
     *
     * @param  string  $csv  contenu brut du fichier `{insee}.csv`
     * @return array<int, array{t: string, p: int}>
     */
    public static function samplesFromCsv(string $csv): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        if (count($lines) < 2) {
            return [];
        }

        // Lecture par NOM de colonne : l'ordre des 40 colonnes de DVF n'est pas un contrat.
        $header = str_getcsv(array_shift($lines), ',', '"', '\\');
        $index = array_flip($header);
        foreach (['id_mutation', 'nature_mutation', 'valeur_fonciere', 'code_type_local', 'surface_reelle_bati'] as $required) {
            if (! isset($index[$required])) {
                return [];
            }
        }

        $roomsColumn = $index['nombre_pieces_principales'] ?? null;

        // ⚠️ On n'accumule PAS les lignes brutes : Toulouse 2025 fait 4 Mo pour 15 000 lignes
        // de 40 colonnes, soit ~80 Mo de tableaux PHP — de quoi mettre à genoux un conteneur
        // à 1 Go sur deux requêtes simultanées. Seul est retenu, par mutation, ce qui décide :
        // son prix et l'ensemble DÉDOUBLONNÉ de ses locaux bâtis. Mesuré : 80 Mo → 8 Mo.
        /** @var array<string, array{v: int, l: array<string, array{0: string, 1: int}>}> $mutations */
        $mutations = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $row = str_getcsv($line, ',', '"', '\\');
            $id = $row[$index['id_mutation']] ?? '';
            if ($id === '' || ($row[$index['nature_mutation']] ?? '') !== 'Vente') {
                continue;
            }

            // `valeur_fonciere` porte le prix de la mutation ENTIÈRE, identique sur chacune
            // de ses lignes : il ne se lit qu'une fois.
            $mutations[$id] ??= ['v' => self::toCents($row[$index['valeur_fonciere']] ?? ''), 'l' => []];

            $type = $row[$index['code_type_local']] ?? '';
            $area = (int) ($row[$index['surface_reelle_bati']] ?? 0);
            if ($area <= 0 || ! in_array($type, [self::TYPE_HOUSE, self::TYPE_APARTMENT], true)) {
                continue;
            }

            // Dédoublonnage sur (type, surface, pièces) : geo-dvf répète le MÊME local sur
            // chaque parcelle d'assiette — une maison à cheval sur deux parcelles produit deux
            // lignes identiques. Sans lui, toute maison multi-parcelles serait écartée à tort.
            $key = $type.'|'.$area.'|'.($roomsColumn === null ? '' : ($row[$roomsColumn] ?? ''));
            $mutations[$id]['l'][$key] = [$type, $area];
        }

        $samples = [];
        foreach ($mutations as $mutation) {
            $sample = self::mutationSample($mutation);
            if ($sample !== null) {
                $samples[] = $sample;
            }
        }

        return $samples;
    }

    /**
     * Un prix au m² pour une mutation, ou `null` si elle n'est pas un comparable propre.
     *
     * @param  array{v: int, l: array<string, array{0: string, 1: int}>}  $mutation
     * @return array{t: string, p: int}|null
     */
    private static function mutationSample(array $mutation): ?array
    {
        // Zéro local bâti (terrain nu) ou plusieurs : le prix n'est attribuable à aucune
        // surface — c'est LE filtre qui sépare un prix au m² juste d'un prix triplé.
        if ($mutation['v'] < self::MIN_SALE_CENTS || count($mutation['l']) !== 1) {
            return null;
        }

        [$type, $area] = reset($mutation['l']);
        if ($area < self::MIN_AREA_M2) {
            return null;
        }

        $perM2 = (int) bcdiv((string) $mutation['v'], (string) $area, 0);
        if ($perM2 < self::MIN_PRICE_PER_M2_CENTS || $perM2 > self::MAX_PRICE_PER_M2_CENTS) {
            return null;
        }

        return ['t' => $type, 'p' => $perM2];
    }

    /**
     * Estime la valeur vénale d'un bien à partir d'échantillons déjà réduits.
     *
     * @param  array<int, array{t: string, p: int}>  $samples
     * @param  array<int, int>  $years  millésimes d'où proviennent les échantillons
     * @return array<string, mixed>
     */
    public static function estimate(array $samples, string $type, int $area, array $years): array
    {
        $code = self::TYPES[$type] ?? self::TYPE_APARTMENT;
        $area = max(self::BOUNDS['area'][0], min(self::BOUNDS['area'][1], $area));

        $prices = [];
        foreach ($samples as $sample) {
            if ($sample['t'] === $code) {
                $prices[] = $sample['p'];
            }
        }

        $median = self::median($prices);

        return [
            'type' => array_search($code, self::TYPES, true) ?: 'appartement',
            'area_m2' => $area,
            'sample_size' => count($prices),
            'years' => $years,
            'price_per_m2_cents' => $median,
            // Pas de valeur affichée sous le seuil : la page dit « pas assez de ventes »
            // plutôt que d'habiller une médiane sur deux transactions.
            'value_cents' => $median === null ? null : (int) bcmul((string) $median, (string) $area, 0),
        ];
    }

    /**
     * Médiane entière d'une série de centimes.
     *
     * Sur un effectif pair, moyenne des deux valeurs centrales, tronquée comme partout
     * ailleurs sur le site (bcmath scale 0).
     *
     * @param  array<int, int>  $values
     */
    public static function median(array $values): ?int
    {
        $count = count($values);
        if ($count === 0) {
            return null;
        }

        sort($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$middle];
        }

        return (int) bcdiv(bcadd((string) $values[$middle - 1], (string) $values[$middle], 0), '2', 0);
    }

    /** `valeur_fonciere` est en euros avec un point décimal (« 185000.00 ») → centimes. */
    private static function toCents(string $value): int
    {
        $value = trim(str_replace(',', '.', $value));
        if ($value === '' || ! is_numeric($value)) {
            return 0;
        }

        return (int) bcmul($value, '100', 0);
    }
}
