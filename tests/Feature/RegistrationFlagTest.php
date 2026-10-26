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

// === RegistrationGate : mode invitation ===

it('ne confond pas « invite » avec « false »', function () {
    // ⚠️ L'ancre du piège central : filter_var('invite', FILTER_VALIDATE_BOOL) rend FALSE.
    // Sans le court-circuit de RegistrationGate, le mode se comporterait exactement comme
    // « false », la route /register ne serait pas enregistrée, et TOUS les liens
    // d'invitation tomberaient en 404 — sans le moindre message.
    config(['app.allow_registration' => RegistrationGate::MODE_INVITE]);
    User::factory()->create();

    expect(RegistrationGate::allows())->toBeFalse()
        // ... mais la route doit exister quand même, c'est là qu'atterrit l'invité.
        ->and(RegistrationGate::exposesRoute())->toBeTrue()
        ->and(RegistrationGate::requiresInvitation())->toBeTrue();

    config(['app.allow_registration' => false]);

    expect(RegistrationGate::exposesRoute())->toBeFalse();
});

it('laisse l\'inscription ouverte en mode invitation tant qu\'aucun compte n\'existe', function () {
    // Repli d'amorçage : sinon une instance neuve démarrée en « invite » n'a personne
    // pour émettre la première invitation.
    config(['app.allow_registration' => RegistrationGate::MODE_INVITE]);

    expect(RegistrationGate::allows())->toBeTrue()
        ->and(RegistrationGate::requiresInvitation())->toBeFalse();
});

it('ignore les comptes démo en mode invitation', function () {
    config(['app.allow_registration' => RegistrationGate::MODE_INVITE]);
    User::factory()->create(['is_demo' => true]);

    expect(RegistrationGate::allows())->toBeTrue();
});
