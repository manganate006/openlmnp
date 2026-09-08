<?php

use App\Services\TaxReturnService;

/**
 * Ce que la liasse ANNONCE doit être ce que le document PRODUIT.
 *
 * L'outil MCP `generate_tax_return` a promis pendant des mois « formulaires 2031, 2033-A à
 * 2033-G » et rendu `['2031', …, '2033-E', '2033-G']`, alors que la vue ne contenait que
 * quatre sections. Un assistant répète l'annonce, l'utilisateur cherche des pages absentes,
 * et l'erreur essaime : elle avait déjà atteint cinq e-mails et trois vues de la vitrine.
 *
 * ⚠️ Le test compare DANS LES DEUX SENS. Vérifier seulement que chaque forme annoncée existe
 * laisserait passer l'inverse — une section ajoutée au document sans être annoncée —, et
 * c'est ce sens-là qui s'était produit pour le 2031-SD : la vue le recevait sans le rendre.
 *
 * ⚠️ L'annexe « Détail des immobilisations » n'est PAS un formulaire Cerfa, et son titre ne
 * commence donc pas par « Formulaire ». Si elle en portait un, ce test réclamerait qu'on
 * l'annonce comme telle — et l'utilisateur la chercherait sur impots.gouv.fr.
 */
it('announces exactly the forms the document contains', function () {
    $markup = file_get_contents(resource_path('views/pdf/tax-return.blade.php'));

    preg_match_all('/<h2>Formulaire ([^<—]+?)\s*—/u', $markup, $matches);

    $inDocument = array_values(array_unique(array_map('trim', $matches[1])));
    $announced = TaxReturnService::FORMS;

    sort($inDocument);
    sort($announced);

    expect($inDocument)->not->toBeEmpty()
        ->and($inDocument)->toBe($announced);
});

it('keeps the MCP tool announcement anchored to the same source', function () {
    $tool = file_get_contents(app_path('Mcp/Tools/GenerateTaxReturn.php'));

    // Pas de liste recopiée : l'outil lit la constante. Une copie se périme en silence, et
    // c'est exactement ce qui s'était produit.
    expect($tool)->toContain('TaxReturnService::FORMS')
        ->and($tool)->not->toContain("'2033-G'")
        ->and($tool)->not->toContain("'2033-E'");
});
