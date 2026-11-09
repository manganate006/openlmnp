<?php

use App\Models\Income;
use App\Models\Property;
use App\Models\User;
use App\Services\AirbnbImportService;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = new AirbnbImportService();

    $this->property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Test Airbnb',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 30000000,
        'notary_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
});

it('imports airbnb csv with english headers', function () {
    // ⚠️ « Reservation », pas « Payout » : un `Payout` n'a jamais de Amount/Host fee
    // détaillés dans un export réel (c'est le virement qui RÈGLE des réservations déjà
    // détaillées ailleurs) — l'utiliser ici testait un cas qui n'existe pas, et masquait
    // le vrai défaut de double comptage (voir AirbnbImportTransactionTypesTest).
    $csv = "Date,Type,Confirmation code,Start date,Nights,Guest,Listing,Amount,Host fee,Paid out\n";
    $csv .= "2024-06-15,Reservation,ABC123,2024-06-10,3,John Doe,My Listing,150.00,4.50,145.50\n";
    $csv .= "2024-07-20,Reservation,DEF456,2024-07-18,2,Jane Smith,My Listing,200.00,6.00,194.00\n";

    $file = UploadedFile::fake()->createWithContent('airbnb.csv', $csv);
    $result = $this->service->import($file, $this->property);

    expect($result['imported'])->toBe(2);
    expect($result['skipped'])->toBe(0);
});

it('skips duplicate reservations', function () {
    $csv = "Date,Confirmation code,Amount,Host fee\n";
    $csv .= "2024-06-15,ABC123,150.00,4.50\n";

    $file1 = UploadedFile::fake()->createWithContent('airbnb1.csv', $csv);
    $this->service->import($file1, $this->property);

    // Import again — should skip the duplicate
    $file2 = UploadedFile::fake()->createWithContent('airbnb2.csv', $csv);
    $result = $this->service->import($file2, $this->property);

    expect($result['imported'])->toBe(0);
    expect($result['skipped'])->toBe(1);
});

it('skips negative amounts (refunds)', function () {
    $csv = "Date,Confirmation code,Amount,Host fee\n";
    $csv .= "2024-06-15,REF001,-50.00,0\n";

    $file = UploadedFile::fake()->createWithContent('airbnb.csv', $csv);
    $result = $this->service->import($file, $this->property);

    expect($result['imported'])->toBe(0);
});

it('parses european money format', function () {
    $csv = "Date,Confirmation code,Amount,Host fee\n";
    $csv .= "2024-06-15,EUR001,\"1.234,56\",\"37,04\"\n";

    $file = UploadedFile::fake()->createWithContent('airbnb.csv', $csv);
    $result = $this->service->import($file, $this->property);

    expect($result['imported'])->toBe(1);

    $income = $this->property->incomes()->first();
    expect($income->amount)->toBe(123456); // 1234.56€ in cents
});

it('handles french column headers', function () {
    $csv = "Date,Code de confirmation,Montant,Frais de service hôte,Voyageur\n";
    $csv .= "2024-06-15,FR001,250.00,7.50,Pierre Dupont\n";

    $file = UploadedFile::fake()->createWithContent('airbnb.csv', $csv);
    $result = $this->service->import($file, $this->property);

    expect($result['imported'])->toBe(1);

    $income = $this->property->incomes()->first();
    expect($income->guest_name)->toBe('Pierre Dupont');
    expect($income->platform_fee)->toBe(750); // 7.50€
});

/**
 * « Taxes reversées par Airbnb » est le libellé réel de la colonne sur l'export
 * « Historique des transactions » (vérifié sur un export réel, voir le commentaire de
 * COLUMN_MAP dans AirbnbImportService). Décision de Jérémie du 06/11 : elle doit être
 * captée dans `incomes.tourist_tax`, sans jamais gonfler `amount` — ce n'est pas une
 * recette imposable, Airbnb ne fait que la reverser à la commune.
 */
