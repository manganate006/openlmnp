<?php

namespace App\Services\OpenData;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Accès à l'API Recherche d'entreprises (DINUM, données Insee, Licence Ouverte 2.0, sans clé)
 * — `https://recherche-entreprises.api.gouv.fr/search?q=<siren>&per_page=5`, 7 requêtes par
 * seconde et par IP (documentation OpenAPI, relevée le 2026-09-30).
 *
 * Même contrat que `DvfClient` : APPEL SORTANT, donc jamais au chargement d'un écran — seulement
 * sur clic explicite (bouton « Vérifier dans Sirene »), qui transmet le seul SIREN saisi.
 *
 * ⚠️ Deux codes APE sont normaux pour un loueur en meublé, pas un seul (nomenclature NAF rév. 2,
 * Insee, relue le 2026-09-30) : 68.20A « Location de logements » couvre les meublés destinés à
 * l'habitation principale ou secondaire et EXCLUT le court séjour, qui relève de 55.20Z
 * « Hébergement touristique et autre hébergement de courte durée ». Ne jamais réduire l'un des
 * deux à une anomalie.
 *
 * ⚠️ Cache FICHIER, comme DVF — pas le cache Laravel : une instance auto-hébergée qui a déjà
 * vérifié son SIREN continue de fonctionner hors ligne et après un `cache:clear`. Sur panne
 * réseau, un résultat périmé est resservi plutôt que de rendre une erreur.
 */
class SireneClient
{
    /** Codes NAF rév. 2 → [libellé Insee, ce que le code veut dire pour un loueur]. */
    public const RENTAL_CODES = [
        '68.20A' => ['Location de logements', 'location de logements, vides ou meublés, destinés à l\'habitation principale ou secondaire'],
        '55.20Z' => ['Hébergement touristique et autre hébergement de courte durée', 'location meublée de courte durée, à la nuitée ou à la semaine'],
    ];

    /**
     * Catégories juridiques Insee (niveau III) qu'un loueur en meublé rencontre, avec leur
     * libellé officiel. Les autres codes s'affichent seuls, sans libellé inventé.
     *
     * ⚠️ 2110 est la forme d'une indivision entre personnes physiques : c'est elle qu'exige la
     * vérification d'un dossier d'indivision. Le registre ne nomme les indivisaires que dans
     * environ un cas sur deux (relevé du 2026-10-14) : la forme prouve l'indivision, pas
     * l'identité de ses membres.
     */
    public const LEGAL_FORMS = [
        '1000' => 'Entrepreneur individuel',
        '2110' => 'Indivision entre personnes physiques',
        '2120' => 'Indivision avec personne morale',
    ];

    public const INDIVISION_LEGAL_FORMS = ['2110', '2120'];

    /**
     * La fiche Sirene du SIREN exact, ou null si l'API répond sans lui.
     *
     * @return array<string, mixed>|null résultat BRUT de l'API — `describe()` le met en forme
     *
     * @throws SireneUnavailable
     */
    public function lookup(string $siren, bool $fresh = false): ?array
    {
        $this->guard();

        $path = $this->cachePath($siren);
        $ttl = (int) config('opendata.sirene.cache_days') * 86400;

        // `$fresh` : une décision qui ACCORDE quelque chose (dossier d'indivision) se prend sur
        // l'état du jour — une entreprise cessée depuis le dernier relevé doit être vue.
        if (! $fresh && is_file($path) && (time() - (int) filemtime($path)) < $ttl) {
            return $this->read($path);
        }

        try {
            $company = $this->fetch($siren);
        } catch (SireneUnavailable $e) {
            // Réseau coupé : on ressert le résultat périmé s'il existe, comme DVF — sauf en
            // lecture fraîche, où un relevé ancien ne vaut pas preuve.
            if ($fresh || ! is_file($path)) {
                throw $e;
            }

            return $this->read($path);
        }

        $this->write($path, $company);

        return $company;
    }

    /**
     * Ce que l'écran affiche. Le nom n'est montré que si l'entreprise n'a pas demandé la
     * diffusion partielle (`statut_diffusion` = 'O') — son absence ne dit rien d'autre.
     *
     * @param  array<string, mixed>  $company  résultat brut de `lookup()`
     * @return array<string, mixed>
     */
    public static function describe(array $company): array
    {
        $ape = (string) ($company['activite_principale'] ?? '');
        $legalForm = (string) ($company['nature_juridique'] ?? '');

        return [
            'siren' => (string) ($company['siren'] ?? ''),
            'name' => ($company['statut_diffusion'] ?? 'O') === 'O' ? self::withoutRepeatedSigle($company['nom_complet'] ?? null) : null,
            'active' => ($company['etat_administratif'] ?? '') === 'A',
            'created_on' => $company['date_creation'] ?? null,
            'closed_on' => $company['date_fermeture'] ?? null,
            'ape' => $ape,
            'rental' => self::RENTAL_CODES[$ape] ?? null,
            'legal_form' => $legalForm,
            'legal_form_label' => self::LEGAL_FORMS[$legalForm] ?? null,
            'indivision' => in_array($legalForm, self::INDIVISION_LEGAL_FORMS, true),
            'open_establishments' => isset($company['nombre_etablissements_ouverts'])
                ? (int) $company['nombre_etablissements_ouverts']
                : null,
            // Dirigeants personnes physiques (ex. les indivisaires eux-mêmes) : le
            // registre ne nomme QUE l'entité pour une indivision (« nom_complet » =
            // littéralement « INDIVISION » dans environ la moitié des cas relevés le
            // 2026-10-14, voir LEGAL_FORMS) — ces noms sont ce qui reste de concret
            // pour proposer un nom de dossier utile (voir LinkedDossiers::
            // proposedDossierName()).
            'leaders' => self::extractLeaders($company),
        ];
    }

