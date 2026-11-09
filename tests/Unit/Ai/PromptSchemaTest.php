<?php

use App\Services\Ai\Analysis\Prompts\Prompt;

/**
 * Le schéma d'extraction est envoyé en `strict: true` (OpenAiProvider), ce qui
 * INTERDIT les unions discriminées : d'où la forme « un tableau par famille », tous
 * déclarés à la racine, tous obligatoires, et vides pour les types qui ne les
 * concernent pas.
 *
 * ⚠️ Conséquence : ajouter un tableau sans l'ajouter AUSSI à `required` produit un
 * schéma que le fournisseur rejette — ou pire, qu'il accepte en rendant un objet
 * incomplet. L'erreur est facile (deux endroits, vingt lignes d'écart) et ce test
 * la rend impossible à reproduire, sans énumérer aucune clé : il se met à jour tout
 * seul à chaque ajout.
 */
it('déclare en required exactement les propriétés racine du schéma', function () {
    $schema = Prompt::schema()['schema'];

    expect(array_keys($schema['properties']))->toEqualCanonicalizing($schema['required']);
});

it('déclare chaque tableau de lignes avec ses propres required', function () {
    // Même piège, un cran plus bas : `strict: true` s'applique aussi aux objets
    // imbriqués. Un `items.properties` sans son `items.required` passe la relecture
    // et casse à l'appel.
    $schema = Prompt::schema()['schema'];

    foreach ($schema['properties'] as $nom => $propriete) {
        if (($propriete['type'] ?? null) !== 'array') {
            continue;
        }

        $items = $propriete['items'] ?? [];

        expect($items['required'] ?? null)
            ->not->toBeNull("le tableau « {$nom} » n'a pas de required")
            ->and(array_keys($items['properties'] ?? []))
            ->toEqualCanonicalizing($items['required'], "« {$nom} » : required ≠ properties");
    }
});

it('connaît le type « relevé de revenus »', function () {
    expect(Prompt::TYPES)->toContain('income_statement');
});
