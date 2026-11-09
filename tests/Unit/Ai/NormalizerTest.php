<?php

use App\Enums\TvaRate;
use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Property;
use App\Models\User;
use App\Services\Ai\Analysis\Normalizer;

/**
 * Le Normalizer est le garde-fou entre un modèle probabiliste et une
 * comptabilité : c'est lui qui décide qu'un « 8,5 % » inconnu vaut 0 % assorti
 * d'un avertissement plutôt qu'un taux inventé. Ces tests fixent ces règles.
 */
beforeEach(function () {
    $this->user = User::factory()->create();

    $this->property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Studio Test',
        'address' => '12 rue des Lilas',
        'city' => 'Lyon',
        'postal_code' => '69003',
        'type' => 'apartment',
        'total_area' => 40,
        'rented_area' => 40,
        'acquisition_date' => '2021-01-01',
        'acquisition_price' => 15000000,
        'notary_fees' => 1000000,
        'land_percentage' => 15,
        'rental_start_date' => '2021-06-01',
        'rental_type' => 'seasonal',
        'tva_regime' => 'exempt',
        'is_primary_residence' => false,
    ]);

    $this->normalizer = new Normalizer;
});

/** Fabrique une réponse de modèle minimale. */
function reponseModele(array $champs, string $type = 'expense_invoice', float $confiance = 0.9, array $extra = []): array
{
    $fields = [];

    foreach ($champs as $name => $value) {
        $fields[] = [
            'name' => $name,
            'value' => $value === null ? null : (string) $value,
            'confidence' => 0.9,
            'evidence' => "Lu : {$name}",
        ];
    }

    return array_merge([
        'document_type' => $type,
        'confidence' => $confiance,
        'fields' => $fields,
        'items' => [],
        'schedule' => [],
        'lines' => [],
    ], $extra);
}

// =============================================================================
// Montants
// =============================================================================

it('convertit les montants en centimes sans jamais passer par un float', function (string $lu, int $attendu) {
    $result = $this->normalizer->normalize(
        reponseModele(['total_ttc' => $lu]),
        $this->property,
    );

    expect($result->values['amount'])->toBe($attendu);
})->with([
    'format demandé au modèle' => ['143.20', 14320],
    'décimale française' => ['1234,56', 123456],
    'espace fine insécable' => ["1 234,56 €", 123456],
    'espace insécable' => ["1\u{00A0}234,56 €", 123456],
    'séparateur de milliers anglo' => ['1,234.56', 123456],
    'entier' => ['890', 89000],
    'centime isolé' => ['0.01', 1],
    'gros montant' => ['12 500,00 €', 1250000],
]);

it('tranche pour le séparateur de milliers quand un point est suivi de trois chiffres', function () {
    // « 1.234 » vaut 1 234 € dans un document français, pas 1,23 €.
    $result = $this->normalizer->normalize(
        reponseModele(['total_ttc' => '1.234']),
        $this->property,
    );

    expect($result->values['amount'])->toBe(123400)
        // Le cas est ambigu : la confiance est rabaissée pour le signaler.
        ->and($result->confidences['amount'])->toBeLessThanOrEqual(0.6);
});

it('laisse le montant vide plutôt que d\'inventer quand il est illisible', function () {
    $result = $this->normalizer->normalize(
        reponseModele(['total_ttc' => 'illisible']),
        $this->property,
    );

    expect($result->values['amount'])->toBeNull();
});

// =============================================================================
// TVA
// =============================================================================

it('ramène les taux de TVA aux quatre valeurs de l\'énumération', function (string $lu, int $attendu) {
    $result = $this->normalizer->normalize(
        reponseModele(['vat_rate' => $lu]),
        $this->property,
    );

    expect($result->values['tva_rate'])->toBe($attendu);
})->with([
    'taux normal' => ['20', 2000],
    'taux normal avec décimales' => ['20.00', 2000],
    'taux intermédiaire' => ['10', 1000],
    'taux réduit' => ['5.5', 550],
    'taux réduit à la française' => ['5,5', 550],
    'exonéré' => ['0', 0],
    'déjà en points de base' => ['2000', 2000],
]);

