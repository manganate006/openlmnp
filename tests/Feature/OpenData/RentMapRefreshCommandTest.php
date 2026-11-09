<?php

use App\Services\OpenData\RentMapDiscovery;
use App\Services\OpenData\RentMapSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * `openlmnp:refresh-rent-map` — téléchargement et import des 4 CSV de la « Carte des loyers ».
 *
 * ⚠️ Contrairement à DVF, cette commande appelle le réseau HORS de tout clic utilisateur
 * (planifiée en décembre) : le test qui compte n'est donc pas « ne part jamais », comme pour
 * `dvf:refresh-years` à l'affichage, mais « une panne réseau laisse la table dans son état
 * précédent plutôt que de la vider » — une instance auto-hébergée sans accès sortant ne doit
 * jamais perdre un repère déjà importé.
 */
beforeEach(function () {
    File::deleteDirectory(storage_path('app/private/rent-map'));
});

/** @return array<string, string> type => nom de fixture (tests/Fixtures/opendata/rent-map/) */
function rentMapFixtureNames(): array
{
    return [
        RentMapSource::TYPE_APARTMENT => 'apartment',
        RentMapSource::TYPE_APARTMENT_1_2 => 'apartment-1-2',
        RentMapSource::TYPE_APARTMENT_3_PLUS => 'apartment-3-plus',
        RentMapSource::TYPE_HOUSE => 'house',
    ];
}

/**
 * ⚠️ Chaque entrée DOIT être un CALLABLE, jamais un `Http::response(...)` tout fait : ce
 * dernier construit son flux PSR-7 une seule fois, à l'enregistrement du fake, et
 * `Http::sink()` le lit en entier (`getContents()`) — le relire à un second téléchargement
 * réel de la même URL, dans le même test, rendrait silencieusement une réponse VIDE (flux déjà
 * épuisé). Un callable, lui, est ré-invoqué à chaque requête et construit un flux neuf à
 * chaque fois. Voir aussi la règle « Http::fake cumule ses stubs » : cette fonction ne
 * s'appelle donc qu'UNE fois par test, jamais pour changer la réponse en cours de route — un
 * callable qui lit une variable capturée PAR RÉFÉRENCE s'en charge (cf. le test d'idempotence).
 *
 * @param  array<string, \Closure>  $overrides  clé = type de bien
 */
function fakeRentMapDownloads(array $overrides = []): void
{
    $responses = [];

    foreach (RentMapSource::TYPES as $type => $meta) {
        $responses[$meta['url']] = $overrides[$type]
            ?? fn () => Http::response(file_get_contents(base_path('tests/Fixtures/opendata/rent-map/'.rentMapFixtureNames()[$type].'.csv')));
    }

    Http::fake($responses);
}

it('downloads and imports the four property types', function () {
    fakeRentMapDownloads();

    $this->artisan('openlmnp:refresh-rent-map')->assertSuccessful();

    expect(DB::table('rent_map_indicators')->count())->toBe(12);

    $bordeauxApartment = DB::table('rent_map_indicators')
        ->where('insee_code', '33063')
        ->where('property_type', 'apartment')
        ->first();

    expect($bordeauxApartment)->not->toBeNull()
        ->and($bordeauxApartment->per_m2_cents)->toBe(1599)
        ->and($bordeauxApartment->vintage)->toBe(RentMapSource::VINTAGE);
});

it('never stores a row for the Paris/Lyon/Marseille city-wide code, only arrondissements', function () {
    fakeRentMapDownloads();

    $this->artisan('openlmnp:refresh-rent-map')->assertSuccessful();

    expect(DB::table('rent_map_indicators')->where('insee_code', '75101')->exists())->toBeTrue()
        ->and(DB::table('rent_map_indicators')->where('insee_code', '75056')->exists())->toBeFalse();
});

