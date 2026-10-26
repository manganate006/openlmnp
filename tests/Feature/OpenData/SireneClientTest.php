<?php

use App\Services\OpenData\SireneClient;
use App\Services\OpenData\SireneUnavailable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * `SireneClient` — accès à l'API Recherche d'entreprises (DINUM, données Insee), sur le
 * patron de `DvfClient` : aucun appel réseau réel, `Http::fake()` couvre chaque scénario.
 *
 * ⚠️ `Http::fake()` cumule ses stubs : le PREMIER enregistré répond. Un scénario par test,
 * jamais deux réponses empilées pour la même URL dans le même test.
 */
const SIREN_VALIDE = '120027016'; // SIREN réel (Insee), passe la clé de Luhn.

beforeEach(function () {
    RateLimiter::clear('sirene:guest');
    // Le cache est un FICHIER, comme DVF : le vider entre les tests, pas seulement le cache
    // Laravel, sans quoi un relevé laissé par un test précédent fausserait le suivant.
    File::deleteDirectory(storage_path('app/private/sirene'));
});

/** @return array<string, mixed> */
function sireneCompany(array $overrides = []): array
{
    return array_replace([
        'siren' => SIREN_VALIDE,
        'nom_complet' => 'SCI EXEMPLE',
        'statut_diffusion' => 'O',
        'etat_administratif' => 'A',
        'date_creation' => '2018-03-01',
        'date_fermeture' => null,
        'activite_principale' => '68.20A',
    ], $overrides);
}

function fakeSirene(array $results): void
{
    Http::fake([
        'recherche-entreprises.api.gouv.fr/*' => Http::response([
            'results' => $results,
            'total_results' => count($results),
        ]),
    ]);
}

// === Luhn ===

it('valide un SIREN réel', function () {
    expect(SireneClient::luhn(SIREN_VALIDE))->toBeTrue();
});

it('refuse un SIREN dont la clé de Luhn est fausse', function () {
    expect(SireneClient::luhn('123456789'))->toBeFalse();
});

it('connaît l\'exception documentée de La Poste', function () {
    // 356000000 : seul SIREN français à ne pas respecter Luhn — la somme des chiffres
    // doit être un multiple de 5.
    expect(SireneClient::luhn('356000000'))->toBeTrue();
});

// === Lookup : scénarios réalistes ===

it('décrit une entreprise active au code 68.20A (location longue durée)', function () {
    fakeSirene([sireneCompany()]);

    $company = app(SireneClient::class)->lookup(SIREN_VALIDE);
    $described = SireneClient::describe($company);

    expect($described['active'])->toBeTrue()
        ->and($described['ape'])->toBe('68.20A')
        ->and($described['rental'][0])->toBe('Location de logements')
        ->and($described['name'])->toBe('SCI EXEMPLE')
        ->and($described['created_on'])->toBe('2018-03-01');
});

it('décrit une entreprise active au code 55.20Z (location saisonnière type Airbnb)', function () {
    fakeSirene([sireneCompany(['activite_principale' => '55.20Z'])]);

    $described = SireneClient::describe(app(SireneClient::class)->lookup(SIREN_VALIDE));

    expect($described['active'])->toBeTrue()
        ->and($described['ape'])->toBe('55.20Z')
        ->and($described['rental'][0])->toBe('Hébergement touristique et autre hébergement de courte durée');
});

it('signale une entreprise cessée avec sa date de fermeture', function () {
    fakeSirene([sireneCompany(['etat_administratif' => 'C', 'date_fermeture' => '2024-11-30'])]);

    $described = SireneClient::describe(app(SireneClient::class)->lookup(SIREN_VALIDE));

    expect($described['active'])->toBeFalse()
        ->and($described['closed_on'])->toBe('2024-11-30');
});

it('rend le code APE sans le classer en location quand il n\'est ni 68.20A ni 55.20Z', function () {
    fakeSirene([sireneCompany(['activite_principale' => '47.11Z'])]);

    $described = SireneClient::describe(app(SireneClient::class)->lookup(SIREN_VALIDE));

    expect($described['ape'])->toBe('47.11Z')
        ->and($described['rental'])->toBeNull();
});