it('accepte « tva_rate » quand le modèle francise le nom du champ', function () {
    // Le prompt impose « vat_rate », mais un modèle qui lit une facture
    // française renvoie régulièrement le nom français. L'ignorer proposerait
    // 0 % de TVA sur une facture qui en porte 20 %.
    $result = $this->normalizer->normalize(
        reponseModele(['tva_rate' => '20']),
        $this->property,
    );

    expect($result->values['tva_rate'])->toBe(2000);
});

it('propose 0 % et avertit quand le taux lu n\'existe pas en métropole', function () {
    $result = $this->normalizer->normalize(
        reponseModele(['vat_rate' => '8.5']),
        $this->property,
    );

    expect($result->values['tva_rate'])->toBe(TvaRate::Exempt->value)
        ->and($result->confidences['tva_rate'])->toBe(0.6)
        ->and(implode(' ', $result->warnings))->toContain('Taux de TVA non reconnu');
});

// =============================================================================
// Dates
// =============================================================================

it('normalise les écritures de dates en ISO', function (string $lu, string $attendu) {
    $result = $this->normalizer->normalize(
        reponseModele(['issue_date' => $lu]),
        $this->property,
    );

    expect($result->values['expense_date'])->toBe($attendu);
})->with([
    'ISO' => ['2026-03-12', '2026-03-12'],
    'française' => ['12/03/2026', '2026-03-12'],
    'tirets' => ['12-03-2026', '2026-03-12'],
    'points' => ['12.03.2026', '2026-03-12'],
]);

// =============================================================================
// Catégories
// =============================================================================

it('accepte les onze catégories de l\'énumération SQL', function () {
    foreach (array_keys(Expense::categoryLabels()) as $categorie) {
        $result = $this->normalizer->normalize(
            reponseModele(['category' => $categorie]),
            $this->property,
        );

        expect($result->values['category'])->toBe($categorie);
    }
});

it('classe en « Divers » et avertit quand la catégorie est inconnue', function () {
    $result = $this->normalizer->normalize(
        reponseModele(['category' => 'jardinage']),
        $this->property,
    );

    expect($result->values['category'])->toBe(Expense::CATEGORY_OTHER)
        ->and($result->confidences['category'])->toBe(0.6)
        ->and(implode(' ', $result->warnings))->toContain('Catégorie non reconnue');
});

// =============================================================================
// Champs dérivés
// =============================================================================

it('compose la description « fournisseur — objet »', function () {
    $result = $this->normalizer->normalize(
        reponseModele(['vendor' => 'EDF', 'subject' => 'Électricité janvier 2026']),
        $this->property,
    );

    expect($result->values['description'])->toBe('EDF — Électricité janvier 2026');
});

it('déduit la récurrence de la période couverte', function (string $debut, string $fin, string $attendu) {
    $result = $this->normalizer->normalize(
        reponseModele(['period_start' => $debut, 'period_end' => $fin]),
        $this->property,
    );

    expect($result->values['recurring_type'])->toBe($attendu);
})->with([
    'mensuelle' => ['2026-01-01', '2026-01-31', 'monthly'],
    'trimestrielle' => ['2026-01-01', '2026-03-31', 'quarterly'],
    'annuelle' => ['2026-01-01', '2026-12-31', 'yearly'],
]);

it('avertit quand l\'adresse du document ne correspond pas au bien', function () {
    $result = $this->normalizer->normalize(
        reponseModele(['address_on_document' => '4 avenue de Paris, 75012 Paris']),
        $this->property,
    );

    expect(implode(' ', $result->warnings))->toContain('ne correspond pas');
});

it('ne signale rien quand l\'adresse correspond au bien', function () {
    $result = $this->normalizer->normalize(
        reponseModele(['address_on_document' => '12 rue des Lilas, 69003 LYON']),
        $this->property,
    );

    expect(implode(' ', $result->warnings))->not->toContain('ne correspond pas');
});

