<?php

namespace App\Services\OpenData;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Saisie libre (nom de commune, code postal ou code INSEE) → communes candidates.
 *
 * S'appuie sur l'API Découpage administratif (`geo.api.gouv.fr`), publique et sans clé,
 * adossée au jeu « Découpage administratif du territoire français » de data.gouv.fr — le
 * SECOND jeu de données associé à la réutilisation.
 *
 * DVF est indexé par code INSEE, pas par code postal : cette résolution n'est donc pas un
 * confort de saisie, c'est une étape obligatoire (un code postal peut couvrir plusieurs
 * communes, et Paris/Lyon/Marseille sont découpés en arrondissements côté INSEE).
 */
final class CommuneResolver
{
    private const FIELDS = 'nom,code,codesPostaux,departement,population';

    /**
     * Communes à arrondissements municipaux : [code commune => [préfixe INSEE, nombre].
     *
     * ⚠️ SANS CETTE EXPANSION, « Lyon » EST UN 404. L'API Découpage administratif ne connaît
     * que la commune (69123) et n'expose aucun endpoint d'arrondissements, alors que DVF
     * n'est publié QUE par arrondissement (69381 à 69389). Un visiteur cherchant Lyon, Paris
     * ou Marseille — les trois villes les plus probables sur un outil d'investissement —
     * obtiendrait « aucune vente publiée ». Vérifié le 2026-09-01 : `/communes/69123/
     * arrondissements` et `?codeParent=` ne renvoient rien.
     *
     * Les codes postaux se déduisent du rang (75101 → 75001, 69381 → 69001, 13201 → 13001).
     */
    private const ARRONDISSEMENTS = [
        '75056' => ['name' => 'Paris', 'insee' => 75100, 'postal' => 75000, 'count' => 20],
        '69123' => ['name' => 'Lyon', 'insee' => 69380, 'postal' => 69000, 'count' => 9],
        '13055' => ['name' => 'Marseille', 'insee' => 13200, 'postal' => 13000, 'count' => 16],
    ];

    /**
     * @return array<int, array{code: string, nom: string, departement: string, code_postal: string}>
     *
     * @throws DvfUnavailable
     */
    public function search(string $query): array
    {
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? '');
        if ($query === '') {
            return [];
        }

        return Cache::remember(
            'dvf:communes:'.md5(mb_strtolower($query)),
            now()->addDays(30),
            fn () => $this->expand($this->lookup($query), $query)
        );
    }

    /**
     * Remplace Paris / Lyon / Marseille par leurs arrondissements, seuls publiés dans DVF.
     *
     * Si la recherche était un code postal, on ne garde que l'arrondissement correspondant :
     * « 69003 » doit mener à Lyon 3e, pas à une liste de neuf choix.
     *
     * @param  array<int, array{code: string, nom: string, departement: string, code_postal: string}>  $communes
     * @return array<int, array{code: string, nom: string, departement: string, code_postal: string}>
     */
    private function expand(array $communes, string $query): array
    {
        $expanded = [];

        foreach ($communes as $commune) {
            $city = self::ARRONDISSEMENTS[$commune['code']] ?? null;
            if ($city === null) {
                $expanded[] = $commune;

                continue;
            }

            $boroughs = [];
            for ($rank = 1; $rank <= $city['count']; $rank++) {
                $boroughs[] = [
                    'code' => (string) ($city['insee'] + $rank),
                    'nom' => $city['name'].' '.$rank.($rank === 1 ? 'er' : 'e'),
                    'departement' => $commune['departement'],
                    'code_postal' => str_pad((string) ($city['postal'] + $rank), 5, '0', STR_PAD_LEFT),
                ];
            }

            // Le filtre ne s'applique QU'aux arrondissements ainsi fabriqués : appliqué à
            // toute la liste, il écraserait l'ambiguïté code postal / code INSEE ci-dessus.
            $matching = array_values(array_filter($boroughs, fn (array $b) => $b['code_postal'] === $query));

            $expanded = array_merge($expanded, $matching !== [] ? $matching : $boroughs);
        }

        return $expanded;
    }

    /**
     * @return array<int, array{code: string, nom: string, departement: string, code_postal: string}>
     *
     * @throws DvfUnavailable
     */
    private function lookup(string $query): array
    {
        // Un code INSEE corse (`2A004`) n'est jamais un code postal : on tranche tout de suite.
        if (preg_match('/^2[AB][0-9]{3}$/i', $query)) {
            return $this->get('/communes/'.mb_strtoupper($query));
        }

        if (preg_match('/^[0-9]{5}$/', $query)) {
            // Un humain tape un code postal, pas un code INSEE : c'est l'interprétation par
            // défaut, le code INSEE ne servant que de rattrapage.
            //
            // ⚠️ Les deux lectures peuvent désigner des communes DIFFÉRENTES — « 97411 » est
            // le code postal de Saint-Paul et le code INSEE de Saint-Denis, et « 69003 » le
            // code postal de Lyon 3ᵉ et le code INSEE d'Albigny-sur-Saône. Proposer les deux
            // à chaque fois noierait le cas courant sous une question inutile ; la commune
            // retenue est donc affichée avec son code INSEE, pour que l'erreur saute aux yeux.
            $communes = $this->get('/communes', ['codePostal' => $query]);

            return $communes !== [] ? $communes : $this->get('/communes/'.$query);
        }

        return $this->get('/communes', ['nom' => $query, 'boost' => 'population', 'limit' => 8]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<int, array{code: string, nom: string, departement: string, code_postal: string}>
     *
     * @throws DvfUnavailable
     */
    private function get(string $path, array $params = []): array
    {
        $url = rtrim((string) config('opendata.dvf.geo_api_url'), '/').$path;

        try {
            $response = Http::timeout((int) config('opendata.dvf.timeout'))
                ->retry(2, 200, throw: false)
                ->get($url, $params + ['fields' => self::FIELDS]);
        } catch (\Throwable) {
            throw DvfUnavailable::network();
        }

        if ($response->status() === 404) {
            return [];
        }

        if (! $response->successful()) {
            throw DvfUnavailable::network();
        }

        $body = $response->json();
        // `/communes/{code}` renvoie un objet, `/communes?...` un tableau.
        $rows = array_is_list($body ?? []) ? $body : [$body];

        return array_values(array_filter(array_map(self::normalise(...), $rows ?? [])));
    }

    /**
     * @param  mixed  $row
     * @return array{code: string, nom: string, departement: string, code_postal: string}|null
     */
    private static function normalise($row): ?array
    {
        if (! is_array($row) || ! isset($row['code'], $row['nom'])) {
            return null;
        }

        return [
            'code' => (string) $row['code'],
            'nom' => (string) $row['nom'],
            'departement' => (string) ($row['departement']['nom'] ?? ''),
            'code_postal' => (string) ($row['codesPostaux'][0] ?? ''),
        ];
    }
}
