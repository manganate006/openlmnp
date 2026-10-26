<?php

use App\Services\Ai\Assistant\OutputGuard;
use App\Services\Ai\Assistant\RouteLinks;

/**
 * Deuxième barrière du périmètre : une réponse qui glisse vers le conseil fiscal est
 * remplacée, mais une phrase qui décrit ce que fait le logiciel ne l'est pas.
 */
beforeEach(function () {
    $this->guard = new OutputGuard(new RouteLinks);
});

it('remplace une recommandation de régime fiscal', function () {
    $refus = $this->guard->inspect(
        'Vu vos revenus, vous devriez opter pour le régime réel dès cette année.'
    );

    expect($refus)->not->toBeNull()
        ->and($refus)->toContain('Je ne peux pas vous conseiller sur votre situation fiscale');
});

it('remplace un avis personnalisé', function () {
    expect($this->guard->inspect('Dans votre cas, la déduction est possible.'))->not->toBeNull()
        ->and($this->guard->inspect('Je vous conseille de déduire cette dépense.'))->not->toBeNull()
        ->and($this->guard->inspect('Vous avez le droit de déduire ce voyage.'))->not->toBeNull();
});

it('remplace une interprétation de la loi', function () {
    expect($this->guard->inspect("L'article 39 du CGI prévoit cette déduction."))->not->toBeNull()
        ->and($this->guard->inspect('Le BOFiP précise le contraire.'))->not->toBeNull()
        ->and($this->guard->inspect('Vous risquez un redressement fiscal.'))->not->toBeNull();
});

it('propose une porte de sortie vers le simulateur', function () {
    $refus = $this->guard->inspect('Vous devriez opter pour le réel.');

    expect($refus)->toContain('simulateur')
        ->and($refus)->toContain('expert-comptable');
});

it('laisse passer une réponse d\'usage', function () {
    $reponses = [
        'Pour saisir une facture d\'assurance, ouvrez la page Charges puis cliquez sur Créer.',
        'Le simulateur compare le micro-BIC et le régime réel à partir de vos montants.',
        'Les intérêts d\'emprunt sont repris automatiquement dans le résultat de l\'exercice.',
    ];

    foreach ($reponses as $reponse) {
        expect($this->guard->inspect($reponse))->toBeNull();
    }
});

it('tolère une phrase à risque reprise de la documentation fournie', function () {
    $extraits = [[
        'text' => "Charges déductibles\nVous pouvez déduire les dépenses engagées dans l'intérêt "
            .'de la location meublée, au prorata de la quote-part.',
    ]];

    $reponse = "Vous pouvez déduire les dépenses engagées dans l'intérêt de la location meublée, "
        .'au prorata de la quote-part.';

    expect($this->guard->inspect($reponse, $extraits))->toBeNull();
});

it('bloque la même phrase quand elle ne vient pas de la documentation', function () {
    $reponse = 'Je vous conseille de déduire ce séjour à Rome comme un déplacement professionnel.';

    expect($this->guard->inspect($reponse, [['text' => 'Sans rapport.']]))->not->toBeNull();
});

// =============================================================================
// Filtre d'entrée : questions de doctrine fiscale
// =============================================================================

it('refuse une question de doctrine fiscale avant tout appel au modèle', function () {
    $questions = [
        "Le BOFiP autorise-t-il l'amortissement du terrain ?",
        "L'article 39 du CGI permet-il cette déduction ?",
        'Que risque-t-on en cas d\'oubli ?',
        'Y a-t-il une jurisprudence sur ce point ?',
        'Est-ce légal de déduire cette dépense ?',
        "La loi autorise-t-elle l'amortissement des travaux ?",
        // Apostrophe droite et apostrophe typographique (U+2019, ramenée par Str::ascii).
        "C'est légal de déduire ma voiture ?",
        "L'administration fiscale accepte-t-elle cette charge ?",
        "L\u{2019}administration fiscale accepte-t-elle cette charge ?",
    ];

    foreach ($questions as $question) {
        expect($this->guard->inspectQuestion($question))
            ->not->toBeNull()
            ->toContain('Je ne peux pas vous conseiller sur votre situation fiscale');
    }
});

it('laisse passer une question d\'usage qui parle des mêmes notions', function () {
    $questions = [
        'Comment OpenLMNP traite-t-il la part du terrain ?',
        'Où saisir la taxe foncière ?',
        "Comment le logiciel calcule l'amortissement des travaux ?",
        'Pourquoi mon amortissement est-il plafonné ?',
        'Comment créer une charge ?',
        'Que contient le fichier FEC ?',
    ];

    foreach ($questions as $question) {
        expect($this->guard->inspectQuestion($question))->toBeNull();
    }
});

// =============================================================================
// Affirmations fausses : indivision « au réel de plein droit » (piège P13)
// =============================================================================

it('bloque « réel de plein droit » appliqué à une indivision', function () {
    $reponses = [
        'Une indivision est au réel de plein droit : le micro-BIC ne s\'applique pas.',
        "Pour un bien en indivision, activez la rubrique dans Mon profil.\n\nLe régime réel de plein droit s'applique alors.",
        'Les indivisions sont soumises d\'office au régime réel.',
        'Une indivision passe d\'office au réel.',
    ];

    foreach ($reponses as $reponse) {
        expect($this->guard->inspect($reponse))
            ->not->toBeNull()
            ->toContain('Je ne peux pas vous conseiller sur votre situation fiscale');
    }
});

it('bloque l\'affirmation même si un extrait de documentation la contient', function () {
    $reponse = 'Une indivision est au réel de plein droit.';

    expect($this->guard->inspect($reponse, [['text' => $reponse]]))->not->toBeNull();
});

it('laisse passer la règle de l\'indivision telle que le logiciel l\'applique', function () {
    $reponses = [
        'Pour une indivision, OpenLMNP applique les mêmes règles de micro-BIC qu\'à un dossier individuel.',
        'Le plafond du micro-BIC se compte sur la totalité des recettes de l\'indivision.',
        'Saisissez les charges de l\'indivision pour leur montant total.',
    ];

    foreach ($reponses as $reponse) {
        expect($this->guard->inspect($reponse))->toBeNull();
    }
});

it('ne vise pas « de plein droit » hors indivision', function () {
    expect($this->guard->inspect('Le simulateur compare le micro-BIC et le régime réel.'))->toBeNull()
        ->and($this->guard->inspect('Au-delà du plafond, le passage au réel figure dans le simulateur.'))->toBeNull();
});
