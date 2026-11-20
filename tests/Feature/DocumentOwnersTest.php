<?php

use App\Models\Concerns\HasDocuments;
use App\Models\Income;
use App\Models\Lease;
use App\Models\Property;
use App\Models\User;
use App\Services\DocumentExportService;
use App\Support\DocumentOwners;
use Illuminate\Support\Facades\Storage;

/**
 * La liste unique des porteurs de justificatifs (`DocumentOwners`).
 *
 * Elle vivait en six exemplaires : un formulaire qui affiche des pièces sans être
 * inscrit ici les dépose, et l'export ne les rend jamais. Ce test lit les formulaires
 * eux-mêmes, pour que le prochain porteur ne dépende pas de la mémoire de quelqu'un.
 */
function documentOwnerProperty(User $user): Property
{
    return Property::withoutGlobalScopes()->forceCreate([
        'user_id'              => $user->id,
        'name'                 => 'Appartement',
        'address'              => '1 rue Test',
        'city'                 => 'Lyon',
        'postal_code'          => '69001',
        'type'                 => 'apartment',
        'total_area'           => 40,
        'rented_area'          => 40,
        'acquisition_date'     => '2020-01-01',
        'acquisition_price'    => 15000000,
        'notary_fees'          => 0,
        'land_percentage'      => 15,
        'rental_start_date'    => '2025-01-01',
        'rental_type'          => Property::RENTAL_LONG_TERM,
        'is_primary_residence' => false,
    ]);
}

function documentOwnerLease(Property $property): Lease
{
    return Lease::create([
        'property_id'    => $property->id,
        'tenant_name'    => 'Camille Martin',
        'start_date'     => '2025-01-01',
        'rent_amount'    => 65000,
        'charges_amount' => 5000,
        'charges_mode'   => Lease::CHARGES_PROVISIONS,
        'due_day'        => 5,
    ]);
}

function documentOwnerIncome(Property $property): Income
{
    return Income::create([
        'property_id'  => $property->id,
        'income_date'  => '2025-03-05',
        'amount'       => 70000,
        'platform_fee' => 0,
        'tourist_tax'  => 0,
        'source'       => Income::SOURCE_DIRECT,
    ]);
}

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->property = documentOwnerProperty($this->user);
});

it('registers every form that shows supporting documents', function () {
    $resources = glob(app_path('Filament/Resources/*/*Resource.php'));
    $covered = [];

    foreach ($resources as $file) {
        $forms = glob(dirname($file) . '/Schemas/*Form.php');
        $showsDocuments = collect($forms)->contains(
            fn (string $form) => str_contains((string) file_get_contents($form), 'DocumentsSection::make('),
        );

        if (! $showsDocuments) {
            continue;
        }

        $class = 'App\\Filament\\Resources\\' . basename(dirname($file)) . '\\' . basename($file, '.php');
        $covered[] = $class::getModel();
    }

    expect($covered)->toContain(Income::class, Lease::class)
        ->and(array_diff($covered, DocumentOwners::classes()))->toBe([]);
});

it('only lists models that can carry documents', function () {
    foreach (DocumentOwners::classes() as $class) {
        expect(class_uses_recursive($class))->toHaveKey(HasDocuments::class);
    }
});

it('exports the documents of an income and of a lease in their own folders', function () {
    // Pas de session ailleurs : sur `/mcp`, elle passerait avant le jeton.
    $this->actingAs($this->user);
    $income = documentOwnerIncome($this->property);
    $lease = documentOwnerLease($this->property);

    foreach ([[$income, 'virement.pdf'], [$lease, 'bail-signe.pdf']] as [$record, $file]) {
        $path = "documents/{$this->user->id}/pieces-comptables/{$file}";
        Storage::disk('local')->put($path, '%PDF-1.4');
        $record->documents()->create(['label' => pathinfo($file, PATHINFO_FILENAME), 'file_path' => $path]);
    }

    $chemins = collect(app(DocumentExportService::class)->manifest($this->user))->pluck('chemin')->sort()->values();

    // Sans date propre, une pièce se range à la date de ce qui la porte.
    expect($chemins->all())->toBe([
        '2025/baux/sans-date_bail-signe.pdf',
        '2025/recettes/sans-date_virement.pdf',
    ]);

    $seulsLesBaux = collect(app(DocumentExportService::class)->manifest($this->user, null, 'lease'))->pluck('chemin');

    expect($seulsLesBaux->all())->toBe(['2025/baux/sans-date_bail-signe.pdf']);
});

it('attaches a document to a lease through MCP, never to another user\'s', function () {
    config(['mcp.enabled' => true]);
    $this->user->forceFill(['mcp_enabled' => true])->save();
    $token = $this->user->createToken('test-token')->plainTextToken;
    $mine = documentOwnerLease($this->property);
    $foreign = documentOwnerLease(documentOwnerProperty(User::factory()->create()));

    $attach = fn (Lease $lease) => $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id'      => 1,
        'method'  => 'tools/call',
        'params'  => [
            'name'      => 'attach_document',
            'arguments' => [
                'type'        => 'lease',
                'record_id'   => $lease->id,
                'label'       => 'Bail signé',
                'file_base64' => base64_encode('%PDF-1.4 bail'),
                'filename'    => 'bail.pdf',
            ],
        ],
    ]);

    $result = json_decode($attach($mine)->assertOk()->json('result.content.0.text', '{}'), true);

    expect($result['success'] ?? false)->toBeTrue()
        ->and($mine->documents()->count())->toBe(1);

    $attach($foreign);

    expect($foreign->documents()->count())->toBe(0);
});
