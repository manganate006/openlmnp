<?php

use App\Support\Fr;

it('met au singulier à un, et au pluriel à partir de deux', function () {
    expect(Fr::accorde(1, 'jour'))->toBe('1 jour')
        ->and(Fr::accorde(2, 'jour'))->toBe('2 jours')
        ->and(Fr::accorde(7, 'jour'))->toBe('7 jours');
});

it('laisse zéro au singulier, contrairement à l\'anglais', function () {
    // « 0 day**s** » en anglais, « 0 jour » en français. C'est la règle qu'un helper
    // emprunté à `Str::plural()` aurait fait rater sans que rien ne le signale.
    expect(Fr::accorde(0, 'jour'))->toBe('0 jour')
        ->and(Fr::nom(0, 'bien'))->toBe('bien');
});

it('accepte un pluriel irrégulier', function () {
    // Le « s » par défaut couvre les mots de l'interface ; il ne doit pas être le seul
    // recours, sinon la première exception se réglera par une concaténation à la main.
    expect(Fr::accorde(3, 'journal', 'journaux'))->toBe('3 journaux')
        ->and(Fr::accorde(1, 'journal', 'journaux'))->toBe('1 journal');
});

it('rend le nom seul quand le nombre est remplacé par un déterminant', function () {
    // Cas du bandeau d'expiration : « votre bien » / « vos 2 biens ».
    expect(Fr::nom(1, 'bien'))->toBe('bien')
        ->and(Fr::nom(2, 'bien'))->toBe('biens');
});

it('traite un compteur négatif comme sa valeur absolue', function () {
    // Un solde, un écart : « -2 euros » se dit au pluriel, « -1 euro » au singulier.
    expect(Fr::accorde(-1, 'euro'))->toBe('-1 euro')
        ->and(Fr::accorde(-2, 'euro'))->toBe('-2 euros');
});