it('masque le nom d\'une entreprise en diffusion partielle', function () {
    fakeSirene([sireneCompany(['statut_diffusion' => 'P'])]);

    $described = SireneClient::describe(app(SireneClient::class)->lookup(SIREN_VALIDE));

    expect($described['name'])->toBeNull()
        // Le reste de la fiche (actif, APE...) n'est pas concerné par la diffusion partielle.
        ->and($described['active'])->toBeTrue();
});

it('rend null quand le SIREN recherché n\'est pas dans les résultats', function () {
    // La recherche est plein texte : elle peut répondre avec d'autres entreprises.
    fakeSirene([sireneCompany(['siren' => '999999999'])]);

    expect(app(SireneClient::class)->lookup(SIREN_VALIDE))->toBeNull();
});

it('rend null sans résultat du tout', function () {
    fakeSirene([]);

    expect(app(SireneClient::class)->lookup(SIREN_VALIDE))->toBeNull();
});

it('lève une exception quand l\'API répond en erreur', function () {
    Http::fake(['recherche-entreprises.api.gouv.fr/*' => Http::response('', 500)]);

    expect(fn () => app(SireneClient::class)->lookup(SIREN_VALIDE))
        ->toThrow(SireneUnavailable::class);
});

// === Interrupteur et débit ===

it('peut être coupée entièrement, sans le moindre appel sortant', function () {
    config(['opendata.sirene.enabled' => false]);
    Http::fake();

    expect(fn () => app(SireneClient::class)->lookup(SIREN_VALIDE))
        ->toThrow(SireneUnavailable::class, 'désactivée');

    Http::assertNothingSent();
});

it('limite les vérifications répétées', function () {
    config(['opendata.sirene.rate_limit' => 1]);
    fakeSirene([sireneCompany()]);

    app(SireneClient::class)->lookup(SIREN_VALIDE);

    expect(fn () => app(SireneClient::class)->lookup(SIREN_VALIDE))
        ->toThrow(SireneUnavailable::class, 'Trop de vérifications');
});

// === Cache fichier : hors ligne après une première vérification, comme DVF ===
//
// ⚠️ `Http::fake()` CUMULE ses stubs : appelé deux fois avec le même motif d'URL dans le
// même test, c'est le PREMIER enregistré qui répond, jamais le second — un piège déjà
// rencontré ailleurs dans ce projet. Les scénarios à deux requêtes réelles ci-dessous
// passent donc par une SEULE fake, avec `Http::sequence()` pour varier la réponse.

it('ne refait pas d\'appel réseau tant que le cache est dans les temps', function () {
    fakeSirene([sireneCompany()]);

    app(SireneClient::class)->lookup(SIREN_VALIDE);
    app(SireneClient::class)->lookup(SIREN_VALIDE);

    // Le cache est un FICHIER : il survit à `cache:clear`, contrairement au cache Laravel.
    Http::assertSentCount(1);
});

it('ne refait pas d\'appel réseau pour un SIREN déjà vérifié et introuvable', function () {
    fakeSirene([]);

    expect(app(SireneClient::class)->lookup(SIREN_VALIDE))->toBeNull();
    expect(app(SireneClient::class)->lookup(SIREN_VALIDE))->toBeNull();

    Http::assertSentCount(1);
});

it('sert un résultat périmé si le réseau est coupé au-delà de la durée de cache', function () {
    config(['opendata.sirene.cache_days' => 1]);
    Http::fake([
        'recherche-entreprises.api.gouv.fr/*' => Http::sequence()
            ->push(['results' => [sireneCompany()], 'total_results' => 1])
            ->push('', 500),
    ]);

    app(SireneClient::class)->lookup(SIREN_VALIDE);

    // Hors du délai de cache configuré : le second appel tente le réseau, qui échoue.
    touch(storage_path('app/private/sirene/'.SIREN_VALIDE.'.json'), now()->subDays(2)->timestamp);

    $described = SireneClient::describe(app(SireneClient::class)->lookup(SIREN_VALIDE));

    // La donnée périmée est resservie, pas une exception — un relevé vieux de quelques
    // jours vaut mieux que rien, même raisonnement que DVF.
    expect($described['active'])->toBeTrue();
});