it('is idempotent: running it twice replaces the vintage instead of duplicating rows', function () {
    // Un seul `Http::fake()` pour tout le test (cf. la note de `fakeRentMapDownloads()`) : le
    // contenu de l'appartement change EN COURS DE ROUTE via une variable capturée par
    // référence, relue à chaque appel réel — pas via un second `Http::fake()`.
    $apartmentBody = file_get_contents(base_path('tests/Fixtures/opendata/rent-map/apartment.csv'));

    fakeRentMapDownloads([
        RentMapSource::TYPE_APARTMENT => function () use (&$apartmentBody) {
            return Http::response($apartmentBody);
        },
    ]);

    $this->artisan('openlmnp:refresh-rent-map')->assertSuccessful();
    expect(DB::table('rent_map_indicators')->count())->toBe(12);

    // Deuxième passage, avec un contenu DIFFÉRENT pour un seul type : le prix change,
    // l'échantillon ne doit ni doubler ni garder l'ancienne valeur.
    $apartmentBody = <<<'CSV'
    "id_zone";"INSEE_C";"LIBGEO";"EPCI";"DEP";"REG";"loypredm2";"lwr.IPm2";"upr.IPm2";"TYPPRED";"nbobs_com";"nbobs_mail";"R2_adj"
    "2920";"33063";"Bordeaux";"243300316";"33";"75";20,00;15,00;25,00;"commune";90000;96000;0,85
    CSV;

    $this->artisan('openlmnp:refresh-rent-map')->assertSuccessful();

    expect(DB::table('rent_map_indicators')->where('property_type', 'apartment')->count())->toBe(1)
        ->and(DB::table('rent_map_indicators')->where('property_type', 'apartment')->first()->per_m2_cents)->toBe(2000)
        // Les 3 autres types n'ont pas bougé.
        ->and(DB::table('rent_map_indicators')->count())->toBe(10);
});

