<?php

use App\Filament\Pages\AdminUpdate;
use App\Models\Setting;
use App\Models\User;
use App\Services\UpdateService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

// L'image Docker officielle est immuable : ni rsync, ni composer, ni npm dans le
// runtime, et opcache tourne avec validate_timestamps=0. Sans le drapeau
// UPDATE_SELF_APPLY, les Process de UpdateService échouent en silence (leur retour
// n'est jamais vérifié) et l'utilisateur croit sa mise à jour appliquée.

beforeEach(function () {
    Http::fake(['api.github.com/*' => Http::response([], 200)]);
});

// === Service ===

it('refuses to apply a release when self-apply is disabled', function () {
    config(['updater.self_apply' => false]);
    Process::fake();

    $result = (new UpdateService())->applyUpdate('https://example.test/tarball');

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('docker pull');

    Process::assertNothingRan();
});

it('refuses to deploy a branch when self-apply is disabled', function () {
    config(['updater.self_apply' => false]);
    Process::fake();

    $result = (new UpdateService())->applyBranchUpdate();

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('docker pull');

    Process::assertNothingRan();
});

it('names the configured docker image in the blocking message', function () {
    config([
        'updater.self_apply' => false,
        'updater.docker_image' => 'manganate06/openlmnp:1.2.3',
    ]);

    expect((new UpdateService())->selfApplyBlockedReason())
        ->toContain('docker pull manganate06/openlmnp:1.2.3');
});

it('allows self-apply when the flag is on and rsync is available', function () {
    config(['updater.self_apply' => true]);
    Process::fake(['command -v *' => Process::result(exitCode: 0)]);

    expect((new UpdateService())->selfApplyBlockedReason())->toBeNull();
});

it('blocks self-apply when rsync is missing, instead of failing silently', function () {
    config(['updater.self_apply' => true]);
    Process::fake(['command -v *' => Process::result(exitCode: 1)]);

    expect((new UpdateService())->selfApplyBlockedReason())->toContain('rsync');
});

// === Commande planifiée ===

it('makes app:auto-update a no-op on an immutable instance', function () {
    config(['updater.self_apply' => false]);
    Setting::set('auto_update_enabled', '1');
    Process::fake();

    $this->artisan('app:auto-update')
        ->expectsOutputToContain('docker pull')
        ->assertSuccessful();

    Process::assertNothingRan();
});

// === Page Filament ===

it('hides the apply button and shows the docker instructions when immutable', function () {
    config([
        'updater.self_apply' => false,
        'updater.docker_image' => 'manganate06/openlmnp:latest',
    ]);
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    Livewire::test(AdminUpdate::class)
        ->set('updateInfo', ['available' => true, 'latest_version' => '9.9.9'])
        ->assertOk()
        ->assertSee('docker pull manganate06/openlmnp:latest')
        ->assertDontSee('Installer v9.9.9');
});

it('keeps the apply button on a mutable instance', function () {
    config(['updater.self_apply' => true]);
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    Livewire::test(AdminUpdate::class)
        ->set('updateInfo', ['available' => true, 'latest_version' => '9.9.9'])
        ->assertOk()
        ->assertSee('Installer v9.9.9')
        ->assertDontSee('docker pull');
});

// === Garde-fou : allowlist de docker-entrypoint.sh ===
// L'entrypoint ne recopie vers .env qu'une liste fixe de variables : toute variable
// oubliée est silencieusement ignorée en production, `-e` ou pas.

it('propagates the updater variables through the docker entrypoint allowlist', function () {
    $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));

    expect($entrypoint)->toContain('UPDATE_SELF_APPLY')
        ->and($entrypoint)->toContain('UPDATE_DOCKER_IMAGE');
});

it('disables self-apply in the docker environment template', function () {
    expect(file_get_contents(base_path('.env.docker')))
        ->toContain('UPDATE_SELF_APPLY=false');
});
