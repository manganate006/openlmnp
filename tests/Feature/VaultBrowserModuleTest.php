<?php

/**
 * Déchiffrement des justificatifs dans le navigateur : deux défauts qu'aucun test PHP ne
 * voyait, cumulés, constatés le 2026-10-16 en ouvrant un justificatif scellé (format 2).
 *
 * 1. La fenêtre de déverrouillage lisait les fonctions sur l'objet rendu par `import()`.
 *    Vite retire les `export` d'un point d'entrée d'application : cet objet est VIDE, d'où
 *    « v.estDeverrouille is not a function » à chaque ouverture.
 * 2. `libsodium-wrappers` (variante standard) n'embarque pas `crypto_pwhash` : aucune phrase
 *    de passe ne pouvait être dérivée, et l'échec s'affichait comme un manque de mémoire.
 *
 * Le parcours réel (dérivation, descellement, déchiffrement, empreinte identique au fichier
 * déposé) a été rejoué au navigateur ; ces tests verrouillent les deux causes.
 */
it('depends on the sumo build of libsodium, the only one that ships crypto_pwhash', function () {
    $package = json_decode(file_get_contents(base_path('package.json')), true);
    $dependencies = ($package['dependencies'] ?? []) + ($package['devDependencies'] ?? []);

    expect($dependencies)->toHaveKey('libsodium-wrappers-sumo')
        ->and($dependencies)->not->toHaveKey('libsodium-wrappers');

    expect(file_get_contents(resource_path('js/vault.js')))
        ->toContain("from 'libsodium-wrappers-sumo'");
});

it('reads the vault module from its window global, never from the import() result', function () {
    $hook = file_get_contents(resource_path('views/livewire/vault-unlock-hook.blade.php'));

    expect($hook)->toContain('window.OpenlmnpVault')
        ->and($hook)->not->toMatch('/this\.module\s*=\s*await\s+import\(/');

    expect(file_get_contents(resource_path('js/vault.js')))
        ->toContain('window.OpenlmnpVault = {');
});

it('does not disguise every unlock failure as a lack of memory', function () {
    $js = file_get_contents(resource_path('js/vault.js'));

    // Seul un échec d'allocation devient « mémoire » ; une fonction absente doit remonter
    // avec sa cause, sans quoi le défaut n°2 redevient invisible.
    expect($js)->toContain("err.code = 'INTERNE'");
});
