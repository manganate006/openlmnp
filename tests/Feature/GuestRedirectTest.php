<?php

// Le middleware `auth` générique redirige vers route('login'), que ce projet n'expose pas
// (Filament nomme la sienne `filament.admin.auth.login`). Ouvertes dans un navigateur par
// un visiteur non connecté, ces routes rendaient une erreur 500 au lieu de la page de
// connexion — constaté sur la bêta le 2026-09-26.

it('renvoie un visiteur non connecté vers la connexion, pas vers une erreur', function (string $url) {
    $this->get($url)->assertRedirect(route('filament.admin.auth.login'));
})->with([
    'autocomplétion d\'adresse' => '/adresse/recherche?q=rue',
    'matériel de clé du coffre' => '/vault/key-material',
]);

it('répond 401 à un appel JSON non authentifié, sans redirection', function () {
    $this->getJson('/adresse/recherche?q=rue')->assertUnauthorized();
});
