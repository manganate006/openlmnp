<?php

use App\Models\Income;
use App\Models\Property;
use App\Models\User;
use App\Services\AirbnbImportService;
use Illuminate\Http\UploadedFile;

/**
 * L'export « Historique des transactions » d'Airbnb porte une colonne `Type` à quatre
 * valeurs (`Payout`, `Réservation`, `Régularisation`, `Versement de résolution`) : ce ne
 * sont PAS des doublons, elles se complètent. `Payout` est le virement bancaire qui
 * RÈGLE une ou plusieurs lignes `Réservation` déjà détaillées (montant brut + commission) :
 * importer les deux compte la même recette deux fois. Vérifié sur un export réel
 * (`_admin/data/airbnb-csv/`, données gitignorées) : 60 lignes `Payout`, 59 `Réservation`,
 * les données ci-dessous sont INVENTÉES pour ce test.
 *
 * Le même export révèle aussi deux pièges adjacents, corrigés dans le même commit :
 *   - le libellé réel de la colonne commission est « Frais de service », SANS « hôte » —
 *     absent, il faisait conclure à tort au format « Réservations » (sans commission) ;
 *   - un même code de confirmation porte parfois PLUSIEURS lignes `Réservation`
 *     légitimement distinctes (acompte puis solde) : dédoublonner sur le seul code en
 *     perdrait une.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = new AirbnbImportService();

    $this->property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Villa Test',
        'address' => '1 rue Test',
        'city' => 'Nice',
        'postal_code' => '06000',
        'type' => 'house',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 50000000,
        'notary_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
});

/** @param array<int, array<string, string>> $rows */
function transactionsCsv(array $rows): string
{
    $header = [
        'Date', 'Arrivée au plus tard le', 'Type', 'Code de confirmation',
        'Date de réservation', 'Date de début', 'Date de fin', 'Nuits', 'Voyageur',
        'Logement', 'Détails', 'Code de référence', 'Devise', 'Montant', 'Versé',
        'Frais de service', 'Frais de Versement sur carte bancaire', 'Frais de ménage',
        'Revenus bruts', 'Taxes reversées par Airbnb', 'Année des revenus',
    ];

    $lines = [implode(',', $header)];

    foreach ($rows as $row) {
        $line = [];
        foreach ($header as $column) {
            $value = $row[$column] ?? '';
            $line[] = str_contains($value, ',') ? '"' . $value . '"' : $value;
        }
        $lines[] = implode(',', $line);
    }

    return implode("\n", $lines) . "\n";
}

it('does not count a Payout row on top of the Réservation rows it settles', function () {
    $csv = transactionsCsv([
        // Un virement qui règle les deux réservations ci-dessous — informatif
        // seulement, ne doit produire AUCUN revenu.
        ['Date' => '2026-02-05', 'Type' => 'Payout', 'Devise' => 'EUR', 'Versé' => '288.90', 'Année des revenus' => '2026'],
        ['Date' => '2026-02-01', 'Type' => 'Réservation', 'Code de confirmation' => 'SYN001', 'Devise' => 'EUR', 'Montant' => '150.00', 'Frais de service' => '5.55', 'Année des revenus' => '2026'],
        ['Date' => '2026-02-03', 'Type' => 'Réservation', 'Code de confirmation' => 'SYN002', 'Devise' => 'EUR', 'Montant' => '144.45', 'Frais de service' => '5.35', 'Année des revenus' => '2026'],
    ]);

    $file = UploadedFile::fake()->createWithContent('transactions.csv', $csv);
    $result = $this->service->import($file, $this->property);

    expect($result['imported'])->toBe(2)
        ->and($result['errors'])->toBe([]);

    // Recette totale = les deux `Réservation`, jamais + le `Payout` qui les règle.
    expect((int) Income::where('property_id', $this->property->id)->sum('amount'))->toBe(15000 + 14445);
});

it('reports a skipped Payout row explicitly in the preview instead of silently dropping it', function () {
    $csv = transactionsCsv([
        ['Date' => '2026-02-05', 'Type' => 'Payout', 'Devise' => 'EUR', 'Versé' => '288.90', 'Année des revenus' => '2026'],
    ]);

    $file = UploadedFile::fake()->createWithContent('transactions.csv', $csv);
    $result = $this->service->preview($file, $this->property);

    expect($result['rows'])->toHaveCount(1)
        ->and($result['rows'][0]['skipped'])->toBeTrue()
        ->and($result['rows'][0]['skip_reason'])->toContain('Payout');
});

