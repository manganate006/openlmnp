<?php

use App\Models\User;
use App\Support\DossierQuota;

beforeEach(function () {
    config(['services.provisioning.token' => null, 'dossiers.quota' => null]);
});

it('est illimité par défaut en auto-hébergement', function () {
    $user = User::factory()->create();

    expect(DossierQuota::isCloudInstance())->toBeFalse()
        ->and(DossierQuota::for($user))->toBeNull()
        ->and(DossierQuota::canCreate($user))->toBeTrue();
});

it('respecte un plafond global posé en auto-hébergement', function () {
    config(['dossiers.quota' => 2]);
    $user = User::factory()->create();

    expect(DossierQuota::for($user))->toBe(2)
        ->and(DossierQuota::remaining($user))->toBe(2);
});

it('est nul par défaut sur le cloud (PROVISION_TOKEN configuré)', function () {
    config(['services.provisioning.token' => 'secret-cloud']);
    $user = User::factory()->create();

    expect(DossierQuota::isCloudInstance())->toBeTrue()
        ->and(DossierQuota::for($user))->toBe(0)
        ->and(DossierQuota::canCreate($user))->toBeFalse();
});

it('la valeur propre au compte prime TOUJOURS, cloud ou auto-hébergement', function () {
    config(['services.provisioning.token' => 'secret-cloud']);
    $user = User::factory()->create(['linked_dossier_quota' => 5]);

    expect(DossierQuota::for($user))->toBe(5)
        ->and(DossierQuota::canCreate($user))->toBeTrue();
});

it('compte les dossiers actifs déjà créés, pas les archivés', function () {
    $user = User::factory()->create(['linked_dossier_quota' => 1]);

    $dossier = \App\Models\LinkedDossier::create([
        'public_id' => (string) \Illuminate\Support\Str::ulid(),
        'user_id' => User::factory()->create(['is_linked_dossier' => true])->id,
        'owner_id' => $user->id,
        'kind' => 'indivision',
        'siren' => '100000009',
    ]);

    expect(DossierQuota::used($user))->toBe(1)
        ->and(DossierQuota::canCreate($user))->toBeFalse();

    $dossier->update(['archived_at' => now()]);

    expect(DossierQuota::used($user))->toBe(0)
        ->and(DossierQuota::canCreate($user))->toBeTrue();
});
