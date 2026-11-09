<?php

use App\Models\User;
use App\Services\Vault\VaultKeyManager;
use App\Support\Vault;

// Le déchiffrement navigateur repose sur deux points d'entrée. Ce qui les rend sûrs
// n'est pas ce qu'ils rendent, mais ce qu'ils ne rendent PAS : aucune clé ouverte.

beforeEach(function () {
    config()->set('vault.kdf', 'test');
    $this->user = User::factory()->create();
    app(VaultKeyManager::class)->create($this->user, 'une phrase de passe solide');
});

it('sert le matériel de clé sans jamais livrer de clé ouverte', function () {
    $reponse = $this->actingAs($this->user)->getJson(route('vault.key-material'));

    $reponse->assertOk();
    $json = $reponse->json();

    expect($json['scheme'])->toBe('sealed')
        ->and($json['wrapped_secret_key'])->not->toBeNull()
        ->and($json['public_key'])->not->toBeNull()
        ->and($json['kdf']['ops'])->toBeGreaterThan(0);

    // ⚠️ L'assertion qui compte. Si l'une de ces clés sortait ouverte, confiner le
    // déchiffrement au navigateur ne protégerait plus de rien.
    [$dek, $prive] = app(VaultKeyManager::class)->unlockPair($this->user, 'une phrase de passe solide');
    $corps = $reponse->getContent();

    expect($corps)->not->toContain(base64_encode($dek))
        ->and($corps)->not->toContain(base64_encode($prive))
        ->and($json)->not->toHaveKey('wrapped_dek');
});

it('refuse le matériel de clé à un visiteur non connecté', function () {
    $this->getJson(route('vault.key-material'))->assertStatus(401);
});

it('annonce le schéma historique plutôt que de faire croire à une panne', function () {
    Vault::vaultOf($this->user)->update([
        'wrapped_secret_key' => null,
        'secret_key_nonce' => null,
        'public_key' => null,
    ]);

    $this->actingAs($this->user)
        ->getJson(route('vault.key-material'))
        ->assertStatus(409)
        ->assertJsonPath('scheme', 'legacy');
});

it('ne sert aucun matériel quand le coffre n\'est pas activé', function () {
    $autre = User::factory()->create();

    $this->actingAs($autre)
        ->getJson(route('vault.key-material'))
        ->assertStatus(404);
});
