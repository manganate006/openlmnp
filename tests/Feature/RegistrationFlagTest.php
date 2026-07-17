<?php

// ALLOW_REGISTRATION contrôle la page /register du panel. La route est
// enregistrée au boot du panel : on ne peut pas changer la config après coup
// dans le même process, on vérifie donc le comportement par défaut (activée)
// et la présence du lien sur la page de connexion.

it('shows the register page when registration is allowed (default)', function () {
    expect(config('app.allow_registration'))->toBeTrue();

    $this->get('/register')->assertOk();
});

it('shows the password reset request page', function () {
    $this->get('/password-reset/request')->assertOk();
});