// =============================================================================
// Durées d'amortissement — proposées par le code, jamais par le modèle
// =============================================================================

it('propose la durée d\'amortissement du mobilier selon les règles métier', function (array $champs, int $attendu) {
    $result = $this->normalizer->normalize(
        reponseModele($champs, 'furniture_invoice'),
        $this->property,
    );

    expect($result->values['duration_years'])->toBe($attendu);
})->with([
    'neuf standard' => [['total_ttc' => '450', 'item_label' => 'Canapé'], Furniture::DURATION_NEW],
    'occasion' => [['total_ttc' => '450', 'item_label' => 'Canapé', 'is_second_hand' => 'true'], Furniture::DURATION_SECOND_HAND],
    'linge de maison' => [['total_ttc' => '120', 'item_label' => 'Linge de lit'], Furniture::DURATION_SECOND_HAND],
    'gros équipement' => [['total_ttc' => '1500', 'item_label' => 'Cuisine équipée'], Furniture::DURATION_EQUIPMENT],
]);

it('propose la durée d\'amortissement des travaux selon leur nature', function (string $description, int $attendu) {
    $result = $this->normalizer->normalize(
        reponseModele(['subject' => $description, 'total_ttc' => '5000'], 'works_invoice'),
        $this->property,
    );

    expect($result->values['duration_years'])->toBe($attendu);
})->with([
    'gros œuvre' => ['Réfection de toiture', 15],
    'embellissement' => ['Peinture des murs', 5],
    'par défaut' => ['Aménagement salle de bain', 10],
]);

// =============================================================================
// Classement et reclassement
// =============================================================================

it('reclasse en travaux une facture de charge qui n\'en est pas une', function () {
    $result = $this->normalizer->normalize(
        reponseModele(['vendor' => 'Plomberie Martin', 'subject' => 'Fourniture et pose douche', 'total_ttc' => '3200']),
        $this->property,
    );

    expect($result->documentType)->toBe('works_invoice')
        ->and($result->entity())->toBe('work')
        ->and(implode(' ', $result->warnings))->toContain('facture de travaux');
});

it('reclasse en mobilier une facture d\'électroménager au-delà de 500 €', function () {
    $result = $this->normalizer->normalize(
        reponseModele(['vendor' => 'Darty', 'subject' => 'Réfrigérateur combiné', 'total_ttc' => '899']),
        $this->property,
    );

    expect($result->documentType)->toBe('furniture_invoice')
        ->and($result->entity())->toBe('furniture');
});

it('respecte le type imposé par l\'utilisateur', function () {
    $result = $this->normalizer->normalize(
        reponseModele(['vendor' => 'Darty', 'subject' => 'Réfrigérateur', 'total_ttc' => '899']),
        $this->property,
        forcedType: 'expense_invoice',
    );

    expect($result->documentType)->toBe('expense_invoice');
});

it('signale un document non reconnu sans bloquer la saisie', function () {
    $result = $this->normalizer->normalize(
        reponseModele([], 'unknown', 0.1),
        $this->property,
    );

    expect($result->isUnrecognised())->toBeTrue()
        // Le formulaire reste proposé, vide : l'utilisateur peut saisir à la main.
        ->and($result->entity())->toBe('expense')
        ->and(implode(' ', $result->warnings))->toContain('Document non reconnu');
});

// =============================================================================
// Avis d'imposition et assurance
// =============================================================================

it('impose catégorie, taux et récurrence sur un avis de taxe foncière', function () {
    $result = $this->normalizer->normalize(
        reponseModele(['issue_date' => '2026-10-15', 'total_ttc' => '1180', 'vendor' => 'DGFiP'], 'property_tax_notice'),
        $this->property,
    );

    expect($result->values['category'])->toBe(Expense::CATEGORY_PROPERTY_TAX)
        ->and($result->values['tva_rate'])->toBe(TvaRate::Exempt->value)
        ->and($result->values['recurring_type'])->toBe('yearly');
});

