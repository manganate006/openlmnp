<?php

use App\Filament\Pages\OnboardingWizard;
use App\Filament\Pages\RepriseDossier;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Autocomplétion d'adresse par la Base Adresse Nationale.
 *
 * Deux exigences, et la seconde compte plus que la première :
 *   1. choisir une suggestion remplit adresse, code postal ET commune ;
 *   2. **rien ne casse quand le service est tombé.** Un formulaire de bien qu'on ne peut
 *      plus valider parce qu'un service public est en maintenance serait une régression
 *      bien pire que l'absence d'autocomplétion. Les pannes sont donc jouées ici
 *      explicitement — réseau coupé, erreur HTTP, fonctionnalité désactivée — et toutes
 *      doivent se solder par une liste vide servie en 200, jamais par une erreur.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
});

/** Réponse GeoJSON de la BAN, réduite à ce que le logiciel en lit. */
function banPayload(): array
{
    return [
        'type' => 'FeatureCollection',
        'features' => [
            [
                'properties' => [
                    'label' => '8 Boulevard du Port 80000 Amiens',
                    'name' => '8 Boulevard du Port',
                    'postcode' => '80000',
                    'city' => 'Amiens',
                    'citycode' => '80021',
                ],
            ],
            [
                'properties' => [
                    'label' => '8 Boulevard du Port 44380 Pornichet',
                    'name' => '8 Boulevard du Port',
                    'postcode' => '44380',
                    'city' => 'Pornichet',
                    'citycode' => '44132',
                ],
            ],
        ],
    ];
}

it('rend adresse, code postal, commune et code INSEE pour chaque suggestion', function () {
    Http::fake(['data.geopf.fr/*' => Http::response(banPayload())]);

    $results = $this->actingAs($this->user)
        ->getJson('/adresse/recherche?q=8 boulevard du port')
        ->assertOk()
        ->json('results');

    expect($results)->toHaveCount(2)
        ->and($results[0])->toBe([
            // Le libellé complet distingue deux voies homonymes dans la liste…
            'label' => '8 Boulevard du Port 80000 Amiens',
            // …mais c'est la voie SEULE qui va dans le champ « Adresse » : y recopier la
            // ville la ferait apparaître deux fois sur la fiche du bien.
            'address' => '8 Boulevard du Port',
            'postal_code' => '80000',
            'city' => 'Amiens',
            'insee_code' => '80021',
        ]);
});

it('n\'interroge pas le service avant trois caractères', function () {
    Http::fake();

    $this->actingAs($this->user)->getJson(route('address.search', ['q' => '8 ']))->assertOk()->assertJson(['results' => []]);
    $this->actingAs($this->user)->getJson(route('address.search', ['q' => '']))->assertOk()->assertJson(['results' => []]);

    // ⚠️ La garde est rejouée CÔTÉ SERVEUR : l'anti-rebond du navigateur ne protège pas
    // un service public gratuit, l'URL étant appelable à la main.
    Http::assertNothingSent();
});

it('demande cinq suggestions au plus, en mode autocomplétion', function () {
    Http::fake(['data.geopf.fr/*' => Http::response(banPayload())]);

    $this->actingAs($this->user)->getJson('/adresse/recherche?q=rue de la paix')->assertOk();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'limit=5')
            && str_contains($request->url(), 'autocomplete=1');
    });
});

it('interroge la Géoplateforme, pas l\'ancienne adresse de l\'API', function () {
    Http::fake(['data.geopf.fr/*' => Http::response(banPayload())]);

    $this->actingAs($this->user)->getJson('/adresse/recherche?q=rue de la paix')->assertOk();

    // data.gouv.fr référence désormais l'API Adresse sur data.geopf.fr/geocodage.
    // api-adresse.data.gouv.fr répond encore, mais c'est elle qui risque d'être coupée.
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://data.geopf.fr/geocodage/search/'));
});

it('laisse la saisie manuelle intacte quand le service ne répond pas', function () {
    // Réseau coupé, DNS muet, délai dépassé : l'exception ne doit JAMAIS remonter à
    // l'écran. Sans ce filet, le formulaire deviendrait invalidable pour une panne
    // qui ne le concerne pas.
    Http::fake(fn () => throw new ConnectionException('data.geopf.fr injoignable'));

    $this->actingAs($this->user)
        ->getJson('/adresse/recherche?q=8 boulevard du port')
        ->assertOk()
        ->assertExactJson(['results' => [], 'unavailable' => true]);
});

it('laisse la saisie manuelle intacte quand le service rend une erreur', function () {
    Http::fake(['data.geopf.fr/*' => Http::response('Service Unavailable', 503)]);

    $this->actingAs($this->user)
        ->getJson('/adresse/recherche?q=8 boulevard du port')
        ->assertOk()
        ->assertExactJson(['results' => [], 'unavailable' => true]);
});

it('ne sort rien de l\'instance quand l\'autocomplétion est désactivée', function () {
    config(['opendata.address.enabled' => false]);
    Http::fake();

    $this->actingAs($this->user)
        ->getJson('/adresse/recherche?q=8 boulevard du port')
        ->assertOk()
        ->assertExactJson(['results' => [], 'unavailable' => true]);

    Http::assertNothingSent();

    // Et le script n'est même pas injecté : rien ne peut appeler quoi que ce soit.
    $this->actingAs($this->user)->get('/')->assertOk()->assertDontSee('olmnpAddressAutocomplete');
});