it('maps the real "Frais de service" commission column, without "hôte", and reports the transactions format', function () {
    $this->property->update(['airbnb_commission_rate' => 3.6]);

    $csv = transactionsCsv([
        ['Date' => '2026-03-01', 'Type' => 'Réservation', 'Code de confirmation' => 'FEE001', 'Devise' => 'EUR', 'Montant' => '200.00', 'Frais de service' => '7.20', 'Année des revenus' => '2026'],
    ]);

    $file = UploadedFile::fake()->createWithContent('transactions.csv', $csv);
    $preview = $this->service->preview($file, $this->property);

    // Format « Historique des transactions » correctement reconnu : aucun avertissement
    // n'invite à changer d'export puisque c'est déjà le bon.
    expect(implode(' ', $preview['warnings']))->not->toContain('Format « Réservations » détecté');

    $this->service->import($file, $this->property);

    $income = Income::where('reservation_ref', 'FEE001')->firstOrFail();
    expect($income->amount)->toBe(20000)
        ->and($income->platform_fee)->toBe(720);
});

it('keeps two Réservation lines sharing the same confirmation code when their amounts differ (deposit then balance)', function () {
    $csv = transactionsCsv([
        ['Date' => '2026-04-01', 'Type' => 'Réservation', 'Code de confirmation' => 'SPLIT01', 'Devise' => 'EUR', 'Montant' => '50.00', 'Frais de service' => '1.85', 'Année des revenus' => '2026'],
        ['Date' => '2026-04-20', 'Type' => 'Réservation', 'Code de confirmation' => 'SPLIT01', 'Devise' => 'EUR', 'Montant' => '450.00', 'Frais de service' => '16.65', 'Année des revenus' => '2026'],
    ]);

    $file = UploadedFile::fake()->createWithContent('transactions.csv', $csv);
    $result = $this->service->import($file, $this->property);

    expect($result['imported'])->toBe(2)
        ->and(Income::where('reservation_ref', 'SPLIT01')->count())->toBe(2)
        ->and((int) Income::where('reservation_ref', 'SPLIT01')->sum('amount'))->toBe(5000 + 45000);
});

it('does not recreate duplicates when the exact same file is imported twice', function () {
    $csv = transactionsCsv([
        ['Date' => '2026-05-01', 'Type' => 'Réservation', 'Code de confirmation' => 'DUP001', 'Devise' => 'EUR', 'Montant' => '90.00', 'Frais de service' => '3.33', 'Année des revenus' => '2026'],
    ]);

    $first = UploadedFile::fake()->createWithContent('transactions1.csv', $csv);
    $this->service->import($first, $this->property);

    $second = UploadedFile::fake()->createWithContent('transactions2.csv', $csv);
    $result = $this->service->import($second, $this->property);

    expect($result['imported'])->toBe(0)
        ->and(Income::where('reservation_ref', 'DUP001')->count())->toBe(1);
});

it('imports a resolution payout sharing its confirmation code with an earlier reservation, instead of dropping it as a duplicate', function () {
    $csv = transactionsCsv([
        ['Date' => '2026-06-01', 'Type' => 'Réservation', 'Code de confirmation' => 'RES001', 'Devise' => 'EUR', 'Montant' => '300.00', 'Frais de service' => '11.10', 'Année des revenus' => '2026'],
        // Même code de confirmation, mais un versement DISTINCT (remboursement AirCover),
        // à une autre date et pour un autre montant.
        ['Date' => '2026-07-15', 'Type' => 'Versement de résolution', 'Code de confirmation' => 'RES001', 'Devise' => 'EUR', 'Montant' => '42.00', 'Année des revenus' => '2026'],
    ]);

    $file = UploadedFile::fake()->createWithContent('transactions.csv', $csv);
    $result = $this->service->import($file, $this->property);

    expect($result['imported'])->toBe(2)
        ->and(Income::where('reservation_ref', 'RES001')->count())->toBe(2)
        ->and((int) Income::where('reservation_ref', 'RES001')->sum('amount'))->toBe(30000 + 4200);
});

it('skips a negative Régularisation row and names its type in the reason, rather than a generic message', function () {
    $csv = transactionsCsv([
        ['Date' => '2026-08-01', 'Type' => 'Régularisation', 'Code de confirmation' => 'REG001', 'Devise' => 'EUR', 'Montant' => '-69.50', 'Frais de service' => '2.59', 'Année des revenus' => '2026'],
    ]);

    $file = UploadedFile::fake()->createWithContent('transactions.csv', $csv);
    $preview = $this->service->preview($file, $this->property);

    expect($preview['rows'])->toHaveCount(1)
        ->and($preview['rows'][0]['skipped'])->toBeTrue()
        ->and($preview['rows'][0]['skip_reason'])->toContain('Régularisation');

    $result = $this->service->import($file, $this->property);
    expect($result['imported'])->toBe(0)
        ->and(Income::where('reservation_ref', 'REG001')->exists())->toBeFalse();
});
