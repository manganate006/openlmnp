<?php

namespace App\Services\OpenData;

use Illuminate\Support\Facades\Http;

/**
 * Découverte automatique d'un millésime de la « Carte des loyers » sur l'API data.gouv.fr —
 * ce qui évite de relever les 4 URLs à la main à chaque nouvelle publication (décembre).
 *
 * Chaque millésime est un jeu de données DISTINCT, au slug prévisible :
 * `carte-des-loyers-indicateurs-de-loyers-dannonce-par-commune-en-{année}`. `GET
 * /api/1/datasets/{slug}/` renvoie `resources[]` (chacune avec `url`, `title`, `format`) si le
 * jeu existe, ou une 404 sinon (relevé le 2026-09-30).
 *
 * ⚠️ **Les 4 fichiers sont identifiés par la FIN DE LEUR URL, jamais par leur titre** — celui-ci
 * varie d'un millésime à l'autre (singulier ou pluriel selon les années). Le nom de fichier, lui,
 * est stable : seul le dossier horodaté qui le précède change.
 */
final class RentMapDiscovery
{
    /**
     * Fin d'URL exacte → clé `RentMapSource::TYPES` correspondante.
     *
     * @var array<string, string>
     */
    private const FILE_SUFFIXES = [
        'pred-app-mef-dhup.csv' => RentMapSource::TYPE_APARTMENT,
        'pred-app12-mef-dhup.csv' => RentMapSource::TYPE_APARTMENT_1_2,
        'pred-app3-mef-dhup.csv' => RentMapSource::TYPE_APARTMENT_3_PLUS,
        'pred-mai-mef-dhup.csv' => RentMapSource::TYPE_HOUSE,
    ];

    /** Slug du jeu data.gouv.fr pour ce millésime — même gabarit chaque année. */
    public static function slug(int $vintage): string
    {
        return "carte-des-loyers-indicateurs-de-loyers-dannonce-par-commune-en-{$vintage}";
    }

    /** Page publique du jeu (celle citée à l'affichage), calculée à partir du slug. */
    public static function datasetPageUrl(int $vintage): string
    {
        return rtrim((string) config('opendata.rent_map.page_base_url'), '/').'/'.self::slug($vintage);
    }

    /**
     * Les 4 URLs de CSV du millésime demandé, ou `null` si le jeu n'existe pas encore (404),
     * si l'API ne répond pas (réseau coupé, instance auto-hébergée sans accès sortant), ou si
     * la réponse ne contient pas les 4 fichiers reconnus (jeu publié incomplet).
     *
     * @return array<string, string>|null clé = `RentMapSource::TYPES`, valeur = URL du CSV
     */
    public static function discoverUrls(int $vintage): ?array
    {
        try {
            $response = Http::timeout((int) config('opendata.rent_map.timeout'))
                ->retry(2, 200, throw: false)
                ->acceptJson()
                ->get(rtrim((string) config('opendata.rent_map.api_base_url'), '/').'/'.self::slug($vintage).'/');
        } catch (\Throwable) {
            return null;
        }

        // 404 = millésime pas encore publié : pas une panne, l'appelant retentera un autre jour.
        if (! $response->successful()) {
            return null;
        }

        $urls = [];
        foreach ((array) $response->json('resources') as $resource) {
            $url = (string) ($resource['url'] ?? '');

            foreach (self::FILE_SUFFIXES as $suffix => $type) {
                if ($url !== '' && str_ends_with($url, $suffix)) {
                    $urls[$type] = $url;
                }
            }
        }

        // Moins de 4 fichiers reconnus = jeu incomplet : on ne devine jamais un fichier manquant.
        return count($urls) === 4 ? $urls : null;
    }
}
