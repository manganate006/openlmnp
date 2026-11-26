<?php

use App\Filament\Resources\FiscalYears\Pages\ListFiscalYears;
use App\Models\Income;
use App\Models\Property;
use App\Models\User;
use App\Services\DocumentExportService;
use Illuminate\Support\Facades\Storage;

/**
 * Années proposées par le filtre de l'export des justificatifs (Exercices fiscaux).
 *
 * La table `documents` ne porte pas d'utilisateur : la lire telle quelle proposait les
 * années des justificatifs de tous les comptes. Signalé sur la PR #25.
 */
function documentYearsIncome(User $user, string $date): Income
{
    $property = Property::withoutGlobalScopes()->forceCreate([
        'user_id'              => $user->id,
        'name'                 => 'Appartement',
        'address'              => '1 rue Test',
        'city'                 => 'Lyon',
        'postal_code'          => '69001',
        'type'                 => 'apartment',
        'total_area'           => 40,
        'rented_area'          => 40,
        'acquisition_date'     => '2018-01-01',
        'acquisition_price'    => 15000000,
        'notary_fees'          => 0,
        'land_percentage'      => 15,
        'rental_start_date'    => '2018-01-01',
        'rental_type'          => Property::RENTAL_SEASONAL,
        'is_primary_residence' => false,
    ]);

    $income = Income::withoutGlobalScopes()->create([
        'property_id'  => $property->id,
        'income_date'  => $date,
        'amount'       => 70000,
        'platform_fee' => 0,
        'tourist_tax'  => 0,
        'source'       => Income::SOURCE_DIRECT,
    ]);

    $path = "documents/{$user->id}/pieces-comptables/{$date}.pdf";
    Storage::disk('local')->put($path, '%PDF-1.4');
    $income->documents()->create(['label' => 'Relevé', 'file_path' => $path, 'document_date' => $date]);

    return $income;
}

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->other = User::factory()->create();
});

it('only lists the years of the user\'s own documents, most recent first', function () {
    documentYearsIncome($this->user, '2023-04-10');
    documentYearsIncome($this->user, '2025-02-01');
    documentYearsIncome($this->user, '2025-06-01');
    documentYearsIncome($this->other, '2019-09-15');

    expect(app(DocumentExportService::class)->documentYears($this->user))->toBe(['2025', '2023'])
        ->and(app(DocumentExportService::class)->documentYears($this->other))->toBe(['2019']);
});

it('does not offer another account\'s years in the export filter', function () {
    documentYearsIncome($this->user, '2024-03-01');
    documentYearsIncome($this->other, '2019-09-15');

    $this->actingAs($this->user);

    $years = (fn () => $this->getDocumentYears())->call(new ListFiscalYears());

    expect($years)->toBe(['2024' => '2024']);
});

it('falls back to the current year when the user has no dated document', function () {
    documentYearsIncome($this->other, '2019-09-15');

    $this->actingAs($this->user);

    $years = (fn () => $this->getDocumentYears())->call(new ListFiscalYears());

    expect($years)->toBe([(string) date('Y') => (string) date('Y')]);
});
