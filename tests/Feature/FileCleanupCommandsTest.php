<?php

use App\Models\FiscalYear;
use App\Models\User;
use App\Support\ImportUpload;
use Illuminate\Support\Facades\Storage;

/**
 * Les deux commandes d'entretien des fichiers en clair.
 *
 * - `openlmnp:cleanup-imports` (planifiée) ramasse les dépôts d'import abandonnés en cours de
 *   route : un export Airbnb déposé, prévisualisé, puis laissé là.
 * - `openlmnp:purge-legacy-files` (manuelle) supprime ce que les versions antérieures ont
 *   laissé sur le disque : liasses fiscales, FEC, et CSV d'import restés à la racine.
 *
 * ⚠️ La seconde est DESTRUCTRICE et ne peut pas être autre chose : un CSV posé à la racine n'a
 * aucun lien vers un utilisateur, ni en base, ni dans son chemin — impossible de le ranger sous
 * son propriétaire. D'où le rapport par défaut, pour que la décision soit vue avant d'être prise.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
});

it('supprime les dépôts d\'import plus vieux que le délai, et garde les récents', function () {
    $disk = Storage::disk('local');
    $directory = ImportUpload::directoryFor($this->user->id);

    $disk->put("{$directory}/vieux.csv", 'a,b');
    $disk->put("{$directory}/recent.csv", 'a,b');
    touch($disk->path("{$directory}/vieux.csv"), now()->subDays(3)->getTimestamp());

    // Un justificatif du même utilisateur, hors du répertoire d'import : jamais concerné.
    $disk->put('documents/' . $this->user->id . '/pieces-comptables/facture.pdf', 'PDF');
    touch($disk->path('documents/' . $this->user->id . '/pieces-comptables/facture.pdf'), now()->subYears(3)->getTimestamp());

    $this->artisan('openlmnp:cleanup-imports')->assertSuccessful();

    expect($disk->exists("{$directory}/vieux.csv"))->toBeFalse();
    expect($disk->exists("{$directory}/recent.csv"))->toBeTrue();
    expect($disk->exists('documents/' . $this->user->id . '/pieces-comptables/facture.pdf'))->toBeTrue();
});

it('ne supprime rien sans --fix et vide tout avec', function () {
    $disk = Storage::disk('local');

    $disk->put('tax-returns/2024/liasse_fiscale_2024.pdf', '%PDF-1.4');
    $disk->put('fec/2024/123456789FEC20241231.txt', "JournalCode\tJournalLib");
    $disk->put('01K4ABCDEF.csv', "Date,Guest\n2024-01-01,Marie");

    // Ce qui ne doit PAS être touché : les justificatifs, le cache DVF, la clé de l'instance.
    $disk->put('documents/' . $this->user->id . '/pieces-comptables/facture.pdf', 'PDF');
    $disk->put('dvf/years.json', '["2024"]');

    $fiscalYear = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2024,
        'status' => FiscalYear::STATUS_CLOSED,
        'pdf_path' => 'tax-returns/2024/liasse_fiscale_2024.pdf',
        'fec_path' => 'fec/2024/123456789FEC20241231.txt',
        'tax_return_generated_at' => '2025-03-01 09:00:00',
    ]);

    $this->artisan('openlmnp:purge-legacy-files')
        ->expectsOutputToContain('Rapport seul')
        ->assertSuccessful();

    expect($disk->exists('tax-returns/2024/liasse_fiscale_2024.pdf'))->toBeTrue();
    expect($disk->exists('01K4ABCDEF.csv'))->toBeTrue();

    $this->artisan('openlmnp:purge-legacy-files --fix')->assertSuccessful();

    expect($disk->exists('tax-returns/2024/liasse_fiscale_2024.pdf'))->toBeFalse();
    expect($disk->exists('fec/2024/123456789FEC20241231.txt'))->toBeFalse();
    expect($disk->exists('01K4ABCDEF.csv'))->toBeFalse();

    expect($disk->exists('documents/' . $this->user->id . '/pieces-comptables/facture.pdf'))->toBeTrue();
    expect($disk->exists('dvf/years.json'))->toBeTrue();

    // Les chemins morts sont vidés, mais le signal « la liasse a été produite » survit :
    // les badges et la checklist d'accueil ne doivent pas régresser au passage de la commande.
    $fiscalYear->refresh();
    expect($fiscalYear->pdf_path)->toBeNull();
    expect($fiscalYear->fec_path)->toBeNull();
    expect($fiscalYear->tax_return_generated_at)->not->toBeNull();
});

it('est idempotente : une seconde exécution ne trouve plus rien', function () {
    Storage::disk('local')->put('tax-returns/2024/liasse_fiscale_2024.pdf', '%PDF-1.4');

    $this->artisan('openlmnp:purge-legacy-files --fix')->assertSuccessful();

    $this->artisan('openlmnp:purge-legacy-files --fix')
        ->expectsOutputToContain('rien à faire')
        ->assertSuccessful();
});
