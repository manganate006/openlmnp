<?php

/**
 * Les lignes « base saisie à la main » se signalent SANS consommer de hauteur.
 *
 * Une pastille « saisi » vivait sous le nom du composant, dans le tableau des montants.
 * Elle n'allongeait que les lignes qui la portaient : le tableau alternait lignes hautes
 * et lignes basses selon une information qui n'a rien à voir avec la mise en page, et on
 * y perdait le rythme vertical qui rend un tableau de chiffres lisible.
 *
 * ⚠️ Elle est remplacée par une couleur de ligne — MAIS PAS SEULEMENT. La couleur ne peut
 * pas porter seule une information (WCAG 1.4.1 : daltonisme, impression en noir et blanc,
 * contraste dégradé). Il faut un second signal non chromatique, et qui ne reprenne pas la
 * hauteur qu'on vient d'économiser. C'est ce que ce fichier vérifie, dans les deux
 * tableaux concernés : celui des montants, et celui de la lecture de liasse.
 */
const AMOUNTS_TABLE_VIEW = 'filament/partials/depreciation-editor-core.blade.php';
const AMOUNTS_STYLE_VIEW = 'filament/partials/depreciation-editor-assets.blade.php';
const AI_TABLE_VIEW = 'filament/pages/reprise-dossier.blade.php';

function panelView(string $relative): string
{
    return file_get_contents(resource_path('views/'.$relative));
}

/** Déclarations CSS d'une règle, dans les `<style>` d'une vue du panel. */
function cssRule(string $relative, string $selector): ?string
{
    $contents = panelView($relative);

    return preg_match('/'.preg_quote($selector, '/').'\s*\{([^}]*)\}/', $contents, $match)
        ? trim($match[1])
        : null;
}

it('ne porte plus de pastille sous le nom du composant', function () {
    // La pastille est ce qui ajoutait de la hauteur. Sa classe ne doit plus exister
    // nulle part — ni dans le balisage, ni en CSS mort.
    foreach ([AMOUNTS_TABLE_VIEW, AMOUNTS_STYLE_VIEW] as $view) {
        expect(panelView($view))->not->toContain('de-manual-badge');
    }
});

it('colore la ligne entière plutôt que d\'y ajouter un élément', function () {
    expect(panelView(AMOUNTS_TABLE_VIEW))->toContain('de-row-manual');

    $background = cssRule(AMOUNTS_STYLE_VIEW, '.de-amounts tr.de-row-manual > td');

    expect($background)->not->toBeNull()
        ->and($background)->toContain('background');
});

it('double la couleur d\'un repère qui survit au noir et blanc', function () {
    $table = panelView(AMOUNTS_TABLE_VIEW);

    // Repère glyphique dans la cellule…
    expect($table)->toContain('de-manual-mark')
        // …intitulé accessible sur la ligne, pour qui ne voit ni couleur ni glyphe…
        ->and($table)->toContain('aria-label')
        ->and($table)->toContain('Base saisie à la main')
        // …et une légende, sans laquelle ni la couleur ni le glyphe ne veulent rien dire.
        ->and($table)->toContain('de-legend');
});

it('ne reprend pas en hauteur ce que la pastille consommait', function () {
    // Une bordure gauche ordinaire décalerait toute la colonne ; `inset` peint à
    // l'intérieur de la cellule et ne déplace rien. Et le repère reste sur la ligne de
    // base du texte : aucune des deux règles n'introduit de `display: block`, de
    // `padding` vertical ni de marge haute.
    $border = cssRule(AMOUNTS_STYLE_VIEW, '.de-amounts tr.de-row-manual > td:first-child');
    $mark = cssRule(AMOUNTS_STYLE_VIEW, '.de-manual-mark');

    expect($border)->toContain('inset')
        ->and($mark)->not->toBeNull()
        ->and($mark)->not->toContain('display: block')
        ->and($mark)->not->toContain('padding')
        ->and($mark)->not->toContain('margin-top');
});

it('n\'écrit aucune couleur hors des jetons du thème', function () {
    // Même exigence que `PanelStylesheetTest`, rejouée sur les règles ajoutées ici :
    // une couleur littérale ne se décline pas en thème sombre.
    foreach ([
        '.de-amounts tr.de-row-manual > td',
        '.de-amounts tr.de-row-manual > td:first-child',
        '.de-manual-mark',
    ] as $selector) {
        $declarations = cssRule(AMOUNTS_STYLE_VIEW, $selector);

        expect($declarations)->not->toBeNull();

        preg_match_all('/(?:background|color|box-shadow)\s*:\s*([^;]+)/', $declarations, $values);

        foreach ($values[1] as $value) {
            expect($value)->toContain('var(--olmnp-');
        }
    }
});

it('applique le même traitement à la lecture de liasse', function () {
    $view = panelView(AI_TABLE_VIEW);

    // « déjà portée par l'application » était une pastille de vingt-huit caractères dans
    // la cellule du libellé : elle passait à la ligne et allongeait la sienne.
    expect($view)->toContain('rp-ai-carried')
        ->and($view)->toContain('rp-ai-mark')
        ->and($view)->toContain('rp-ai-legend')
        ->and(cssRule(AI_TABLE_VIEW, '.rp-ai-carried > td:first-child'))->toContain('inset');

    // ⚠️ Une ligne peut être douteuse ET déjà portée. C'est le doute qui doit rester
    // visible : sa règle vient donc APRÈS, et gagne à spécificité égale.
    expect(strpos($view, '.rp-ai-carried > td {'))
        ->toBeLessThan(strpos($view, '.rp-ai-doubt td {'));
});