it('remet la TVA à zéro sur une assurance et l\'explique', function () {
    $result = $this->normalizer->normalize(
        reponseModele(['total_ttc' => '245', 'tva_rate' => '20', 'vendor' => 'MAIF'], 'insurance'),
        $this->property,
    );

    expect($result->values['category'])->toBe(Expense::CATEGORY_INSURANCE)
        ->and($result->values['tva_rate'])->toBe(TvaRate::Exempt->value)
        ->and($result->values['recurring_type'])->toBe('yearly')
        ->and(implode(' ', $result->warnings))->toContain('taxe sur les conventions d\'assurance');
});

// =============================================================================
// Extraits et confiance
// =============================================================================

it('transporte l\'extrait du document pour l\'infobulle « Lu : … »', function () {
    $result = $this->normalizer->normalize(
        reponseModele(['total_ttc' => '143.20']),
        $this->property,
    );

    expect($result->evidence['amount'])->toBe('Lu : total_ttc');
});

it('borne la confiance globale entre 0 et 1', function () {
    $result = $this->normalizer->normalize(
        reponseModele(['total_ttc' => '10'], 'expense_invoice', 4.2),
        $this->property,
    );

    expect($result->confidence)->toBe(1.0);
});

// =============================================================================
// Relevé bancaire
// =============================================================================

it('ne retient jamais l\'IBAN complet d\'un relevé', function () {
    $result = $this->normalizer->normalize(
        reponseModele(['iban_last4' => 'FR7630006000011234567890189', 'bank_name' => 'Crédit Agricole'], 'bank_statement'),
        $this->property,
    );

    expect($result->values['iban_last4'])->toBe('0189')
        ->and(json_encode($result->toArray()))->not->toContain('FR7630006000011234567890189');
});

it('classe les opérations d\'un relevé et ne coche pas les échéances de prêt', function () {
    $result = $this->normalizer->normalize(
        reponseModele([], 'bank_statement', 0.9, ['lines' => [
            ['date' => '12/03/2026', 'label' => 'EDF PRLV', 'amount' => '89.30', 'direction' => 'debit', 'kind' => 'expense', 'category' => 'energy', 'confidence' => 0.9],
            ['date' => '15/03/2026', 'label' => 'AIRBNB PAYMENTS', 'amount' => '640.00', 'direction' => 'credit', 'kind' => 'income', 'category' => null, 'confidence' => 0.9],
            ['date' => '05/03/2026', 'label' => 'ECH PRET IMMO', 'amount' => '712.44', 'direction' => 'debit', 'kind' => 'loan_payment', 'category' => null, 'confidence' => 0.9],
            ['date' => '20/03/2026', 'label' => 'VIREMENT INTERNE', 'amount' => '200.00', 'direction' => 'debit', 'kind' => 'ignore', 'category' => null, 'confidence' => 0.8],
        ]]),
        $this->property,
    );

    $rows = collect($result->rows);

    expect($rows)->toHaveCount(4)
        ->and($rows->firstWhere('kind', 'expense')['category'])->toBe('energy')
        ->and($rows->firstWhere('kind', 'expense')['amount'])->toBe(8930)
        ->and($rows->firstWhere('kind', 'income')['source'])->toBe('airbnb')
        // Ni les échéances de prêt ni les lignes ignorées ne sont cochées.
        ->and($rows->firstWhere('kind', 'loan_payment')['selected'])->toBeFalse()
        ->and($rows->firstWhere('kind', 'ignore')['selected'])->toBeFalse()
        ->and(implode(' ', $result->warnings))->toContain('échéances de prêt');
});

// =============================================================================
// Tableau d'amortissement
// =============================================================================

it('recule la date d\'effet d\'un mois par rapport à la première échéance', function () {
    // LoanService fait tomber l'échéance i à start_date + i mois : une date
    // d'effet mal placée décalerait tout le tableau d'un mois.
    $result = $this->normalizer->normalize(
        reponseModele(['first_payment_date' => '2026-02-05', 'principal' => '150000'], 'loan_schedule'),
        $this->property,
    );

    expect($result->values['start_date'])->toBe('2026-01-05');
});

