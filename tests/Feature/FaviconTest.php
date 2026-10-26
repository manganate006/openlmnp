<?php

use App\Models\User;

// Ticket #18 : `public/favicon.ico` était le fichier vide du squelette Laravel et le panel ne
// déclarait aucune icône. Le navigateur demandait donc /favicon.ico d'office et recevait un
// 200 sans contenu.

it('livre des fichiers d\'icône non vides', function (string $file) {
    expect(filesize(public_path($file)))->toBeGreaterThan(0);
})->with(['favicon.ico', 'favicon.svg']);

it('livre un vrai fichier .ico', function () {
    // En-tête ICONDIR : réservé = 0, type = 1 (icône).
    expect(substr(file_get_contents(public_path('favicon.ico')), 0, 4))->toBe("\x00\x00\x01\x00");
});

it('déclare l\'icône dans le panel', function (bool $authenticated) {
    if ($authenticated) {
        $this->actingAs(User::factory()->create());
    }

    $this->get($authenticated ? '/' : '/login')
        ->assertOk()
        ->assertSee('favicon.svg', false);
})->with(['page de connexion' => false, 'tableau de bord' => true]);

it('déclare l\'icône sur les pages hors panel', function () {
    $this->get('/confidentialite')
        ->assertOk()
        ->assertSee('<link rel="icon" href="'.asset('favicon.svg').'"', false);
});

it('déclare l\'icône avec le schéma de la requête, pas celui du démarrage', function () {
    // Derrière le proxy HTTPS, un `asset()` évalué au boot du panel sortait en http://
    // (bêta, 2026-10-24) : le lien est désormais calculé au rendu.
    $this->get('https://localhost/login')
        ->assertOk()
        ->assertSee('<link rel="icon" href="https://localhost/favicon.svg"', false);
});
