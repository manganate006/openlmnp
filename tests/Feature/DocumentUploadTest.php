<?php

use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Models\Expense;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Le dépôt d'un justificatif, de bout en bout (issue #12 de cocool97).
 *
 * ⚠️ Ce parcours n'était exercé par AUCUN test : tous les tests qui manipulaient des
 * documents créaient la ligne directement en base (`documents()->create([...])`). C'est
 * exactement le trou par lequel le défaut est passé — un fichier déposé sans libellé faisait
 * échouer la validation, le message tombait dans une section repliée, et le fichier restait
 * dans `livewire-tmp` sans que rien ne soit écrit.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $property = Property::create([
        'user_id' => $this->user->id, 'name' => 'Studio', 'address' => '1 rue du Test',
        'city' => 'Lyon', 'postal_code' => '69003', 'type' => 'apartment',
        'total_area' => 45, 'rented_area' => 45, 'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000, 'land_percentage' => 15,
        'rental_start_date' => '2022-03-01', 'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);

    $this->expense = Expense::create([
        'property_id' => $property->id, 'category' => 'insurance',
        'description' => 'Assurance PNO', 'amount' => 25000,
        'expense_date' => '2024-05-10',
    ]);
});

it('enregistre un justificatif déposé sans libellé saisi', function () {
    $fichier = UploadedFile::fake()->create('quittance-mai.pdf', 12, 'application/pdf');

    Livewire::test(EditExpense::class, ['record' => $this->expense->getRouteKey()])
        ->fillForm(['documents' => [['file_path' => [$fichier]]]])
        ->call('save')
        ->assertHasNoFormErrors();

    $document = $this->expense->fresh()->documents()->first();

    expect($document)->not->toBeNull()
        // Le libellé vient du nom du fichier : c'est ce qui supprime le mode d'échec.
        ->and($document->label)->toBe('quittance-mai')
        // Et surtout : le fichier a QUITTÉ le répertoire temporaire de Livewire.
        ->and($document->file_path)->not->toContain('livewire-tmp')
        ->and(Storage::disk('local')->exists($document->file_path))->toBeTrue();
});

it('ne remplace jamais un libellé saisi par l\'utilisateur', function () {
    $fichier = UploadedFile::fake()->create('quittance-mai.pdf', 12, 'application/pdf');

    Livewire::test(EditExpense::class, ['record' => $this->expense->getRouteKey()])
        ->fillForm(['documents' => [['label' => 'Quittance de mai 2024', 'file_path' => [$fichier]]]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->expense->fresh()->documents()->first()->label)
        ->toBe('Quittance de mai 2024');
});