it('signale un tableau d\'amortissement incohérent', function () {
    $result = $this->normalizer->normalize(
        reponseModele(['principal' => '100000', 'duration_months' => '3'], 'loan_schedule', 0.9, ['schedule' => [
            ['n' => 1, 'date' => '2026-02-05', 'capital' => '300.00', 'interest' => '80.00', 'insurance' => '12.00', 'remaining' => '99700.00'],
            ['n' => 2, 'date' => '2026-03-05', 'capital' => '301.00', 'interest' => '79.00', 'insurance' => '12.00', 'remaining' => '99399.00'],
        ]]),
        $this->property,
    );

    $warnings = implode(' ', $result->warnings);

    expect($result->rows)->toHaveCount(2)
        // Somme des capitaux très inférieure au capital emprunté.
        ->and($warnings)->toContain('diffère du capital emprunté')
        // 2 échéances lues pour 3 mois annoncés.
        ->and($warnings)->toContain('différé ou modulation');
});

// =============================================================================
// `is_dedicated` — la règle que rien ne verrouillait
// =============================================================================
//
// ⚠️ Ce champ n'est ni extrait du document ni demandé au modèle : le Normalizer le
// DÉDUIT du bien. Il décide pourtant si la quote-part de surface s'applique, donc
// le montant réellement déduit — et sur du mobilier ou des travaux, l'assiette
// amortissable pour toute la durée du plan. Il n'avait aucun test.

it('propose « dédié » quand le bien n\'est pas la résidence principale', function () {
    $r = $this->normalizer->normalize(
        reponseModele(['amount' => '120.00', 'date' => '2026-02-04', 'category' => 'energy']),
        $this->property,
    );

    expect($r->values['is_dedicated'])->toBeTrue();
});

it('propose « partagé » sur une résidence principale — la quote-part s\'appliquera', function () {
    $this->property->forceFill(['is_primary_residence' => true])->save();

    $r = $this->normalizer->normalize(
        reponseModele(['amount' => '120.00', 'date' => '2026-02-04', 'category' => 'energy']),
        $this->property->refresh(),
    );

    expect($r->values['is_dedicated'])->toBeFalse();
});

it('applique la même règle au mobilier et aux travaux, pas seulement aux charges', function () {
    // L'enjeu n'est pas le même : sur une charge la valeur change une déduction,
    // sur une immobilisation elle change l'assiette amortissable de tout le plan.
    $this->property->forceFill(['is_primary_residence' => true])->save();
    $bien = $this->property->refresh();

    $mobilier = $this->normalizer->normalize(
        reponseModele(['amount' => '499.00', 'date' => '2026-01-08'], 'furniture_invoice'),
        $bien,
    );

    $travaux = $this->normalizer->normalize(
        reponseModele(['amount' => '2970.00', 'date' => '2026-02-24'], 'works_invoice'),
        $bien,
    );

    expect($mobilier->values['is_dedicated'])->toBeFalse()
        ->and($travaux->values['is_dedicated'])->toBeFalse();
});

it('marque « à vérifier » plutôt que d\'affirmer', function () {
    // La confiance est figée à 0,6 — exactement le seuil qui fait afficher
    // « À vérifier » en ambre. C'est délibéré : le modèle n'a rien lu, c'est une
    // déduction, et l'écran doit le dire au lieu de la présenter comme un fait.
    $r = $this->normalizer->normalize(
        reponseModele(['amount' => '120.00', 'date' => '2026-02-04', 'category' => 'energy']),
        $this->property,
    );

    expect($r->confidences['is_dedicated'] ?? null)->toBe(0.6);
});

// =============================================================================
// Relevé annuel de plateforme
// =============================================================================

