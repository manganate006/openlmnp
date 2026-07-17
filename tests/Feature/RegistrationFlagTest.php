<?php

use App\Models\User;
use App\Support\RegistrationGate;

// La route /register est enregistrée au boot du panel : en prod chaque requête
// ré-évalue RegistrationGate, mais dans les tests HTTP le panel est déjà booté.
// On teste donc la page par défaut (base vide → ouverte) et la logique du gate.

it('shows the register page on a fresh install (no account yet)', function () {
    $this->get('/register')->assertOk();
});

it('shows the password reset request page', function () {
    $this->get('/password-reset/request')->assertOk();
});

// === RegistrationGate : mode auto (défaut) ===

it('allows registration in auto mode while no real account exists', function () {
    config(['app.allow_registration' => 'auto']);

    expect(RegistrationGate::allows())->toBeTrue();
});

it('closes registration in auto mode once a real account exists', function () {
    config(['app.allow_registration' => 'auto']);
    User::factory()->create();

    expect(RegistrationGate::allows())->toBeFalse();
});

it('ignores demo accounts in auto mode', function () {
    config(['app.allow_registration' => 'auto']);
    User::factory()->create(['is_demo' => true]);

    expect(RegistrationGate::allows())->toBeTrue();
});

// === RegistrationGate : valeurs explicites ===

it('always allows registration when explicitly enabled', function () {
    config(['app.allow_registration' => 'true']);
    User::factory()->create();

    expect(RegistrationGate::allows())->toBeTrue();
});

it('never allows registration when explicitly disabled', function () {
    config(['app.allow_registration' => 'false']);

    expect(RegistrationGate::allows())->toBeFalse();
});

it('accepts boolean values as well', function () {
    config(['app.allow_registration' => false]);
    expect(RegistrationGate::allows())->toBeFalse();

    config(['app.allow_registration' => true]);
    User::factory()->create();
    expect(RegistrationGate::allows())->toBeTrue();
});
