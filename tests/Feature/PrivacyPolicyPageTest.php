<?php

it('serves the privacy policy page without authentication', function () {
    $this->get('/confidentialite')
        ->assertOk()
        ->assertSee('Politique de confidentialité')
        ->assertSee('RGPD', false);
});

it('links to the privacy policy from the login page footer', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee(route('legal.confidentialite'), false);
});