/** Charge la fixture et la normalise comme le ferait le service. */
function releveAnnuel(Normalizer $n, Property $bien, string $fixture = 'releve-annuel-airbnb'): \App\Services\Ai\Analysis\AnalysisResult
{
    $payload = json_decode((string) file_get_contents(base_path("tests/Fixtures/ai/{$fixture}.json")), true);

    return $n->normalize($payload, $bien);
}

it('ne crée pas de recette pour les mois sans location', function () {
    // ⚠️ Trois mois à 0,00 € dans la fixture. Douze lignes dont trois nulles
    // pollueraient la liste des recettes, les graphiques mensuels et le FEC.
    $r = releveAnnuel($this->normalizer, $this->property);

    expect($r->rows)->toHaveCount(9)
        ->and(collect($r->rows)->pluck('month')->all())->toBe([2, 4, 5, 6, 7, 8, 9, 10, 12]);
});

it('porte le BRUT en montant et la retenue du mois en commission', function () {
    // C'est le cœur : le relevé publie deux colonnes, leur écart EST la retenue du
    // mois. Rien n'est réparti. Février : 640,00 − 616,96 = 23,04.
    $r = releveAnnuel($this->normalizer, $this->property);
    $fevrier = collect($r->rows)->firstWhere('month', 2);

    expect($fevrier['amount'])->toBe(64000)
        ->and($fevrier['platform_fee'])->toBe(2304)
        // Décembre porte sa régularisation dans SON mois : 1 120,00 − 1 029,68 = 90,32,
        // soit les 3,6 % (40,32) plus 50,00 de régularisation.
        ->and(collect($r->rows)->firstWhere('month', 12)['platform_fee'])->toBe(9032);
});

it('fait retomber les sommes sur le récapitulatif, au centime', function () {
    $r = releveAnnuel($this->normalizer, $this->property);

    expect(collect($r->rows)->sum('amount'))->toBe(1358050)
        ->and(collect($r->rows)->sum('platform_fee'))->toBe(53890)
        ->and(collect($r->rows)->sum(fn ($l) => $l['amount'] - $l['platform_fee']))->toBe(1304160)
        // Les totaux retombent : aucun avertissement de cohérence.
        ->and(collect($r->warnings)->filter(fn ($w) => str_contains($w, 'ne correspond pas')))->toBeEmpty();
});

it('date chaque recette au DERNIER jour de son mois', function () {
    // Déterministe — c'est ce qui rend le dédoublonnage possible — toujours dans
    // l'année civile donc dans le bon exercice, et juste pour un agrégat de période.
    $r = releveAnnuel($this->normalizer, $this->property);

    expect(collect($r->rows)->firstWhere('month', 2)['date'])->toBe('2025-02-28')
        ->and(collect($r->rows)->firstWhere('month', 4)['date'])->toBe('2025-04-30')
        ->and(collect($r->rows)->firstWhere('month', 12)['date'])->toBe('2025-12-31');
});

it('gère février d\'une année bissextile', function () {
    $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/ai/releve-annuel-airbnb.json')), true);

    foreach ($payload['fields'] as &$f) {
        if ($f['name'] === 'fiscal_year') {
            $f['value'] = '2024';
        }
    }

    $r = $this->normalizer->normalize($payload, $this->property);

    expect(collect($r->rows)->firstWhere('month', 2)['date'])->toBe('2024-02-29');
});

it('déduit la plateforme du vendeur, et retombe sur « other » plutôt que « direct »', function () {
    // ⚠️ `direct` signifierait « location en direct, sans plateforme » : un contresens
    // sur un relevé DE plateforme, même non reconnue.
    $r = releveAnnuel($this->normalizer, $this->property);
    expect($r->values['source'])->toBe('airbnb');

    $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/ai/releve-annuel-airbnb.json')), true);

    foreach ($payload['fields'] as &$f) {
        if ($f['name'] === 'vendor') {
            $f['value'] = 'Plateforme Inconnue SAS';
        }

        if ($f['name'] === 'subject') {
            $f['value'] = 'Relevé annuel';
        }
    }

    expect($this->normalizer->normalize($payload, $this->property)->values['source'])->toBe('other');
});

