<?php

use App\Filament\Resources\FiscalYears\Pages\ListFiscalYears;
use App\Models\AccountingEntry;
use App\Models\FiscalYear;
use App\Models\Property;
use App\Models\User;
use App\Services\FecService;
use App\Services\TaxReturnService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * La liasse fiscale et le FEC ne touchent plus le disque.
 *
 * Ces deux documents étaient écrits en clair dans `storage/app/private/{tax-returns,fec}` et y
 * restaient. Ils contiennent davantage qu'un justificatif — adresses, SIREN, résultat fiscal,
 * l'intégralité des écritures — et vivaient sur le volume même que le coffre-fort des
 * justificatifs est censé protéger : la promesse du coffre ne tenait pas tant qu'ils étaient là.
 *
 * Ils sont intégralement recalculables, et l'étaient déjà à chaque téléchargement : le fichier
 * posé sur le disque était réécrit puis relu dans la même requête, jamais servi plus tard.
 *
 * ⚠️ Ce que ces tests verrouillent, c'est l'ABSENCE de fichier — pas seulement le fait que le
 * téléchargement marche. Un test qui se contenterait de vérifier le contenu téléchargé passerait
 * aussi bien avant qu'après la correction.
 */
beforeEach(function () {
    // Disque isolé : l'assertion porte sur l'ABSENCE de fichier, elle ne doit pas dépendre
    // de ce qu'un autre test aurait laissé dans le `storage/` partagé.
    Storage::fake('local');

    $this->user = User::factory()->create(['siren' => '123456789']);

    $this->property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Appartement Test',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2023-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 1500000,
        'land_percentage' => 20,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'tva_regime' => 'exempt',
        'is_primary_residence' => false,
    ]);

    $this->fiscalYear = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2024,
        'status' => FiscalYear::STATUS_CLOSED,
        'total_income' => 999999,
        'fiscal_result' => 123456,
    ]);

    AccountingEntry::create([
        'fiscal_year_id' => $this->fiscalYear->id,
        'entry_date' => '2024-06-15',
        'account_code' => '706',
        'label' => 'Loyer Airbnb',
        'debit' => 0,
        'credit' => 150000,
        'piece_ref' => 'REC-1',
        'journal' => 'VE',
    ]);
});

/** @return list<string> Fichiers présents sous les anciens dossiers de documents générés. */
function generatedDocumentFiles(): array
{
    $disk = Storage::disk('local');

    return array_merge($disk->allFiles('tax-returns'), $disk->allFiles('fec'));
}

it('rend la liasse fiscale sans rien écrire sur le disque', function () {
    $pdf = app(TaxReturnService::class)->renderPdf($this->fiscalYear);

    expect($pdf)->toStartWith('%PDF-');
    expect(generatedDocumentFiles())->toBe([]);

    // Le signal « la liasse a été produite » survit — c'est lui que lisent la checklist
    // d'accueil, les badges, les statistiques et les signaux de cycle de vie.
    expect($this->fiscalYear->fresh()->tax_return_generated_at)->not->toBeNull();
    expect($this->fiscalYear->fresh()->pdf_path)->toBeNull();
});

it('rend le FEC sans rien écrire sur le disque', function () {
    $content = app(FecService::class)->render($this->fiscalYear);

    expect($content)->toContain('JournalCode');
    expect($content)->toContain('Loyer Airbnb');
    expect(generatedDocumentFiles())->toBe([]);

    expect($this->fiscalYear->fresh()->fec_generated_at)->not->toBeNull();
    expect($this->fiscalYear->fresh()->fec_path)->toBeNull();
});

it('télécharge la liasse depuis la page Exercices sans laisser de copie', function () {
    Livewire::actingAs($this->user)
        ->test(ListFiscalYears::class)
        ->callAction(TestAction::make('pdf')->table($this->fiscalYear))
        ->assertFileDownloaded('liasse_fiscale_2024.pdf');

    expect(generatedDocumentFiles())->toBe([]);
});

it('télécharge le FEC depuis la page Exercices sans laisser de copie', function () {
    Livewire::actingAs($this->user)
        ->test(ListFiscalYears::class)
        ->callAction(TestAction::make('fec')->table($this->fiscalYear))
        ->assertFileDownloaded('FEC_2024.txt');

    expect(generatedDocumentFiles())->toBe([]);
});

it('génère la liasse et le FEC par MCP sans rien écrire sur le disque', function () {
    config(['mcp.enabled' => true]);
    $this->user->update(['mcp_enabled' => true]);
    $token = $this->user->createToken('test-token');

    foreach (['generate_tax_return', 'generate_fec'] as $tool) {
        $response = $this->withToken($token->plainTextToken)->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => ['year' => 2024]],
        ]);

        $response->assertOk();
        expect($response->json('result.isError'))->not->toBeTrue();
    }

    expect(generatedDocumentFiles())->toBe([]);

    // Et le retour ne promet plus un fichier qui n'existe pas : `file_exists` y était calculé
    // sur un chemin RELATIF au disque, donc toujours faux — l'outil annonçait depuis toujours
    // « le fichier n'a pas pu être vérifié » après une génération pourtant réussie.
    $fiscalYear = FiscalYear::withoutGlobalScopes()
        ->where('user_id', $this->user->id)->where('year', 2024)->first();

    expect($fiscalYear->tax_return_generated_at)->not->toBeNull();
    expect($fiscalYear->fec_generated_at)->not->toBeNull();
});
