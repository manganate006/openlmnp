<?php

namespace App\Services\OpenData;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Recherche le DPE d'un bien dans la base de l'ADEME (`dpe03existant`, data-fair,
 * data.ademe.fr), publiée depuis juillet 2021 sous Licence Ouverte 2.0.
 *
 * Deux façons de chercher, jamais mélangées :
 *   - par ADRESSE : l'adresse du bien est géocodée par la Géoplateforme (IGN,
 *     `data.geopf.fr/geocodage/search`) en un identifiant BAN, qui sert ensuite de filtre
 *     EXACT sur `identifiant_ban`. Une recherche plein texte sur l'adresse ADEME donnerait
 *     des faux positifs sur les rues homonymes — c'est le champ que recommande la fiche du
 *     jeu de données (voir `_admin/todo/opendata/TODO-OPENDATA-DPE.md`).
 *   - par NUMÉRO de DPE, saisi à la main : utile quand l'adresse ne géocode à rien d'assez
 *     précis, ou que l'utilisateur a déjà son attestation sous les yeux.
 *
 * Plusieurs logements d'un même immeuble partagent le même identifiant BAN : une recherche
 * par adresse peut donc renvoyer plusieurs DPE. RIEN n'est choisi à la place de
 * l'utilisateur ici — la liste complète est renvoyée, triée du plus récent au plus ancien,
 * DPE remplacés exclus (`numero_dpe_remplace`) ; c'est à l'appelant de faire choisir.
 *
 * ⚠️ APPEL SORTANT, ET IL RÉVÈLE L'ADRESSE DU BIEN — plus précis que la seule commune que
 * révèle l'estimation DVF. D'où une action explicite et jamais un appel au chargement d'un
 * écran, et une coupure entière possible avec `DPE_ENABLED=false`.
 */
class DpeClient
{
    public static function enabled(): bool
    {
        return (bool) config('opendata.dpe.enabled');
    }

    /**
     * DPE trouvés à une adresse, du plus récent au plus ancien, DPE remplacés exclus.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws DpeUnavailable
     */
    public function forAddress(?string $address, ?string $postalCode, ?string $city, ?string $inseeCode = null): array
    {
        $this->guard();

        $banId = $this->geocode($address, $postalCode, $city, $inseeCode);
        if ($banId === null) {
            return [];
        }

        return Cache::remember(
            'dpe:ban:'.$banId,
            now()->addMinutes((int) config('opendata.dpe.cache_minutes')),
            fn () => $this->excludeReplaced($this->fetch('identifiant_ban:"'.$banId.'"'))
        );
    }

    /**
     * Le DPE portant ce numéro, ou null s'il n'existe pas dans la base.
     *
     * Contrairement à `forAddress()`, le résultat n'est PAS filtré sur `numero_dpe_remplace` :
     * l'utilisateur a désigné ce numéro précis, volontairement — un DPE remplacé reste un
     * document réel, que l'écran peut signaler mais pas taire.
     *
     * @return array<string, mixed>|null
     *
     * @throws DpeUnavailable
     */
    public function byNumber(string $number): ?array
    {
        $this->guard();

        $number = trim($number);
        if ($number === '') {
            return null;
        }

        $rows = Cache::remember(
            'dpe:number:'.$number,
            now()->addMinutes((int) config('opendata.dpe.cache_minutes')),
            fn () => $this->fetch('numero_dpe:"'.$number.'"')
        );

        return $rows[0] ?? null;
    }

    /**
     * @throws DpeUnavailable
     */
    private function guard(): void
    {
        if (! self::enabled()) {
            throw DpeUnavailable::disabled();
        }

        $key = 'dpe:'.(Auth::id() ?? 'guest');

        if (RateLimiter::tooManyAttempts($key, (int) config('opendata.dpe.rate_limit'))) {
            throw DpeUnavailable::throttled();
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * Identifiant BAN du logement, ou null si l'adresse ne géocode à rien d'assez précis.
     *
     * @throws DpeUnavailable
     */
    private function geocode(?string $address, ?string $postalCode, ?string $city, ?string $inseeCode): ?string
    {
        $query = trim(implode(' ', array_filter([$address, $postalCode, $city])));
        if ($query === '') {
            return null;
        }

        $params = ['q' => $query, 'limit' => 1, 'autocomplete' => 0, 'type' => 'housenumber'];
        if ($inseeCode) {
            // Plus précis que le code postal : lève l'ambiguïté des codes postaux qui
            // couvrent plusieurs communes.
            $params['citycode'] = $inseeCode;
        } elseif ($postalCode) {
            $params['postcode'] = $postalCode;
        }

        try {
            $response = Http::timeout((int) config('opendata.dpe.timeout'))
                ->retry(2, 200, throw: false)
                ->get((string) config('opendata.dpe.geocode_url'), $params);
        } catch (\Throwable) {
            throw DpeUnavailable::network();
        }

        if (! $response->successful()) {
            throw DpeUnavailable::network();
        }

        $id = $response->json('features.0.properties.id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws DpeUnavailable
     */
    private function fetch(string $qs): array
    {
        $fields = 'numero_dpe,etiquette_dpe,date_etablissement_dpe,date_fin_validite_dpe,'
            .'numero_dpe_remplace,type_batiment,numero_etage_appartement,'
            .'complement_adresse_logement,surface_habitable_logement';

        try {
            $response = Http::timeout((int) config('opendata.dpe.timeout'))
                ->retry(2, 200, throw: false)
                ->get(rtrim((string) config('opendata.dpe.base_url'), '/').'/lines', [
                    'size' => 50,
                    'sort' => '-date_etablissement_dpe',
                    'select' => $fields,
                    'qs' => $qs,
                ]);
        } catch (\Throwable) {
            throw DpeUnavailable::network();
        }

        if (! $response->successful()) {
            throw DpeUnavailable::network();
        }

        $today = now()->toDateString();

        return array_values(array_map(
            fn (array $row) => [
                'numero_dpe' => (string) ($row['numero_dpe'] ?? ''),
                'etiquette_dpe' => strtoupper((string) ($row['etiquette_dpe'] ?? '')),
                'date_etablissement_dpe' => (string) ($row['date_etablissement_dpe'] ?? ''),
                'date_fin_validite_dpe' => (string) ($row['date_fin_validite_dpe'] ?? ''),
                'numero_dpe_remplace' => $row['numero_dpe_remplace'] ?? null,
                'type_batiment' => $row['type_batiment'] ?? null,
                'numero_etage_appartement' => $row['numero_etage_appartement'] ?? null,
                'complement_adresse_logement' => $row['complement_adresse_logement'] ?? null,
                'surface_habitable_logement' => $row['surface_habitable_logement'] ?? null,
                'expired' => (($row['date_fin_validite_dpe'] ?? '') !== '') && $row['date_fin_validite_dpe'] < $today,
            ],
            (array) ($response->json('results') ?? [])
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function excludeReplaced(array $rows): array
    {
        $replaced = array_filter(array_column($rows, 'numero_dpe_remplace'));

        return array_values(array_filter(
            $rows,
            fn (array $row) => ! in_array($row['numero_dpe'], $replaced, true)
        ));
    }
}
