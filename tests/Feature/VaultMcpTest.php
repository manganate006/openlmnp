<?php

use App\Models\Expense;
use App\Models\McpAuditLog;
use App\Models\Property;
use App\Models\User;
use App\Services\Vault\VaultKeyManager;
use Illuminate\Support\Facades\Storage;

// L'API MCP face au coffre-fort. Un jeton d'API n'a pas de session interactive, et
// le transport stdio n'a même pas d'utilisateur : la phrase de passe ne peut pas y
// être saisie. On refuse donc explicitement, plutôt que d'écrire un fichier en
// clair au milieu de fichiers chiffrés ou de renvoyer une erreur obscure.

beforeEach(function () {
    config(['mcp.enabled' => true]);
    Storage::fake('local');

    $this->user = User::factory()->create(['mcp_enabled' => true]);
    $this->token = $this->user->createToken('test-token');

    $this->property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Appartement Test', 'address' => '1 rue Test', 'city' => 'Paris',
        'postal_code' => '75001', 'type' => 'apartment', 'total_area' => 100,
        'rented_area' => 50, 'acquisition_date' => '2020-01-01',
        'acquisition_price' => 30000000, 'notary_fees' => 1500000, 'market_value' => null,
        'land_percentage' => 20, 'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal', 'tva_regime' => 'exempt', 'is_primary_residence' => true,
    ]);

    $this->expense = Expense::forceCreate([
        'property_id' => $this->property->id,
        'expense_date' => '2024-01-01', 'amount' => 15700, 'amount_ht' => 15700,
        'amount_tva' => 0, 'tva_rate' => 0, 'category' => 'property_tax',
        'description' => 'CFE 2024', 'is_dedicated' => true, 'recurring_type' => 'yearly',
    ]);
});

function callTool(string $name, array $arguments = []): \Illuminate\Testing\TestResponse
{
    return test()->withToken(test()->token->plainTextToken)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $name, 'arguments' => $arguments],
    ]);
}

it('attaches documents through the API as long as no vault is enabled', function () {
    $response = callTool('attach_document', [
        'type' => 'expense',
        'record_id' => $this->expense->id,
        'label' => 'Avis CFE 2024',
        'file_base64' => base64_encode('%PDF-1.4 avis CFE'),
        'filename' => 'avis-cfe.pdf',
    ]);

    $response->assertOk();
    expect($this->expense->documents()->count())->toBe(1);
});

it('attache un document coffre activé, et l\'écrit chiffré', function () {
    // ⚠️ Ce test disait l'inverse jusqu'au 2026-09-06 : le dépôt par l'API était
    // refusé dès le coffre activé, faute de pouvoir y saisir une phrase de passe.
    // La paire de clés lève ce refus — chiffrer ne demande que la clé publique.
    // L'agent écrit donc un justificatif qu'il ne pourra jamais relire, ce qui est
    // précisément la propriété recherchée.
    app(VaultKeyManager::class)->create($this->user, 'une phrase de passe solide');

    $response = callTool('attach_document', [
        'type' => 'expense',
        'record_id' => $this->expense->id,
        'label' => 'Avis CFE 2024',
        'file_base64' => base64_encode('%PDF-1.4 avis CFE'),
        'filename' => 'avis-cfe.pdf',
    ]);

    $response->assertOk();

    expect($response->json('result.isError'))->not->toBeTrue()
        ->and($this->expense->documents()->count())->toBe(1);

    // Les deux membres comptent : le dépôt a réussi ET rien n'est lisible.
    $fichiers = Storage::disk('local')->allFiles("documents/{$this->user->id}");

    expect($fichiers)->not->toBeEmpty();

    foreach ($fichiers as $f) {
        $contenu = Storage::disk('local')->get($f);
        expect($contenu)->not->toContain('%PDF')
            ->and(app(\App\Services\Vault\DocumentCipher::class)->isEncryptedContent($contenu))->toBeTrue();
    }
});

it('refuse encore le dépôt par l\'API sur un coffre d\'avant la paire de clés', function () {
    app(VaultKeyManager::class)->create($this->user, 'une phrase de passe solide');

    // Coffre historique : la DEK est nécessaire dans les deux sens.
    \App\Support\Vault::vaultOf($this->user)->update([
        'public_key' => null,
        'wrapped_secret_key' => null,
        'secret_key_nonce' => null,
    ]);

    $response = callTool('attach_document', [
        'type' => 'expense',
        'record_id' => $this->expense->id,
        'label' => 'Avis CFE 2024',
        'file_base64' => base64_encode('%PDF-1.4 avis CFE'),
        'filename' => 'avis-cfe.pdf',
    ]);

    $response->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($this->expense->documents()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles("documents/{$this->user->id}"))->toBeEmpty();
});

it('refuses the bulk export once the vault is enabled', function () {
    app(VaultKeyManager::class)->create($this->user, 'une phrase de passe solide');

    $response = callTool('export_documents');

    $response->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('Coffre-fort activé')
        // L'export écrivait une archive EN CLAIR dans storage/app/private/temp,
        // que rien ne supprime : le refus évite de la produire.
        ->and(Storage::disk('local')->exists('temp'))->toBeFalse();
});

it('still deletes documents through the API, which never reads their content', function () {
    Storage::disk('local')->put("documents/{$this->user->id}/pieces-comptables/facture.pdf", 'contenu');

    $document = \App\Models\Document::forceCreate([
        'documentable_type' => Expense::class,
        'documentable_id' => $this->expense->id,
        'label' => 'Avis CFE',
        'file_path' => "documents/{$this->user->id}/pieces-comptables/facture.pdf",
        'sort_order' => 0,
    ]);

    app(VaultKeyManager::class)->create($this->user, 'une phrase de passe solide');

    $response = callTool('delete_document', ['document_id' => $document->id]);

    $response->assertOk();
    expect($response->json('result.isError'))->not->toBeTrue()
        ->and($this->expense->documents()->count())->toBe(0);
});

it('never writes a passphrase to the audit log', function () {
    callTool('list_expenses', ['passphrase' => 'une phrase de passe solide']);

    $logged = McpAuditLog::withoutGlobalScopes()->latest('id')->first();

    expect($logged)->not->toBeNull()
        ->and(json_encode($logged->parameters))->not->toContain('une phrase de passe solide')
        ->and(json_encode($logged->parameters))->toContain('REDACTED');
});