it('leaves previously imported rows untouched when one download fails', function () {
    // État précédent simulé pour le type « maison », comme si une exécution antérieure avait
    // réussi. La commande NE DOIT PAS l'effacer si le téléchargement du millésime échoue.
    DB::table('rent_map_indicators')->insert([
        'insee_code' => '33063',
        'property_type' => 'house',
        'vintage' => 2024,
        'per_m2_cents' => 1_000,
        'low_per_m2_cents' => 800,
        'high_per_m2_cents' => 1_200,
        'observations' => 50,
        'mesh_observations' => 50,
        'prediction_level' => 'commune',
        'r2' => 0.7,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    fakeRentMapDownloads([RentMapSource::TYPE_HOUSE => fn () => Http::response('', 500)]);

    $this->artisan('openlmnp:refresh-rent-map')->assertFailed();

    // Les 3 autres types se sont quand même importés.
    expect(DB::table('rent_map_indicators')->where('property_type', '!=', 'house')->count())->toBe(9);

    // Le vieux millésime de la maison est encore là, inchangé.
    $house = DB::table('rent_map_indicators')->where('property_type', 'house')->first();
    expect($house->vintage)->toBe(2024)
        ->and($house->per_m2_cents)->toBe(1_000);
});

it('does not write anything in dry-run', function () {
    fakeRentMapDownloads();

    $this->artisan('openlmnp:refresh-rent-map --dry-run')->assertSuccessful();

    expect(DB::table('rent_map_indicators')->count())->toBe(0);
});

it('stays silent and offline when the feature is disabled', function () {
    config(['opendata.rent_map.enabled' => false]);
    Http::fake();

    $this->artisan('openlmnp:refresh-rent-map')->assertSuccessful();

    Http::assertNothingSent();
    expect(DB::table('rent_map_indicators')->count())->toBe(0);
});

// === --if-missing (planification quotidienne, découverte automatique depuis le 2026-09-30) ===
//
// Sans lui, une planification purement annuelle laisse le repère invisible pendant des mois
// sur une instance déployée entre deux échéances. `--if-missing` est donc planifié tous les
// jours. Le millésime de référence est celui RÉELLEMENT en base (le plus récent), jamais une
// constante : table vide → bootstrap sur le repli ; sinon → tente courant+1, mais SEULEMENT à
// partir du 1er novembre de cette année-là (heure de Paris) — le reste de l'année, aucun appel
// réseau, sans quoi une tâche quotidienne martèlerait data.gouv.fr pour rien.

afterEach(function () {
    Carbon::setTestNow();
});

/** URL exacte de l'appel de découverte pour un millésime (voir RentMapDiscovery::discoverUrls()). */
function rentMapNextVintageApiUrl(int $vintage): string
{
    return rtrim((string) config('opendata.rent_map.api_base_url'), '/').'/'.RentMapDiscovery::slug($vintage).'/';
}

/** Seede les 4 types de bien avec une ligne du millésime donné — comme un import déjà réussi. */
function seedAllTypesAtVintage(int $vintage): void
{
    foreach (array_keys(RentMapSource::TYPES) as $type) {
        DB::table('rent_map_indicators')->insert([
            'insee_code' => '33063',
            'property_type' => $type,
            'vintage' => $vintage,
            'per_m2_cents' => 1_500,
            'low_per_m2_cents' => 1_200,
            'high_per_m2_cents' => 1_800,
            'observations' => 100,
            'mesh_observations' => 100,
            'prediction_level' => 'commune',
            'r2' => 0.8,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

/**
 * Fake la découverte ET le téléchargement des 4 CSV d'un millésime, sous des URLs distinctes
 * de celles (figées) du millésime de repli — pour bien vérifier que ce sont CES URLs
 * découvertes qui sont utilisées, pas celles écrites en dur dans `RentMapSource::TYPES`.
 *
 * @param  array<string, \Closure>  $csvOverrides  clé = type de bien
 * @return array<string, string> les 4 URLs découvertes, clé = type de bien
 */
function fakeRentMapDiscovery(int $vintage, array $csvOverrides = []): array
{
    $timestamp = '20261211-100000';
    $urls = [];
    $stubs = [];

    $suffixes = [
        RentMapSource::TYPE_APARTMENT => 'pred-app-mef-dhup.csv',
        RentMapSource::TYPE_APARTMENT_1_2 => 'pred-app12-mef-dhup.csv',
        RentMapSource::TYPE_APARTMENT_3_PLUS => 'pred-app3-mef-dhup.csv',
        RentMapSource::TYPE_HOUSE => 'pred-mai-mef-dhup.csv',
    ];

    foreach ($suffixes as $type => $suffix) {
        $url = "https://static.data.gouv.fr/resources/carte-des-loyers-{$vintage}/{$timestamp}/{$suffix}";
        $urls[$type] = $url;
        $fixtureName = rentMapFixtureNames()[$type];
        $stubs[$url] = $csvOverrides[$type]
            ?? fn () => Http::response(file_get_contents(base_path('tests/Fixtures/opendata/rent-map/'.$fixtureName.'.csv')));
    }

    $stubs[rentMapNextVintageApiUrl($vintage)] = Http::response([
        'resources' => collect($urls)->map(fn (string $url) => ['url' => $url, 'title' => 'Peu importe ici'])->values()->all(),
    ]);

    Http::fake($stubs);

    return $urls;
}

it('--if-missing sends no request before November 1st of the candidate vintage', function () {
    seedAllTypesAtVintage(2025);
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Europe/Paris'));
    Http::fake();

    $this->artisan('openlmnp:refresh-rent-map --if-missing')->assertSuccessful();

    Http::assertNothingSent();
    expect(DB::table('rent_map_indicators')->where('vintage', 2025)->count())->toBe(4);
});

it('--if-missing leaves the table untouched when the next vintage is not published yet (404)', function () {
    seedAllTypesAtVintage(2025);
    Carbon::setTestNow(Carbon::parse('2026-11-05 09:00:00', 'Europe/Paris'));
    Http::fake([
        rentMapNextVintageApiUrl(2026) => Http::response([], 404),
    ]);

    $this->artisan('openlmnp:refresh-rent-map --if-missing')->assertSuccessful();

    expect(DB::table('rent_map_indicators')->count())->toBe(4)
        ->and(DB::table('rent_map_indicators')->where('vintage', 2025)->count())->toBe(4);
});

it('--if-missing imports the discovered next vintage and purges the previous one', function () {
    seedAllTypesAtVintage(2025);
    Carbon::setTestNow(Carbon::parse('2026-11-05 09:00:00', 'Europe/Paris'));
    fakeRentMapDiscovery(2026);

    $this->artisan('openlmnp:refresh-rent-map --if-missing')->assertSuccessful();

    expect(DB::table('rent_map_indicators')->where('vintage', 2025)->count())->toBe(0)
        ->and(DB::table('rent_map_indicators')->where('vintage', 2026)->count())->toBe(12);
});

it('--if-missing changes nothing when the discovered vintage is incomplete (three files)', function () {
    seedAllTypesAtVintage(2025);
    Carbon::setTestNow(Carbon::parse('2026-11-05 09:00:00', 'Europe/Paris'));

    // Découverte JSON directement fakée, sans passer par fakeRentMapDiscovery() : un seul
    // fichier — maison — n'apparaît pas dans les ressources publiées.
    Http::fake([
        rentMapNextVintageApiUrl(2026) => Http::response(['resources' => [
            ['url' => 'https://static.data.gouv.fr/resources/x/pred-app-mef-dhup.csv', 'title' => 'x'],
            ['url' => 'https://static.data.gouv.fr/resources/x/pred-app12-mef-dhup.csv', 'title' => 'x'],
            ['url' => 'https://static.data.gouv.fr/resources/x/pred-app3-mef-dhup.csv', 'title' => 'x'],
        ]]),
    ]);

    $this->artisan('openlmnp:refresh-rent-map --if-missing')->assertSuccessful();

    expect(DB::table('rent_map_indicators')->count())->toBe(4)
        ->and(DB::table('rent_map_indicators')->where('vintage', 2025)->count())->toBe(4);
});

it('--if-missing changes nothing and logs a warning when a discovered CSV has an unexpected header', function () {
    seedAllTypesAtVintage(2025);
    Carbon::setTestNow(Carbon::parse('2026-11-05 09:00:00', 'Europe/Paris'));

    Log::spy();

    fakeRentMapDiscovery(2026, [
        // En-tête cassé (colonne "loypredm2" renommée) pour un seul des 4 fichiers.
        RentMapSource::TYPE_HOUSE => fn () => Http::response(
            "\"id_zone\";\"INSEE_C\";\"loyer_m2\"\n\"1\";\"33063\";\"15,99\"\n"
        ),
    ]);

    $this->artisan('openlmnp:refresh-rent-map --if-missing')->assertSuccessful();

    expect(DB::table('rent_map_indicators')->where('vintage', 2025)->count())->toBe(4)
        ->and(DB::table('rent_map_indicators')->where('vintage', 2026)->count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'en-tête inattendu'));
});

it('--if-missing bootstraps on the fallback vintage via discovery when the table is empty', function () {
    fakeRentMapDiscovery(RentMapSource::VINTAGE);

    $this->artisan('openlmnp:refresh-rent-map --if-missing')->assertSuccessful();

    expect(DB::table('rent_map_indicators')->count())->toBe(12)
        ->and(DB::table('rent_map_indicators')->where('vintage', RentMapSource::VINTAGE)->count())->toBe(12);
});

it('--if-missing falls back to the hard-coded URLs when the table is empty and the API is unreachable', function () {
    // Découverte NON fakée : `Http::preventStrayRequests()` (Pest.php) la fait échouer comme
    // une vraie panne réseau. Seules les URLs figées de RentMapSource::TYPES sont fakées.
    fakeRentMapDownloads();

    $this->artisan('openlmnp:refresh-rent-map --if-missing')->assertSuccessful();

    expect(DB::table('rent_map_indicators')->count())->toBe(12)
        ->and(DB::table('rent_map_indicators')->where('vintage', RentMapSource::VINTAGE)->count())->toBe(12);
});

// === Garde-fou : allowlist de docker-entrypoint.sh ===

it('propagates RENT_MAP_ENABLED through the docker entrypoint allowlist', function () {
    $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));

    expect($entrypoint)->toContain('RENT_MAP_ENABLED');
});

it('documents RENT_MAP_ENABLED in .env.example', function () {
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->toContain('RENT_MAP_ENABLED');
});