it('relance l\'API et reflète les nouvelles données au-delà de la durée de cache', function () {
    config(['opendata.sirene.cache_days' => 1]);
    Http::fake([
        'recherche-entreprises.api.gouv.fr/*' => Http::sequence()
            ->push(['results' => [sireneCompany()], 'total_results' => 1])
            ->push(['results' => [sireneCompany(['etat_administratif' => 'C', 'date_fermeture' => '2026-01-01'])], 'total_results' => 1]),
    ]);

    app(SireneClient::class)->lookup(SIREN_VALIDE);

    touch(storage_path('app/private/sirene/'.SIREN_VALIDE.'.json'), now()->subDays(2)->timestamp);

    $described = SireneClient::describe(app(SireneClient::class)->lookup(SIREN_VALIDE));

    expect($described['active'])->toBeFalse();
});

it('distingue une absence en cache d\'une panne réseau après expiration du cache', function () {
    // « Introuvable » (résultat légitime `null`) doit rester distinct d'un cache absent :
    // sinon, un SIREN inconnu redeviendrait une erreur réseau dès que le cache expire.
    config(['opendata.sirene.cache_days' => 1]);
    Http::fake([
        'recherche-entreprises.api.gouv.fr/*' => Http::sequence()
            ->push(['results' => [], 'total_results' => 0])
            ->push('', 500),
    ]);

    expect(app(SireneClient::class)->lookup(SIREN_VALIDE))->toBeNull();

    touch(storage_path('app/private/sirene/'.SIREN_VALIDE.'.json'), now()->subDays(2)->timestamp);

    expect(app(SireneClient::class)->lookup(SIREN_VALIDE))->toBeNull();
});

// === Forme juridique (dossiers d'indivision) ===

it('reconnaît une indivision entre personnes physiques (2110)', function () {
    fakeSirene([sireneCompany(['nature_juridique' => '2110', 'nombre_etablissements_ouverts' => 1])]);

    $described = SireneClient::describe(app(SireneClient::class)->lookup(SIREN_VALIDE));

    expect($described['legal_form'])->toBe('2110')
        ->and($described['legal_form_label'])->toBe('Indivision entre personnes physiques')
        ->and($described['indivision'])->toBeTrue()
        ->and($described['open_establishments'])->toBe(1);
});

it('ne prend pas un entrepreneur individuel pour une indivision', function () {
    fakeSirene([sireneCompany(['nature_juridique' => '1000'])]);

    $described = SireneClient::describe(app(SireneClient::class)->lookup(SIREN_VALIDE));

    expect($described['indivision'])->toBeFalse()
        ->and($described['legal_form_label'])->toBe('Entrepreneur individuel');
});

it('n\'invente pas de libellé pour une forme juridique non répertoriée', function () {
    fakeSirene([sireneCompany(['nature_juridique' => '6540'])]);

    $described = SireneClient::describe(app(SireneClient::class)->lookup(SIREN_VALIDE));

    expect($described['legal_form'])->toBe('6540')
        ->and($described['legal_form_label'])->toBeNull()
        ->and($described['indivision'])->toBeFalse();
});

it('relit le registre en lecture fraîche au lieu de resservir le cache', function () {
    Http::fakeSequence('recherche-entreprises.api.gouv.fr/*')
        ->push(['results' => [sireneCompany(['nature_juridique' => '2110'])], 'total_results' => 1])
        ->push(['results' => [sireneCompany(['nature_juridique' => '2110', 'etat_administratif' => 'C'])], 'total_results' => 1]);
    $client = app(SireneClient::class);

    expect(SireneClient::describe($client->lookup(SIREN_VALIDE))['active'])->toBeTrue()
        ->and(SireneClient::describe($client->lookup(SIREN_VALIDE))['active'])->toBeTrue()
        ->and(SireneClient::describe($client->lookup(SIREN_VALIDE, fresh: true))['active'])->toBeFalse();
});

it('ne ressert pas un relevé périmé en lecture fraîche quand le registre est injoignable', function () {
    fakeSirene([sireneCompany(['nature_juridique' => '2110'])]);
    app(SireneClient::class)->lookup(SIREN_VALIDE);

    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake(['recherche-entreprises.api.gouv.fr/*' => Http::response('', 503)]);

    expect(fn () => app(SireneClient::class)->lookup(SIREN_VALIDE, fresh: true))
        ->toThrow(SireneUnavailable::class);
});