it('captures the tourist tax from the real "Taxes reversées par Airbnb" column without inflating the rent amount', function () {
    $csv = "Date,Code de confirmation,Montant,Frais de service,Taxes reversées par Airbnb\n";
    $csv .= "2026-11-04,TAX001,200.00,7.20,3.30\n";

    $file = UploadedFile::fake()->createWithContent('airbnb.csv', $csv);
    $result = $this->service->import($file, $this->property);

    expect($result['imported'])->toBe(1);

    $income = Income::where('reservation_ref', 'TAX001')->firstOrFail();
    expect($income->amount)->toBe(20000)       // 200,00 € — inchangé par la taxe
        ->and($income->platform_fee)->toBe(720)
        ->and($income->tourist_tax)->toBe(330); // 3,30 €
});

it('also recognises the "Taxe de séjour" fallback header, in-house convention but unconfirmed on a real Airbnb export', function () {
    $csv = "Date,Code de confirmation,Montant,Taxe de séjour\n";
    $csv .= "2026-11-04,TAX002,150.00,2.20\n";

    $file = UploadedFile::fake()->createWithContent('airbnb.csv', $csv);
    $result = $this->service->import($file, $this->property);

    expect($result['imported'])->toBe(1);

    $income = Income::where('reservation_ref', 'TAX002')->firstOrFail();
    expect($income->tourist_tax)->toBe(220);
});

it('parses a european-formatted tourist tax amount', function () {
    $csv = "Date,Code de confirmation,Montant,Taxes reversées par Airbnb\n";
    $csv .= "2026-11-04,TAXEUR1,\"1.234,56\",\"12,34\"\n";

    $file = UploadedFile::fake()->createWithContent('airbnb.csv', $csv);
    $result = $this->service->import($file, $this->property);

    expect($result['imported'])->toBe(1);

    $income = Income::where('reservation_ref', 'TAXEUR1')->firstOrFail();
    expect($income->amount)->toBe(123456)
        ->and($income->tourist_tax)->toBe(1234);
});

it('leaves tourist tax at zero when the csv has no tax column at all, amount unchanged (regression)', function () {
    $csv = "Date,Code de confirmation,Montant,Frais de service\n";
    $csv .= "2026-11-04,NOTAX1,180.00,6.50\n";

    $file = UploadedFile::fake()->createWithContent('airbnb.csv', $csv);
    $result = $this->service->import($file, $this->property);

    expect($result['imported'])->toBe(1);

    $income = Income::where('reservation_ref', 'NOTAX1')->firstOrFail();
    expect($income->amount)->toBe(18000)
        ->and($income->platform_fee)->toBe(650)
        ->and($income->tourist_tax)->toBe(0);
});

