<?php

use App\Filament\Pages\EditProfile;
use App\Filament\Pages\ImportCsv;
use App\Filament\Pages\McpTokens;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Livewire\FeedbackPrompt;
use App\Models\Expense;
use App\Models\Feedback;
use App\Models\Property;
use App\Models\User;
use App\Services\Csv\CsvProfile;
use App\Services\CsvExportService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Un utilisateur ne doit jamais pouvoir lire ou modifier ce qui appartient à un autre,
 * même en trafiquant l'URL signée ou l'état Livewire envoyé par son navigateur.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->other = User::factory()->create();

    $this->secret = "documents/{$this->other->id}/pieces-comptables/2024-01-01_avis.pdf";
    Storage::disk('local')->put($this->secret, '%PDF-1.4 secret');
});

function lienDocument(string $path): string
{
    return URL::temporarySignedRoute('documents.show', now()->addMinutes(5), ['path' => $path]);
}

function bienDe(User $user): Property
{
    return Property::create([
        'user_id' => $user->id, 'name' => 'Studio', 'address' => '1 rue du Test',
        'city' => 'Lyon', 'postal_code' => '69003', 'type' => 'apartment',
        'total_area' => 45, 'rented_area' => 45, 'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000, 'land_percentage' => 15,
        'rental_start_date' => '2022-03-01', 'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
}

it('sert un justificatif à son propriétaire', function () {
    $this->actingAs($this->other)
        ->get(lienDocument($this->secret))
        ->assertOk();
});

it('refuse un chemin qui remonte vers le dossier d\'un autre utilisateur', function () {
    $detour = "documents/{$this->user->id}/../{$this->other->id}/pieces-comptables/2024-01-01_avis.pdf";

    $this->actingAs($this->user)
        ->get(lienDocument($detour))
        ->assertNotFound();
});

it('refuse d\'enregistrer un justificatif qui pointe chez un autre utilisateur', function () {
    $this->actingAs($this->user);

    $property = bienDe($this->user);

    $expense = Expense::create([
        'property_id' => $property->id, 'category' => 'insurance',
        'description' => 'Assurance PNO', 'amount' => 25000,
        'expense_date' => '2024-05-10',
    ]);

    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->fillForm(['documents' => [['label' => 'Avis', 'file_path' => [$this->secret]]]])
        ->call('save');

    expect($expense->fresh()->documents()->count())->toBe(0);
});

it('refuse une photo de bien qui pointe chez un autre utilisateur', function () {
    $this->actingAs($this->user);

    $property = bienDe($this->user);
    $photo = "documents/{$this->other->id}/photos-biens/2022-01-01_maison.jpg";
    Storage::disk('local')->put($photo, 'jpeg');

    Livewire::test(EditProperty::class, ['record' => $property->getRouteKey()])
        ->fillForm(['photo_path' => [$photo]])
        ->call('save');

    expect($property->fresh()->photo_path)->toBeNull();
});

it('ne lit pas un fichier hors du dossier d\'import', function () {
    $releve = "documents/{$this->other->id}/pieces-comptables/releve.csv";
    Storage::disk('local')->put($releve, "Date;Montant;Libellé\n15/03/2024;100,00;Secret\n");

    $property = bienDe($this->user);

    // Par le formulaire, puis en posant directement la propriété publique de l'aperçu.
    $page = Livewire::actingAs($this->user)
        ->test(ImportCsv::class)
        ->set('data.property_id', $property->id)
        ->set('data.target', CsvProfile::TARGET_EXPENSE)
        ->set('data.csv_file', ['u1' => $releve])
        ->call('preview');

    expect($page->get('previewData'))->toBeNull();

    $page->set('previewPropertyId', $property->id)
        ->set('previewTarget', CsvProfile::TARGET_EXPENSE)
        ->set('previewFilePath', $releve)
        ->call('refreshPreview');

    expect($page->get('previewData'))->toBeNull();
});

it('garde l\'e-mail et les jetons du compte démo public', function () {
    config()->set('mcp.enabled', true);
    config()->set('mcp.demo.enabled', true);
    config()->set('mcp.demo.email', 'demo@exemple.test');

    $demo = User::factory()->create(['email' => 'demo@exemple.test']);
    $demo->forceFill(['mcp_enabled' => true])->save();
    $jeton = $demo->createToken('demo-public-readonly');

    Livewire::actingAs($demo)
        ->test(EditProfile::class)
        ->fillForm(['email' => 'pirate@exemple.test', 'timezone' => 'Europe/Paris'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($demo->fresh()->email)->toBe('demo@exemple.test');

    Livewire::actingAs($demo)
        ->test(McpTokens::class)
        ->call('revokeToken', $jeton->accessToken->id)
        ->assertForbidden();

    expect($demo->tokens()->count())->toBe(1);
});

it('ne laisse pas le navigateur choisir le retour d\'avis à modifier', function () {
    config()->set('feedback.enabled', true);
    config()->set('feedback.audiences', 'demo,user');
    config()->set('feedback.variants', 'a,b,c');

    $autre = Feedback::factory()->create(['user_id' => $this->other->id]);

    expect(fn () => Livewire::actingAs($this->user)
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->set('feedbackId', $autre->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('neutralise les formules dans les exports CSV', function () {
    expect(CsvExportService::cell('=HYPERLINK("http://exemple.test")'))->toBe('\'=HYPERLINK("http://exemple.test")')
        ->and(CsvExportService::cell('@SUM(A1)'))->toBe('\'@SUM(A1)')
        ->and(CsvExportService::cell('-12,50'))->toBe('-12,50')
        ->and(CsvExportService::cell('Assurance PNO'))->toBe('Assurance PNO')
        ->and(CsvExportService::cell(1250))->toBe(1250);
});
