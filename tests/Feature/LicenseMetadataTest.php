<?php

// Le logiciel est publié sous AGPL v3 : c'est le fichier LICENSE qui fait foi, et le README
// le répète. composer.json, lui, a longtemps annoncé le « MIT » hérité du squelette Laravel —
// une licence qui n'est pas la nôtre, et que lisent les annuaires, Packagist et les scanners
// de dépendances. Une déclaration fausse à cet endroit autorise, sur le papier, ce que l'AGPL
// interdit.

it('declares in composer.json the license that the LICENSE file actually grants', function () {
    expect(file_get_contents(base_path('LICENSE')))
        ->toContain('GNU AFFERO GENERAL PUBLIC LICENSE');

    $composer = json_decode(file_get_contents(base_path('composer.json')), true);

    expect($composer['license'] ?? '')->toStartWith('AGPL-3.0');
});