    /**
     * « RAISON (SIGLE) » quand le sigle répète la raison sociale, ce que l'API renvoie souvent
     * pour une indivision : « INDIVISION (INDIVISION) » (D5 du test bêta du 2026-10-15). Ramené
     * à « RAISON », comparaison sans casse ni espaces superflus. Un sigle qui dit autre chose
     * (« SCI DES ERABLES (SDE) ») est une information : il reste.
     */
    public static function withoutRepeatedSigle(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = trim($name);

        if (preg_match('/^(.+?)\s*\(\s*(.+?)\s*\)$/u', $name, $m)
            && mb_strtoupper(trim($m[1])) === mb_strtoupper(trim($m[2]))) {
            return trim($m[1]);
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $company
     * @return list<string> noms affichables, ex. ["Jean DUPONT", "Marie MARTIN"]
     */
    private static function extractLeaders(array $company): array
    {
        $names = [];

        foreach ((array) ($company['dirigeants'] ?? []) as $dirigeant) {
            if (! is_array($dirigeant)) {
                continue;
            }

            // Seules les personnes physiques intéressent un dossier d'indivision —
            // une personne morale dirigeante ne nomme aucun indivisaire.
            if (($dirigeant['type_dirigeant'] ?? 'personne physique') !== 'personne physique') {
                continue;
            }

            $nom = trim((string) ($dirigeant['nom'] ?? ''));
            $prenoms = trim((string) ($dirigeant['prenoms'] ?? ''));

            $nomComplet = trim($prenoms.' '.$nom);

            if ($nomComplet !== '') {
                $names[] = $nomComplet;
            }
        }

        return array_values(array_unique($names));
    }

    /** Clé de Luhn du SIREN, avec l'exception documentée de La Poste (SIREN 356000000). */
    public static function luhn(string $digits): bool
    {
        if (str_starts_with($digits, '356000000')) {
            return strlen($digits) === 9 || array_sum(str_split($digits)) % 5 === 0;
        }

        $sum = 0;
        foreach (array_reverse(str_split($digits)) as $i => $d) {
            $d = (int) $d * ($i % 2 === 1 ? 2 : 1);
            $sum += $d > 9 ? $d - 9 : $d;
        }

        return $sum % 10 === 0;
    }

    /**
     * Fonctionnalité coupée, ou utilisateur trop pressé.
     *
     * Le débit n'est pas une facturation : c'est ce qui évite qu'un clic répété martèle
     * l'API Recherche d'entreprises depuis une instance partagée (même raisonnement que DVF).
     *
     * @throws SireneUnavailable
     */
    private function guard(): void
    {
        if (! (bool) config('opendata.sirene.enabled')) {
            throw SireneUnavailable::disabled();
        }

        // Principal (plan des dossiers liés § 2.3) : plusieurs dossiers ne doivent pas
        // chacun hériter de leur propre compteur, qui multiplierait le débit autorisé.
        $key = 'sirene:'.(\App\Support\DossierContext::principal(Auth::user())?->id ?? 'guest');

        if (RateLimiter::tooManyAttempts($key, (int) config('opendata.sirene.rate_limit'))) {
            throw SireneUnavailable::throttled();
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * @throws SireneUnavailable
     */
    private function fetch(string $siren): ?array
    {
        try {
            $response = Http::timeout((int) config('opendata.sirene.timeout'))
                ->retry(2, 200, throw: false)
                ->acceptJson()
                ->get(rtrim((string) config('opendata.sirene.base_url'), '/').'/search', [
                    'q' => $siren,
                    'per_page' => 5,
                ]);
        } catch (\Throwable) {
            throw SireneUnavailable::network();
        }

        if (! $response->successful()) {
            throw SireneUnavailable::network();
        }

        // La recherche est plein texte : on ne garde que la fiche au SIREN exact.
        foreach ((array) $response->json('results') as $result) {
            if (($result['siren'] ?? null) === $siren) {
                return $result;
            }
        }

        return null;
    }

    private function cachePath(string $siren): string
    {
        return storage_path('app/private/sirene/'.preg_replace('/[^0-9]/', '', $siren).'.json');
    }

    /** @return array<string, mixed>|null */
    private function read(string $path): ?array
    {
        $decoded = json_decode((string) @file_get_contents($path), true);

        if (! is_array($decoded) || ! array_key_exists('company', $decoded)) {
            return null;
        }

        return $decoded['company'];
    }

    /** @param  array<string, mixed>|null  $company */
    private function write(string $path, ?array $company): void
    {
        // Un cache qu'on n'arrive pas à écrire (disque plein, droits) ne doit jamais faire
        // échouer une vérification : on rappellera l'API, c'est tout.
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }

        // Enveloppe { company: ... } : un SIREN introuvable est un résultat légitime (`null`),
        // à distinguer d'un fichier de cache absent — sans elle, `json_decode` d'un simple
        // `null` ne se distingue pas d'un fichier illisible.
        @file_put_contents($path, json_encode(['company' => $company]));
    }
}
