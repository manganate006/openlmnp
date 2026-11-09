<?php

use App\Enums\DossierMemberRole;
use App\Mcp\OpenLmnpServer;
use App\Models\LinkedDossier;
use App\Models\Property;
use App\Models\User;
use App\Support\DossierContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

function dossierLieDe(User $owner, string $siren): LinkedDossier
{
    $dossierUser = new User;
    $dossierUser->forceFill([
        'name' => 'Indivision '.$siren,
        'email' => 'dossier-'.Str::lower((string) Str::ulid()).'@dossiers.invalid',
        'password' => Hash::make(Str::random(32)),
        'is_linked_dossier' => true,
        'is_indivision' => true,
        'siren' => $siren,
    ])->save();

    $dossier = LinkedDossier::create([
        'public_id' => (string) Str::ulid(),
        'user_id' => $dossierUser->id,
        'owner_id' => $owner->id,
        'kind' => 'indivision',
        'siren' => $siren,
    ]);

    $dossier->members()->create([
        'user_id' => $owner->id,
        'role' => DossierMemberRole::Owner,
        'accepted_at' => now(),
    ]);

    return $dossier;
}

function proprieteDe(User $user, string $suffixe): Property
{
    return Property::create([
        'user_id' => $user->id, 'name' => "Bien {$suffixe}", 'address' => '1 rue du Test',
        'city' => 'Lyon', 'postal_code' => '69003', 'type' => 'apartment',
        'total_area' => 45, 'rented_area' => 45, 'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000, 'land_percentage' => 15,
        'rental_start_date' => '2022-03-01', 'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
}

beforeEach(function () {
    config(['dossiers.enabled' => true, 'services.provisioning.token' => null, 'dossiers.quota' => null]);
});

it('isole les biens entre le principal, son dossier lié et un AUTRE dossier lié', function () {
    $principal1 = User::factory()->create();
    $dossier1 = dossierLieDe($principal1, '100000009');

    $principal2 = User::factory()->create();
    $dossier2 = dossierLieDe($principal2, '100000017');

    proprieteDe($principal1, 'du principal 1');
    proprieteDe($dossier1->user, 'du dossier 1');
    proprieteDe($principal2, 'du principal 2');
    proprieteDe($dossier2->user, 'du dossier 2');

    $this->actingAs($principal1);
    expect(Property::count())->toBe(1)
        ->and(Property::first()->name)->toBe('Bien du principal 1');

    $this->post(route('filament.admin.dossiers.open', $dossier1->public_id));
    expect(Property::count())->toBe(1)
        ->and(Property::first()->name)->toBe('Bien du dossier 1');

    // Jamais les biens du SECOND dossier, propriété d'un autre principal.
    expect(Property::withoutGlobalScopes()->where('user_id', $dossier2->user_id)->count())->toBe(1);
    expect(Property::where('user_id', $dossier2->user_id)->count())->toBe(0);

    $this->post(route('filament.admin.dossiers.leave'));
    expect(Auth::user()->id)->toBe($principal1->id)
        ->and(Property::count())->toBe(1)
        ->and(Property::first()->name)->toBe('Bien du principal 1');
});

it('exclut les dossiers liés des statistiques globales', function () {
    $principal = User::factory()->create();
    $dossier = dossierLieDe($principal, '100000009');

    $stats = (new \App\Filament\Pages\AdminStats)->getStats();

    // Seul le principal compte : le dossier lié n'est pas un compte « inscrit ».
    expect($stats['users']['total'])->toBe(1);
});

it('exclut les dossiers liés de la résolution du compte diagnostiqué par défaut', function () {
    $principal = User::factory()->create();
    dossierLieDe($principal, '100000009');

    // Sans l'exclusion, deux comptes existeraient ("is_demo=false" compris) et la
    // commande refuserait de choisir ("Plusieurs comptes : précisez une adresse").
    // Avec elle, un seul candidat reste : le principal.
    $this->artisan('openlmnp:diagnostic')->assertSuccessful();
});

it('le MCP local résout le PRINCIPAL comme unique compte réel, jamais son dossier lié', function () {
    $principal = User::factory()->create(['is_demo' => false]);
    dossierLieDe($principal, '100000009');

    expect(Auth::check())->toBeFalse();

    $server = new OpenLmnpServer(new \Laravel\Mcp\Server\Transport\FakeTransporter);
    $boot = new ReflectionMethod($server, 'boot');
    $boot->setAccessible(true);
    $boot->invoke($server);

    expect(Auth::check())->toBeTrue()
        ->and(Auth::id())->toBe($principal->id);
});

// === Contre-épreuve : sans l'exclusion `is_linked_dossier`, le MCP local échouerait en
// silence (deux comptes réels candidats → aucun résolu). Vérifié en retirant la clause
// `->where('is_linked_dossier', false)` d'OpenLmnpServer::boot() : ce test échoue alors
// (Auth::check() reste faux), confirmant que la garde est bien ce qui fait la différence.
it('sans l\'exclusion, deux comptes réels (principal + dossier) rendraient le MCP local ambigu', function () {
    $principal = User::factory()->create(['is_demo' => false]);
    dossierLieDe($principal, '100000009');

    // Démontre la logique SANS la garde : deux comptes "is_demo=false" existent déjà
    // (principal + dossier) si l'on ne filtre pas is_linked_dossier.
    $realUsersSansGarde = User::where('is_demo', false)->count();
    expect($realUsersSansGarde)->toBe(2);

    $realUsersAvecGarde = User::where('is_demo', false)->where('is_linked_dossier', false)->count();
    expect($realUsersAvecGarde)->toBe(1);
});
