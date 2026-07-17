<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('outputs a valid reset link for an existing user', function () {
    User::factory()->create(['email' => 'user@example.com']);

    $this->artisan('openlmnp:reset-password', ['email' => 'user@example.com'])
        ->expectsOutputToContain('/password-reset/reset')
        ->assertSuccessful();
});

it('sets the password directly with --password', function () {
    $user = User::factory()->create(['email' => 'user@example.com']);

    $this->artisan('openlmnp:reset-password', [
        'email' => 'user@example.com',
        '--password' => 'NouveauMotDePasse',
    ])->assertSuccessful();

    expect(Hash::check('NouveauMotDePasse', $user->fresh()->password))->toBeTrue();
});

it('rejects a too short password', function () {
    User::factory()->create(['email' => 'user@example.com']);

    $this->artisan('openlmnp:reset-password', [
        'email' => 'user@example.com',
        '--password' => 'court',
    ])->assertFailed();
});

it('fails cleanly for an unknown email', function () {
    $this->artisan('openlmnp:reset-password', ['email' => 'inconnu@example.com'])
        ->assertFailed();
});
