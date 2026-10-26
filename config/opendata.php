<?php

return [

    /*
    |--------------------------------------------------------------------------
    | DVF — « Demandes de valeurs foncières » (DGFiP)
    |--------------------------------------------------------------------------
    |
    | Estimation de la valeur vénale d'un bien à partir des ventes réelles de sa
    | commune, publiées sur data.gouv.fr sous Licence Ouverte 2.0. Fichiers PAR
    | COMMUNE (20 Ko à 4 Mo), sans clé ni quota : le fichier national de 523 Mo
    | n'est jamais téléchargé.
    |
    | ⚠️ APPEL SORTANT. Elle révèle la COMMUNE du bien à data.gouv.fr. Elle n'est
    | déclenchée que par une action explicite (bouton « Estimer »), jamais au
    | chargement d'un écran, et se coupe entièrement avec DVF_ENABLED=false. Une
    | fois une commune consultée, le résultat est conservé sur disque : l'instance
    | refonctionne hors ligne.
    |
    | ⚠️ Ne pas écrire ici COMBIEN d'appels sortants le logiciel compte. Cette
    | phrase a dit « la seule », puis « l'une des deux seules », et elle était
    | fausse les deux fois — à la fusion du 2026-10-01 il y en avait quatre à la
    | demande (DVF, autocomplétion d'adresse, Sirene, DPE) plus un import planifié
    | (Carte des loyers). Un commentaire qui dénombre se périme en silence et finit
    | par IMPOSER le chiffre faux au lecteur.
    |
    | La liste qui fait foi est CE FICHIER : chaque section ci-dessous est un appel
    | sortant, chacune porte son interrupteur, et aucune ne part sans une action de
    | l'utilisateur — sauf la Carte des loyers, qui est un import planifié et le dit.
    |
    */

    'dvf' => [
        'enabled' => (bool) env('DVF_ENABLED', true),

        'base_url' => env('DVF_BASE_URL', 'https://files.data.gouv.fr/geo-dvf/latest/csv'),

        // API Découpage administratif : commune ou code postal → code INSEE.
        'geo_api_url' => env('DVF_GEO_API_URL', 'https://geo.api.gouv.fr'),

        'timeout' => (int) env('DVF_TIMEOUT', 8),

        // Repli hors ligne UNIQUEMENT : la liste réelle est relevée chaque semaine sur l'index
        // de data.gouv.fr par `dvf:refresh-years` et stockée dans
        // `storage/app/private/dvf/years.json`, qui fait foi dès qu'il existe.
        'years' => [2021, 2022, 2023, 2024, 2025],

        // En deçà, on refuse d'afficher une médiane plutôt que d'en proposer une fragile
        // comme base amortissable.
        'min_sample' => (int) env('DVF_MIN_SAMPLE', 5),

        'cache_days' => (int) env('DVF_CACHE_DAYS', 30),

        // Recherches par utilisateur et par minute (garde-fou, pas une facturation).
        'rate_limit' => (int) env('DVF_RATE_LIMIT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Base Adresse Nationale — autocomplétion d'adresse
    |--------------------------------------------------------------------------
    |
    | API Adresse, servie par la Géoplateforme de l'IGN (data.geopf.fr/geocodage) :
    | service public, gratuit, sans clé, CONÇU pour l'autocomplétion. Il rend adresse, code postal, commune et code INSEE, ce qui
    | supprime d'un coup les incohérences entre ces champs.
    |
    | (Nominatim/OpenStreetMap a été écarté : sa politique d'usage interdit
    | explicitement l'autocomplétion au fil de la frappe.)
    |
    | L'ancienne adresse, api-adresse.data.gouv.fr, répond encore mais n'est plus
    | celle que référence data.gouv.fr : c'est elle qui risque d'être coupée.
    |
    | ⚠️ APPEL SORTANT, et le plus bavard des deux : il révèle à la Géoplateforme le
    | début d'adresse en cours de frappe. D'où les trois garde-fous ci-dessous —
    | longueur minimale, anti-rebond côté navigateur, limite de débit — et un
    | interrupteur : à `enabled = false`, le script n'est même pas injecté dans les
    | pages, et l'endpoint ne rend plus rien. La saisie manuelle, elle, reste
    | toujours possible : aucune validation ne dépend de ce service.
    |
    */

    'address' => [
        'enabled' => (bool) env('ADDRESS_LOOKUP_ENABLED', true),

        'url' => env('ADDRESS_LOOKUP_URL', 'https://data.geopf.fr/geocodage/search/'),

        'timeout' => (int) env('ADDRESS_LOOKUP_TIMEOUT', 3),

        // En deçà, aucune requête ne part. Rejoué CÔTÉ SERVEUR : le navigateur n'est
        // pas une autorisation, l'URL est appelable à la main.
        'min_length' => (int) env('ADDRESS_LOOKUP_MIN_LENGTH', 3),

        // Anti-rebond du navigateur, en millisecondes. Sans lui, une adresse de
        // trente caractères déclencherait trente requêtes sur un service gratuit.
        'debounce_ms' => (int) env('ADDRESS_LOOKUP_DEBOUNCE_MS', 300),

        'limit' => (int) env('ADDRESS_LOOKUP_LIMIT', 5),

        // Recherches par utilisateur et par minute (garde-fou, pas une facturation).
        'rate_limit' => (int) env('ADDRESS_LOOKUP_RATE_LIMIT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sirene — API Recherche d'entreprises (DINUM, données Insee)
    |--------------------------------------------------------------------------
    |
    | Vérifie un SIREN saisi dans le profil : actif ou cessé, date de création, code APE
    | et sa signification pour un loueur en meublé. `https://recherche-entreprises.api.gouv.fr`,
    | sans clé, 7 requêtes/s/IP (documentation OpenAPI, relevée le 2026-09-30).
    |
    | ⚠️ APPEL SORTANT, sur clic explicite uniquement (bouton « Vérifier dans Sirene ») — jamais
    | au chargement d'un écran. Le SIREN saisi est transmis, rien d'autre. Se coupe entièrement
    | avec SIRENE_ENABLED=false. Comme DVF, le résultat est mis en cache sur disque : une
    | instance auto-hébergée qui a déjà vérifié son SIREN continue de fonctionner hors ligne.
    |
    */

    'sirene' => [
        'enabled' => (bool) env('SIRENE_ENABLED', true),

        'base_url' => env('SIRENE_BASE_URL', 'https://recherche-entreprises.api.gouv.fr'),

        'timeout' => (int) env('SIRENE_TIMEOUT', 5),

        'cache_days' => (int) env('SIRENE_CACHE_DAYS', 30),

        // Vérifications par utilisateur et par minute (garde-fou, pas une facturation).
        'rate_limit' => (int) env('SIRENE_RATE_LIMIT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | DPE — Diagnostic de performance énergétique (ADEME)
    |--------------------------------------------------------------------------
    |
    | Retrouve le DPE d'un bien dans la base de l'ADEME (`dpe03existant`,
    | data-fair, publiée depuis juillet 2021 sous Licence Ouverte 2.0), à partir
    | de son adresse — géocodée par la Géoplateforme (IGN) en identifiant BAN —
    | ou d'un numéro de DPE saisi à la main.
    |
    | ⚠️ APPEL SORTANT, ET IL RÉVÈLE L'ADRESSE DU BIEN (plus précis que la seule
    | commune envoyée par l'estimation DVF ci-dessus). Action explicite
    | uniquement (bouton « Retrouver le DPE »), jamais au chargement d'un écran,
    | et se coupe entièrement avec DPE_ENABLED=false.
    |
    */

    'dpe' => [
        'enabled' => (bool) env('DPE_ENABLED', true),

        'base_url' => env('DPE_BASE_URL', 'https://data.ademe.fr/data-fair/api/v1/datasets/dpe03existant'),

        // Géocodage IGN : adresse → identifiant BAN (`dpe03existant` n'est interrogeable
        // qu'avec un identifiant exact, jamais par adresse en texte libre).
        'geocode_url' => env('DPE_GEOCODE_URL', 'https://data.geopf.fr/geocodage/search'),

        'timeout' => (int) env('DPE_TIMEOUT', 8),

        // Courte durée : contrairement au cache DVF (fichier communal, réutilisé par tous
        // les utilisateurs d'une même commune), une recherche DPE est propre à une adresse
        // précise — elle n'amortit que les clics répétés sur la même fiche.
        'cache_minutes' => (int) env('DPE_CACHE_MINUTES', 15),

        // Recherches par utilisateur et par minute (garde-fou, pas une facturation).
        'rate_limit' => (int) env('DPE_RATE_LIMIT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Carte des loyers — repère de loyer d'annonce par commune (ANIL)
    |--------------------------------------------------------------------------
    |
    | Contrairement à DVF, aucun appel réseau n'est déclenché par un utilisateur :
    | `openlmnp:refresh-rent-map --if-missing`, planifiée chaque jour, DÉCOUVRE le millésime
    | suivant sur l'API data.gouv.fr (voir `App\Services\OpenData\RentMapDiscovery`), vérifie
    | le format de ses 4 CSV puis les importe dans `rent_map_indicators`. Le simulateur, lui,
    | ne fait que LIRE cette table, jamais le réseau.
    |
    */

    'rent_map' => [
        // Couvre à la fois la commande planifiée et l'affichage dans le simulateur : une
        // instance qui veut rester silencieuse vis-à-vis de data.gouv.fr coupe tout ici.
        'enabled' => (bool) env('RENT_MAP_ENABLED', true),

        // API data.gouv.fr — `/{slug}/` renvoie les ressources (donc les URLs de CSV) d'un
        // millésime donné, ou une 404 s'il n'est pas encore publié.
        'api_base_url' => env('RENT_MAP_API_BASE_URL', 'https://www.data.gouv.fr/api/1/datasets'),

        // Page PUBLIQUE du jeu (citée à l'affichage et dans l'aide) — même domaine que
        // l'API, chemin différent ; l'URL exacte se calcule à partir du même slug.
        'page_base_url' => env('RENT_MAP_PAGE_BASE_URL', 'https://www.data.gouv.fr/datasets'),

        // Timeout de la DÉCOUVERTE (l'appel JSON qui liste les ressources d'un millésime) —
        // pas du téléchargement des CSV eux-mêmes, qui reste sur son propre budget (fichiers
        // de plusieurs Mo, cf. RefreshRentMap::DOWNLOAD_TIMEOUT).
        'timeout' => (int) env('RENT_MAP_TIMEOUT', 8),
    ],

];