it('ne rend jamais une commission négative', function () {
    // Une régularisation POSITIVE rend net > brut. Sans garde, la commission devient
    // négative et la recette nette se trouve GONFLÉE.
    $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/ai/releve-annuel-airbnb.json')), true);
    $payload['earnings'] = [['month' => 6, 'gross' => '100.00', 'net' => '130.00', 'confidence' => 0.9]];

    $r = $this->normalizer->normalize($payload, $this->property);

    expect($r->rows[0]['platform_fee'])->toBe(0)
        ->and($r->rows[0]['amount'])->toBe(10000);
});

it('signale un mois entièrement remboursé au lieu de l\'escamoter', function () {
    $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/ai/releve-annuel-airbnb.json')), true);
    $payload['earnings'] = [['month' => 3, 'gross' => '0.00', 'net' => '-120.00', 'confidence' => 0.9]];

    $r = $this->normalizer->normalize($payload, $this->property);

    expect($r->rows)->toBeEmpty()
        ->and(collect($r->warnings)->filter(fn ($w) => str_contains($w, 'mars')))->not->toBeEmpty();
});

it('laisse les dates nulles quand l\'année est illisible, sans perdre le mois', function () {
    $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/ai/releve-annuel-airbnb.json')), true);
    $payload['fields'] = collect($payload['fields'])->reject(fn ($f) => $f['name'] === 'fiscal_year')->values()->all();

    $r = $this->normalizer->normalize($payload, $this->property);

    expect($r->values['fiscal_year'])->toBeNull()
        ->and($r->rows[0]['date'])->toBeNull()
        // Le mois survit : l'écran demandera l'année, et les douze dates s'en déduiront.
        ->and($r->rows[0]['month'])->toBe(2);
});

it('signale une somme mensuelle qui ne retombe pas sur le récapitulatif', function () {
    // Fixture dégradée : février amputé. C'est le contrôle qui rattrape une ligne
    // oubliée ou mal lue, sans coûter un appel de plus.
    $r = releveAnnuel($this->normalizer, $this->property, 'releve-annuel-incoherent');

    expect($r->rows)->toHaveCount(8)
        ->and(collect($r->warnings)->filter(fn ($w) => str_contains($w, 'ne correspond pas')))->not->toBeEmpty();
});

it('signale la taxe de séjour sans la répartir sur les mois', function () {
    $r = releveAnnuel($this->normalizer, $this->property);

    expect(collect($r->warnings)->filter(fn ($w) => str_contains($w, 'taxe de séjour')))->not->toBeEmpty();

    foreach ($r->rows as $ligne) {
        expect($ligne)->not->toHaveKey('tourist_tax');
    }
});

it('propose le taux de TVA du régime du bien', function () {
    expect(releveAnnuel($this->normalizer, $this->property)->values['tva_rate'])->toBe(0);

    $this->property->forceFill(['tva_regime' => 'liable'])->save();

    expect(releveAnnuel($this->normalizer, $this->property->refresh())->values['tva_rate'])->toBe(1000);
});

it('ne compte pas deux fois un mois répété par un saut de page', function () {
    // ⚠️ Constaté sur le relevé Airbnb 2024 RÉEL : « mai » est imprimé deux fois,
    // la ligne étant répétée en tête de la page suivante. Sans garde, l'année est
    // fausse de 1 948,70 € — 10 % — et une recette en trop est créée.
    $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/ai/releve-annuel-airbnb.json')), true);
    $payload['earnings'][] = ['month' => 5, 'gross' => '980.50', 'net' => '945.20', 'confidence' => 0.93];

    $r = $this->normalizer->normalize($payload, $this->property);

    expect($r->rows)->toHaveCount(9)
        ->and(collect($r->rows)->where('month', 5))->toHaveCount(1)
        ->and(collect($r->rows)->sum('amount'))->toBe(1358050);
});
