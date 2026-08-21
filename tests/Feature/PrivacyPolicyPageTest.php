<?php

use App\Models\User;

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

// Le lien légal n'est plus un bandeau BODY_END posé sur toutes les pages du panel :
// une fois connecté il vit dans le menu de l'avatar (->userMenuItems()).

it('links to the privacy policy from the user menu once authenticated', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertSee('Confidentialité')
        ->assertSee(route('legal.confidentialite'), false);
});

it('no longer renders the privacy banner on authenticated pages', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertDontSee('olmnp-privacy-footer');
});