it('enregistre un bien saisi à la main pendant que le service est tombé', function () {
    // ⚠️ Le vrai test de la panne. Les trois précédents vérifient que l'ENDPOINT ne
    // renvoie pas d'erreur ; celui-ci vérifie ce qui compte pour l'utilisateur : que le
    // formulaire reste VALIDABLE. Un bien qu'on ne peut plus créer parce qu'un service
    // public est en maintenance serait une régression bien pire que l'absence
    // d'autocomplétion — l'adresse se tape très bien à la main.
    Http::fake(fn () => throw new ConnectionException('data.geopf.fr injoignable'));

    Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2026)
        ->call('nextStep')
        ->set('propertyName', 'Studio hors ligne')
        ->set('propertyAddress', '3 impasse des Serveurs Muets')
        ->set('propertyCity', 'Bordeaux')
        ->set('propertyPostalCode', '33000')
        ->set('propertyArea', '30')
        ->set('acquisitionPrice', '100000')
        ->call('nextStep')
        ->assertHasNoErrors();

    $property = \App\Models\Property::withoutGlobalScopes()->where('user_id', $this->user->id)->firstOrFail();

    expect($property->address)->toBe('3 impasse des Serveurs Muets')
        ->and($property->city)->toBe('Bordeaux')
        ->and($property->postal_code)->toBe('33000');
});

it('laisse les champs d\'adresse librement modifiables', function () {
    // Une autocomplétion qui verrouillerait le champ interdirait les adresses que la BAN
    // ne connaît pas — lieux-dits, voies récentes, biens à l'étranger.
    $html = $this->actingAs($this->user)->get('/properties/create')->assertOk()->getContent();

    preg_match_all('/<input\b[^>]*data-address-role="[^"]+"[^>]*>/i', $html, $inputs);

    expect($inputs[0])->not->toBeEmpty();

    foreach ($inputs[0] as $input) {
        expect($input)->not->toContain('readonly')
            ->and($input)->not->toContain('disabled');
    }
});

it('écarte une suggestion sans commune plutôt que de remplir à moitié', function () {
    Http::fake(['data.geopf.fr/*' => Http::response([
        'features' => [
            ['properties' => ['label' => 'Lieu-dit sans commune', 'name' => 'Lieu-dit']],
            ['properties' => ['label' => 'Amiens', 'name' => 'Amiens', 'city' => 'Amiens', 'postcode' => '80000', 'citycode' => '80021']],
        ],
    ])]);

    $results = $this->actingAs($this->user)
        ->getJson('/adresse/recherche?q=lieu dit')
        ->assertOk()
        ->json('results');

    // Une suggestion incomplète laisserait croire au formulaire qu'il est renseigné.
    expect($results)->toHaveCount(1)->and($results[0]['city'])->toBe('Amiens');
});

it('refuse la recherche à un visiteur non connecté', function () {
    Http::fake();

    // Même convention que le matériel de clé du coffre : un endpoint JSON répond 401,
    // il ne renvoie pas vers une page de connexion.
    $this->getJson(route('address.search', ['q' => '8 boulevard du port']))->assertStatus(401);

    Http::assertNothingSent();
});

it('pose le composant sur tous les écrans qui portent une adresse', function () {
    // ⚠️ Le point du lot : UN composant, pas trois branchements. Si l'un des écrans perd
    // ses attributs, il perd l'autocomplétion en silence — rien d'autre ne le dirait.
    $screens = [
        'fiche d\'un bien' => $this->actingAs($this->user)->get('/properties/create')->assertOk()->getContent(),
        'premier lancement' => Livewire::actingAs($this->user)->test(OnboardingWizard::class)->assertOk()->html(),
        'reprise de dossier' => Livewire::actingAs($this->user)
            ->test(RepriseDossier::class)
            ->set('rentalStartDate', '2019-06-01')
            ->set('firstYear', 2026)
            ->call('nextStep')
            ->assertSet('step', 2)
            ->html(),
    ];

    foreach ($screens as $screen => $html) {
        foreach (['address', 'city', 'postal_code'] as $role) {
            // ⚠️ Un seul argument par appel : `toContain()` prend une LISTE d'aiguilles,
            // et un message passé en second argument y serait cherché dans le HTML —
            // le test échouerait sur sa propre explication.
            expect($html)->toContain('data-address-group="'.($screen === 'reprise de dossier' ? 'reprise' : 'property').'"');
            expect($html)->toContain('data-address-role="'.$role.'"');
        }
    }
});

it('charge le script d\'autocomplétion une fois pour tout le panel', function () {
    $html = $this->actingAs($this->user)->get('/properties/create')->assertOk()->getContent();

    // ⚠️ `@json()` échappe les barres obliques : l'URL apparaît sous sa forme JSON
    // (`http:\/\/…`), et la chercher telle que `route()` la rend ne trouve rien.
    expect($html)->toContain('olmnpAddressAutocomplete')
        ->toContain(trim(json_encode(route('address.search')), '"'))
        // Les deux garde-fous de débit sont dans le script rendu, pas seulement dans
        // l'intention : trois caractères minimum, et un anti-rebond de 300 ms.
        ->toContain('MIN_CHARS = 3')
        ->toContain('DEBOUNCE_MS = 300');
});