it('does not duplicate the tourist tax when the same file is imported twice', function () {
    $csv = "Date,Code de confirmation,Montant,Taxes reversées par Airbnb\n";
    $csv .= "2026-11-04,TAXDUP1,90.00,1.50\n";

    $first = UploadedFile::fake()->createWithContent('airbnb1.csv', $csv);
    $this->service->import($first, $this->property);

    $second = UploadedFile::fake()->createWithContent('airbnb2.csv', $csv);
    $result = $this->service->import($second, $this->property);

    expect($result['imported'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and(Income::where('reservation_ref', 'TAXDUP1')->count())->toBe(1);
});

it('reconstructs gross and commission at the host-only fee rate without touching the tourist tax reported in the preview', function () {
    // Format « Réservations » : jamais de colonne de taxe (confirmée absente des deux
    // échantillons réels `_admin/data/airbnb-csv/reservations_futures_*.csv`) — la taxe
    // reste à 0 même quand le brut est reconstitué depuis le net.
    $this->property->update(['airbnb_commission_rate' => 18.6]);

    $csv = "Date,Code de confirmation,Montant,Voyageur\n";
    $csv .= "2026-11-04,HOSTONLYTAX,814.00,Marie Durand\n";

    $file = UploadedFile::fake()->createWithContent('reservations.csv', $csv);
    $preview = $this->service->preview($file, $this->property);

    expect($preview['rows'][0]['tourist_tax'])->toBe(0);

    $this->service->import($file, $this->property);

    $income = Income::where('reservation_ref', 'HOSTONLYTAX')->firstOrFail();
    expect($income->amount)->toBe(100000)
        ->and($income->tourist_tax)->toBe(0);
});

it('rejects csv files exceeding the maximum import size', function () {
    // Fichier factice au-delà du plafond de 10 Mo (MAX_IMPORT_BYTES) : simule un DoS mémoire.
    $file = UploadedFile::fake()->create('airbnb-huge.csv', 11 * 1024, 'text/csv');

    $result = $this->service->import($file, $this->property);

    expect($result['imported'])->toBe(0);
    expect($result['skipped'])->toBe(0);
    expect($result['errors'])->toHaveCount(1);
    expect($result['errors'][0])->toContain('taille maximum autorisée');
});

it('rejects csv files exceeding the maximum size on preview', function () {
    $file = UploadedFile::fake()->create('airbnb-huge.csv', 11 * 1024, 'text/csv');

    $result = $this->service->preview($file, $this->property);

    expect($result['rows'])->toBe([]);
    expect($result['errors'])->toHaveCount(1);
    expect($result['errors'][0])->toContain('taille maximum autorisée');
});

/**
 * Bascule Airbnb du 13/10/2026 : les « frais partagés » (3 % HT, 3,6 % TTC côté hôte)
 * laissent place aux « frais hôte uniquement » (15,5 % HT, 18,6 % TTC). L'export
 * « Réservations » ne donnant que le net, la reconstitution du brut doit suivre le taux
 * du bien - sinon la recette déclarée est minorée de quinze points.
 */
it('reconstructs gross and commission at the host-only fee rate', function () {
    $this->property->update(['airbnb_commission_rate' => 18.6]);

    // Export « Réservations » : aucune colonne de commission, le montant est déjà net.
    $csv = "Date,Code de confirmation,Montant,Voyageur\n";
    $csv .= "2026-11-04,HOSTONLY1,814.00,Marie Durand\n";

    $file = UploadedFile::fake()->createWithContent('reservations.csv', $csv);
    $result = $this->service->import($file, $this->property);

    expect($result['imported'])->toBe(1);

    $income = Income::where('reservation_ref', 'HOSTONLY1')->firstOrFail();
    expect($income->amount)->toBe(100000);      // 1 000,00 € bruts
    expect($income->platform_fee)->toBe(18600); // 186,00 € de commission
});

/**
 * Le modèle applicable dépend de la date de CONFIRMATION de la réservation, absente de
 * l'export « Réservations ». Un taux unique par bien est donc nécessairement faux pour une
 * partie des lignes tant que des réservations d'avant la bascule restent à venir. L'import
 * doit le dire au lieu de produire un brut faussement précis.
 */
it('warns that one commission rate cannot cover both airbnb fee models', function () {
    $this->property->update(['airbnb_commission_rate' => 3.6]);

    $csv = "Date,Code de confirmation,Montant\n";
    $csv .= "2026-11-04,MIXED1,964.00\n";

    $file = UploadedFile::fake()->createWithContent('reservations.csv', $csv);
    $result = $this->service->preview($file, $this->property);

    $warnings = implode(' | ', $result['warnings']);
    expect($warnings)->toContain('date de confirmation');
    expect($warnings)->toContain('3,60 %');
});

/**
 * Sans taux configuré, l'import ne doit rien reconstituer en silence : il réclame le taux
 * et nomme les deux valeurs possibles, puisque le lecteur n'a aucune raison de les connaître.
 */
it('asks for a commission rate and names both fee models when none is set', function () {
    $this->property->update(['airbnb_commission_rate' => null]);

    $csv = "Date,Code de confirmation,Montant\n";
    $csv .= "2026-11-04,NORATE1,814.00\n";

    $file = UploadedFile::fake()->createWithContent('reservations.csv', $csv);
    $result = $this->service->preview($file, $this->property);

    $warnings = implode(' | ', $result['warnings']);
    expect($warnings)->toContain('3,6 %');
    expect($warnings)->toContain('18,6 %');
});
