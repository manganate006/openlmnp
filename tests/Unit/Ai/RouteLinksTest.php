<?php

use App\Services\Ai\Assistant\RouteLinks;

/**
 * La table des liens est la seule source d'adresses de l'assistant : elle doit couvrir les
 * écrans de l'indivision, et taire les dossiers liés tant que la fonctionnalité est coupée
 * (la route existe toujours, la page répond alors par un refus).
 */
it('connaît le profil et la télédéclaration', function () {
    $table = (new RouteLinks)->table();

    expect($table)->toHaveKey('Profil et assistant')
        ->and($table['Profil et assistant'])->toBe('/profile')
        ->and($table)->toHaveKey('Télédéclaration')
        ->and($table['Télédéclaration'])->toBe('/teledeclaration');
});

it('propose la page des dossiers liés quand la fonctionnalité est active', function () {
    config(['dossiers.enabled' => true]);

    $links = new RouteLinks;

    expect($links->urlFor('Dossiers liés'))->toBe('/linked-dossiers')
        ->and($links->isAllowed('/linked-dossiers'))->toBeTrue()
        ->and($links->forPrompt())->toContain('Dossiers liés : /linked-dossiers');
});

it('tait la page des dossiers liés quand la fonctionnalité est coupée', function () {
    config(['dossiers.enabled' => false]);

    $links = new RouteLinks;

    expect($links->urlFor('Dossiers liés'))->toBeNull()
        ->and($links->isAllowed('/linked-dossiers'))->toBeFalse()
        ->and($links->forPrompt())->not->toContain('/linked-dossiers');
});
