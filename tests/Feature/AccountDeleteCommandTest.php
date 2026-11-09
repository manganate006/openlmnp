<?php

use App\Models\FiscalYear;
use App\Models\Property;
use App\Models\User;

/** Même piège que AccountDeletionServiceTest : neutraliser l'auto-admin du premier compte. */
function creerCompteCommande(array $attributs = []): User
{
    $user = User::factory()->create($attributs);

    if (! array_key_exists('is_admin', $attributs) && $user->is_admin) {
        $user->forceFill(['is_admin' => false])->save();
    }

    return $user;
}

function bienDeLaCommande(User $user): Property
{
    return Property::withoutGlobalScopes()->forceCreate([
        'user_id' => $user->id,
        'name' => 'Bien de la commande',
        'address' => '1 rue de Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 50,
        'rented_area' => 50,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 1600000,
        'land_percentage' => 15,
        'rental_start_date' => '2020-06-01',
        'rental_type' => 'long_term',
        'is_primary_residence' => false,
    ]);
}

it('supprime un compte avec --force, sans demander de confirmation', function () {
    $user = creerCompteCommande();
    bienDeLaCommande($user);

    $this->artisan('openlmnp:account-delete', ['email' => $user->email, '--force' => true])
        ->assertSuccessful();

    expect(User::find($user->id))->toBeNull();
});

it('--dry-run n\'a aucun effet : le plan est affiché, rien n\'est supprimé', function () {
    $user = creerCompteCommande();
    $property = bienDeLaCommande($user);
    FiscalYear::withoutGlobalScopes()->forceCreate(['user_id' => $user->id, 'year' => 2025, 'status' => 'draft']);

    $this->artisan('openlmnp:account-delete', ['email' => $user->email, '--dry-run' => true])
        ->expectsOutputToContain('Biens : 1')
        ->assertSuccessful();

    expect(User::find($user->id))->not->toBeNull()
        ->and(Property::withoutGlobalScopes()->find($property->id))->not->toBeNull()
        ->and(FiscalYear::withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe(1);
});

it('demande confirmation sans --force, et n\'efface rien sur un refus', function () {
    $user = creerCompteCommande();

    $this->artisan('openlmnp:account-delete', ['email' => $user->email])
        ->expectsConfirmation("Confirmer la suppression DÉFINITIVE du compte {$user->email} ?", 'no')
        ->assertSuccessful();

    expect(User::find($user->id))->not->toBeNull();
});

it('supprime le compte si la confirmation est acceptée', function () {
    $user = creerCompteCommande();

    $this->artisan('openlmnp:account-delete', ['email' => $user->email])
        ->expectsConfirmation("Confirmer la suppression DÉFINITIVE du compte {$user->email} ?", 'yes')
        ->assertSuccessful();

    expect(User::find($user->id))->toBeNull();
});

it('échoue sur une adresse inconnue', function () {
    $this->artisan('openlmnp:account-delete', ['email' => 'inconnu@exemple.fr', '--force' => true])
        ->assertFailed();
});

it('refuse le compte de démonstration public', function () {
    config(['demo.email' => 'demo@openlmnp.fr']);
    $demo = User::factory()->create(['email' => 'demo@openlmnp.fr', 'is_demo' => false]);

    $this->artisan('openlmnp:account-delete', ['email' => $demo->email, '--force' => true])
        ->assertFailed();

    expect(User::find($demo->id))->not->toBeNull();
});

it('refuse le dernier administrateur de l\'instance', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->artisan('openlmnp:account-delete', ['email' => $admin->email, '--force' => true])
        ->assertFailed();

    expect(User::find($admin->id))->not->toBeNull();
});
