<?php

use App\Models\User;

/**
 * Bouton d'aide flottant sur mobile (contextual-help.blade.php).
 *
 * D7 du test bêta du 2026-10-15 : à 390 x 844, en bas du profil défilé jusqu'au bout, le
 * bouton d'aide (fixe, 88 à 136 px du bas de l'écran) recouvrait « Annuler », dernière ligne
 * du formulaire. Le recouvrement lui-même se mesure au navigateur (boîtes englobantes, capture
 * `_admin/docs/tests-beta-2026-10-15/corrige2/d7-*`) ; ici, on verrouille que la page sert
 * bien les deux règles qui l'évitent, et qu'elles restent cohérentes entre elles.
 */
it('sert, sous 1024 px, une marge basse du contenu qui dépasse le haut du bouton d\'aide', function () {
    $this->actingAs(User::factory()->create());

    $html = $this->get('/profile')->assertOk()->getContent();

    expect(preg_match('/@media \(max-width: 1023px\) \{ \.ctx-help-btn \{ bottom: (\d+)px;/', $html, $bouton))->toBe(1)
        ->and(preg_match('/@media \(max-width: 1023px\) \{ \.fi-main \{ padding-bottom: (\d+)px; \} \}/', $html, $marge))->toBe(1);

    // Haut du bouton = bottom + 48 px de hauteur. La marge doit le dépasser, sinon la
    // dernière ligne de la page ne peut jamais remonter au-dessus du bouton.
    $hautDuBouton = (int) $bouton[1] + 48;

    expect((int) $marge[1])->toBeGreaterThan($hautDuBouton);
});
