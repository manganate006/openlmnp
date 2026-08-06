<?php

// F8 — en-têtes de sécurité posés côté application (défense en profondeur).

it('sets security headers on web responses', function () {
    $response = $this->get('/login');

    $response->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    expect($response->headers->get('Permissions-Policy'))->toContain('geolocation=()');
});
