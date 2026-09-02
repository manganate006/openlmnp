<?php

namespace App\Services\OpenData;

use Illuminate\Support\Facades\Http;

/**
 * Accès aux fichiers communaux DVF publiés sur data.gouv.fr.
 *
 * `https://files.data.gouv.fr/geo-dvf/latest/csv/{année}/communes/{dep}/{insee}.csv`
 * — 20 Ko à 4 Mo par commune, sans clé ni quota. Le fichier national de 523 Mo n'est jamais
 * touché : c'est ce découpage par commune qui rend l'estimation possible en pleine requête,
 * alors que l'application n'a AUCUNE file d'attente (`QUEUE_CONNECTION=sync`).
 *
 * ⚠️ Le cache est un FICHIER, pas le cache Laravel. Une instance auto-hébergée qui a déjà
 * consulté sa commune doit continuer à fonctionner hors ligne et après un `cache:clear` —
 * c'est le sens même de l'auto-hébergement. En cas de panne réseau, un cache périmé est
 * resservi plutôt que de rendre une erreur : une médiane d'il y a deux mois vaut mieux que rien.
 */
class DvfClient
{
    /**
     * Départements absents de DVF.
     *
     * L'Alsace-Moselle relève du livre foncier et Mayotte d'un cadastre distinct. Ce n'est
     * pas une panne mais une limite de la source : elle mérite un message, pas une excuse.
     */
    public const UNCOVERED_DEPARTMENTS = ['57', '67', '68', '976'];

    /**
     * Prix au m² relevés pour une commune et un millésime.
     *
     * @return array<int, array{t: string, p: int}>
     *
     * @throws DvfUnavailable
     */
    public function samples(string $insee, int $year): array
    {
        if (in_array(self::department($insee), self::UNCOVERED_DEPARTMENTS, true)) {
            throw DvfUnavailable::uncoveredDepartment();
        }

        $path = $this->cachePath($insee, $year);
        $ttl = (int) config('opendata.dvf.cache_days') * 86400;

        if (is_file($path) && (time() - (int) filemtime($path)) < $ttl) {
            return $this->read($path) ?? [];
        }

        try {
            $samples = DvfEstimator::samplesFromCsv($this->fetch($insee, $year));
        } catch (DvfUnavailable $e) {
            // Réseau coupé : on ressert le cache périmé s'il existe.
            $stale = is_file($path) ? $this->read($path) : null;

            if ($stale === null) {
                throw $e;
            }

            return $stale;
        }

        $this->write($path, $samples);

        return $samples;
    }

    /**
     * Millésimes publiés, du plus récent au plus ancien.
     *
     * ⚠️ **Lecture seule, aucun appel réseau** — et ça doit le rester. Cette méthode alimente la
     * liste déroulante de la modale, donc elle s'exécute à l'ouverture ; y télécharger l'index
     * de data.gouv.fr rendrait l'appel sortant implicite, alors que tout le dispositif repose
     * sur le fait qu'il ne part que sur un clic explicite. Le relevé est fait hors requête par
     * `dvf:refresh-years` (planifié).
     *
     * Repli sur `config('opendata.dvf.years')` tant que rien n'a été relevé — et seulement là :
     * un relevé, même vieux, vaut mieux qu'une liste figée dans le code. Une instance
     * auto-hébergée sans accès réseau reste sur le repli, ce qui est le bon comportement.
     *
     * @return array<int, int>
     */
    public static function years(): array
    {
        $stored = self::storedYears();

        if ($stored !== []) {
            return $stored;
        }

        $years = array_map('intval', (array) config('opendata.dvf.years', []));
        rsort($years);

        return array_values($years);
    }

    /**
     * Relève les millésimes réellement publiés, en lisant l'index de `geo-dvf/latest/csv/`.
     *
     * L'index est un listing HTML de 947 octets. DVF est republié deux fois par an : sans ce
     * relevé, un nouveau millésime n'est jamais utilisé, en silence.
     *
     * @return array<int, int> vide si l'index est injoignable ou illisible
     */
    public static function discoverYears(): array
    {
        try {
            $response = Http::timeout((int) config('opendata.dvf.timeout'))
                ->get(rtrim((string) config('opendata.dvf.base_url'), '/').'/');
        } catch (\Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        // On lit le LIBELLÉ des liens (`>2021/<`), pas leur href : le jour où le chemin change,
        // le listing affichera toujours les répertoires de la même façon.
        preg_match_all('#>\s*(20\d{2})/\s*<#', $response->body(), $matches);

        $years = array_filter(
            array_unique(array_map('intval', $matches[1])),
            fn (int $year) => $year >= 2014 && $year <= (int) date('Y') + 1
        );
        rsort($years);

        // Garde-fou : un index qui changerait de format donnerait une liste vide ou d'un seul
        // élément. Mieux vaut garder le relevé précédent que casser l'estimation.
        return count($years) >= 3 ? array_values($years) : [];
    }

    /** @param  array<int, int>  $years */
    public static function storeYears(array $years): void
    {
        $path = storage_path('app/private/dvf/years.json');

        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }

        @file_put_contents($path, json_encode(array_values($years)));
    }

    /** @return array<int, int> */
    private static function storedYears(): array
    {
        $path = storage_path('app/private/dvf/years.json');
        $decoded = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;

        if (! is_array($decoded) || $decoded === []) {
            return [];
        }

        $years = array_map('intval', $decoded);
        rsort($years);

        return array_values($years);
    }

    /**
     * Répertoire département de geo-dvf à partir du code INSEE.
     *
     * Outre-mer sur 3 caractères (971→976), Corse naturellement sur 2 (`2A004` → `2A`).
     */
    public static function department(string $insee): string
    {
        return str_starts_with($insee, '97') ? substr($insee, 0, 3) : substr($insee, 0, 2);
    }

    /**
     * @throws DvfUnavailable
     */
    private function fetch(string $insee, int $year): string
    {
        $url = sprintf(
            '%s/%d/communes/%s/%s.csv',
            rtrim((string) config('opendata.dvf.base_url'), '/'),
            $year,
            self::department($insee),
            $insee
        );

        try {
            $response = Http::timeout((int) config('opendata.dvf.timeout'))
                ->retry(2, 200, throw: false)
                ->get($url);
        } catch (\Throwable) {
            throw DvfUnavailable::network();
        }

        // 404 = commune sans vente publiée ce millésime-là (fréquent en zone rurale). Ce
        // n'est pas une panne : on renvoie un CSV vide, l'appelant élargira les années.
        if ($response->status() === 404) {
            return '';
        }

        if (! $response->successful()) {
            throw DvfUnavailable::network();
        }

        return $response->body();
    }

    private function cachePath(string $insee, int $year): string
    {
        return storage_path("app/private/dvf/{$year}/".preg_replace('/[^A-Z0-9]/i', '', $insee).'.json');
    }

    /** @return array<int, array{t: string, p: int}>|null */
    private function read(string $path): ?array
    {
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param  array<int, array{t: string, p: int}>  $samples */
    private function write(string $path, array $samples): void
    {
        // Un cache qu'on n'arrive pas à écrire (disque plein, droits) ne doit jamais faire
        // échouer une estimation : on retéléchargera, c'est tout.
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }

        @file_put_contents($path, json_encode($samples));
    }
}
