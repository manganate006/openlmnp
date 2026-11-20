<?php

use App\Models\User;
use App\Support\RegistrationGate;
use Illuminate\Support\Facades\Hash;

// La commande lit l'environnement du processus (getenv), pas la config : on pose
// et on retire les variables autour de chaque test.
function setAdminEnv(?string $email, ?string $password): void
{
    foreach (['OPENLMNP_ADMIN_EMAIL' => $email, 'OPENLMNP_ADMIN_PASSWORD' => $password] as $key => $value) {
        putenv($value === null ? $key : "{$key}={$value}");
    }
}

afterEach(fn () => setAdminEnv(null, null));

it('creates the administrator on an empty database', function () {
    setAdminEnv('admin@example.com', 'MotDePasseGenere42');

    $this->artisan('openlmnp:create-admin')->assertSuccessful();

    $user = User::query()->where('email', 'admin@example.com')->firstOrFail();
    expect($user->is_admin)->toBeTrue()
        ->and(Hash::check('MotDePasseGenere42', $user->password))->toBeTrue();
});

it('closes auto registration once the administrator exists', function () {
    config(['app.allow_registration' => 'auto']);
    setAdminEnv('admin@example.com', 'MotDePasseGenere42');

    $this->artisan('openlmnp:create-admin')->assertSuccessful();

    expect(RegistrationGate::allows())->toBeFalse();
});

it('does nothing when a real account already exists', function () {
    $existing = User::factory()->create(['email' => 'proprio@example.com', 'password' => 'AncienMotDePasse']);
    setAdminEnv('admin@example.com', 'MotDePasseGenere42');

    $this->artisan('openlmnp:create-admin')
        ->expectsOutputToContain('rien à faire')
        ->assertSuccessful();

    expect(User::query()->count())->toBe(1)
        ->and(Hash::check('AncienMotDePasse', $existing->fresh()->password))->toBeTrue();
});

it('never overwrites the password on a later boot', function () {
    setAdminEnv('admin@example.com', 'MotDePasseGenere42');
    $this->artisan('openlmnp:create-admin')->assertSuccessful();

    $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
    $admin->update(['password' => 'ChoisiParLAdmin']);

    $this->artisan('openlmnp:create-admin')->assertSuccessful();

    expect(Hash::check('ChoisiParLAdmin', $admin->fresh()->password))->toBeTrue();
});

it('is not blocked by demo sandboxes', function () {
    User::factory()->create(['is_demo' => true]);
    setAdminEnv('admin@example.com', 'MotDePasseGenere42');

    $this->artisan('openlmnp:create-admin')->assertSuccessful();

    expect(User::query()->where('email', 'admin@example.com')->value('is_admin'))->toBeTruthy();
});

it('fails cleanly without the variables', function () {
    setAdminEnv(null, null);

    $this->artisan('openlmnp:create-admin')->assertFailed();
    expect(User::query()->count())->toBe(0);
});

it('rejects a too short password', function () {
    setAdminEnv('admin@example.com', 'court');

    $this->artisan('openlmnp:create-admin')->assertFailed();
    expect(User::query()->count())->toBe(0);
});

it('rejects an invalid e-mail address', function () {
    setAdminEnv('pas-une-adresse', 'MotDePasseGenere42');

    $this->artisan('openlmnp:create-admin')->assertFailed();
    expect(User::query()->count())->toBe(0);
});
