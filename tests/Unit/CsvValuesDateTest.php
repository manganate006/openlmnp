<?php

use App\Services\Csv\CsvValues;

/**
 * La garde de `CsvValues::date()` testait le résultat APRÈS le report de Carbon :
 * `createFromFormat('d/m/Y', '04/30/2026')` n'échoue jamais, il reporte un « mois 30 »
 * sur juin 2028 — la garde y voyait mois=6, jour=4, deux valeurs valides, et laissait
 * passer une date fausse de deux ans. Elle ne pouvait matériellement rien rejeter.
 */
it('does not silently roll a month-30 date two years forward', function () {
    // Anciennement : « mois 30 » en `d/m/Y` roulait vers juin 2028, la garde
    // laissait passer. Un aller-retour strict rejette `d/m/Y` (le reformatage ne
    // reproduit pas « 04/30/2026 ») et retombe sur `m/d/Y`, seule lecture licite.
    expect(CsvValues::date('04/30/2026'))->toBe('2026-04-30');
});

it('still reads the twelve first days of the month as day-first by default', function () {
    // Ambigu : « 03/04/2026 » est un 3 avril valide EN d/m/Y ET un 4 mars valide EN
    // m/d/Y. Rien dans la chaîne ne permet de trancher — l'ordre des formats
    // (`d/m/Y` avant `m/d/Y`) reste la seule règle, et il ne doit pas bouger sans
    // signal de fichier.
    expect(CsvValues::date('03/04/2026'))->toBe('2026-04-03');
});

it('switches the whole file to month-first when instructed by detectPreferMonthFirst', function () {
    // Même chaîne ambiguë que ci-dessus, mais avec le drapeau qu'aurait levé une
    // AUTRE ligne du même fichier (une date que `d/m/Y` ne peut pas lire).
    expect(CsvValues::date('03/04/2026', preferMonthFirst: true))->toBe('2026-03-04');
});

it('detects a file that must be read month-first from a single unambiguous date', function () {
    // « 04/30/2026 » est impossible en d/m/Y (mois 30) : une seule occurrence
    // suffit à trancher pour tout le fichier, ambiguës comprises.
    expect(CsvValues::detectPreferMonthFirst(['03/04/2026', '04/30/2026', '01/01/2026']))
        ->toBeTrue();
});

it('never claims month-first when every date in the file is genuinely ambiguous', function () {
    // Aucune preuve dans ce fichier : rester en d/m/Y par défaut, faute de signal.
    expect(CsvValues::detectPreferMonthFirst(['03/04/2026', '01/05/2026', '', '  ']))
        ->toBeFalse();
});

it('ignores non slash-separated dates when detecting the file format', function () {
    expect(CsvValues::detectPreferMonthFirst(['2026-01-05', '05-01-2026']))
        ->toBeFalse();
});
