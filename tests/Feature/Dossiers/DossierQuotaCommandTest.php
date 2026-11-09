<?php

use App\Models\User;

it('pose le quota de dossiers liés d\'un compte', function () {
    $user = User::factory()->create(['email' => 'abonne@exemple.test']);

    $this->artisan('openlmnp:dossier-quota', ['email' => 'abonne@exemple.test', 'quota' => 3])
        ->assertSuccessful();

    expect($user->fresh()->linked_dossier_quota)->toBe(3);
});

it('refuse un quota négatif', function () {
    User::factory()->create(['email' => 'abonne@exemple.test']);

    $this->artisan('openlmnp:dossier-quota', ['email' => 'abonne@exemple.test', 'quota' => -1])
        ->assertFailed();
});

it('échoue pour une adresse inconnue', function () {
    $this->artisan('openlmnp:dossier-quota', ['email' => 'inconnu@exemple.test', 'quota' => 1])
        ->assertFailed();
});

it('ne pose jamais le quota sur le User d\'un dossier lié', function () {
    $owner = User::factory()->create();
    $dossierUser = User::factory()->create([
        'is_linked_dossier' => true,
        'email' => 'dossier-test@dossiers.invalid',
    ]);

    $this->artisan('openlmnp:dossier-quota', ['email' => 'dossier-test@dossiers.invalid', 'quota' => 5])
        ->assertFailed();

    expect($dossierUser->fresh()->linked_dossier_quota)->toBeNull();
});
