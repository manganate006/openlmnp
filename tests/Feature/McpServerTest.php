<?php

use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Furniture;
use App\Models\Income;
use App\Models\McpAuditLog;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['mcp.enabled' => true]);

    $this->user = User::factory()->create(['mcp_enabled' => true]);
    $this->token = $this->user->createToken('test-token');

    $this->property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Appartement Test',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 50,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 30000000,
        'notary_fees' => 1500000,
        'market_value' => null,
        'land_percentage' => 20,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'tva_regime' => 'exempt',
        'is_primary_residence' => true,
    ]);
});

// === AUTH ===

it('rejects MCP requests without a token', function () {
    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [],
    ])->assertUnauthorized();
});

it('rejects MCP requests when globally disabled', function () {
    config(['mcp.enabled' => false]);

    $this->withToken($this->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [],
        ])->assertNotFound();
});

it('rejects MCP requests when user has MCP disabled', function () {
    $this->user->update(['mcp_enabled' => false]);

    $this->withToken($this->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [],
        ])->assertForbidden();
});

// === DATA ISOLATION ===

it('prevents user A from seeing user B properties via MCP', function () {
    $userB = User::factory()->create(['mcp_enabled' => true]);

    Property::forceCreate([
        'user_id' => $userB->id,
        'name' => 'Bien de B',
        'address' => '2 rue B',
        'city' => 'Lyon',
        'postal_code' => '69001',
        'type' => 'house',
        'total_area' => 80,
        'rented_area' => 80,
        'acquisition_date' => '2021-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2021-06-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);

    $response = $this->withToken($this->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_properties',
                'arguments' => [],
            ],
        ]);

    $response->assertOk();
    $result = json_decode($response->json('result.content.0.text', '{}'), true);

    expect($result['count'])->toBe(1);
    expect($result['properties'][0]['name'])->toBe('Appartement Test');
});

// === READ TOOLS ===

it('lists properties via MCP', function () {
    $response = $this->withToken($this->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_properties',
                'arguments' => [],
            ],
        ]);

    $response->assertOk();
    $result = json_decode($response->json('result.content.0.text', '{}'), true);

    expect($result['count'])->toBe(1);
    expect($result['properties'][0]['acquisition_price_eur'])->toBe('300000.00');
});

// === WRITE TOOLS ===

it('creates an income via MCP', function () {
    $response = $this->withToken($this->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'create_income',
                'arguments' => [
                    'property_id' => $this->property->id,
                    'income_date' => '2025-07-15',
                    'amount' => 150.50,
                    'source' => 'airbnb',
                    'guest_name' => 'Jean Dupont',
                ],
            ],
        ]);

    $response->assertOk();
    $result = json_decode($response->json('result.content.0.text', '{}'), true);

    expect($result['success'])->toBeTrue();
    expect($result['amount_eur'])->toBe('150.50');

    $income = Income::where('property_id', $this->property->id)->first();
    expect($income)->not->toBeNull();
    expect($income->amount)->toBe(15050);
});

it('creates an expense via MCP', function () {
    $response = $this->withToken($this->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'create_expense',
                'arguments' => [
                    'property_id' => $this->property->id,
                    'expense_date' => '2025-03-01',
                    'amount' => 250.00,
                    'category' => 'insurance',
                    'description' => 'Assurance habitation',
                ],
            ],
        ]);

    $response->assertOk();
    $result = json_decode($response->json('result.content.0.text', '{}'), true);

    expect($result['success'])->toBeTrue();
    expect($result['amount_eur'])->toBe('250.00');

    $expense = Expense::where('property_id', $this->property->id)->first();
    expect($expense->amount)->toBe(25000);
});

// === ATTACH DOCUMENT ===

it('attaches a document via file_base64', function () {
    $expense = Expense::forceCreate([
        'property_id' => $this->property->id,
        'expense_date' => '2024-01-01',
        'amount' => 15700,
        'amount_ht' => 15700,
        'amount_tva' => 0,
        'tva_rate' => 0,
        'category' => 'property_tax',
        'description' => 'CFE 2024',
        'is_dedicated' => true,
        'recurring_type' => 'yearly',
    ]);

    $content = '%PDF-1.4 fake pdf content for testing';
    $base64  = base64_encode($content);

    $response = $this->withToken($this->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'attach_document',
                'arguments' => [
                    'type'          => 'expense',
                    'record_id'     => $expense->id,
                    'label'         => 'Avis CFE 2024',
                    'file_base64'   => $base64,
                    'filename'      => '2024_avis-cfe_157eur.pdf',
                    'amount'        => 157.00,
                    'document_date' => '2024-01-01',
                ],
            ],
        ]);

    $response->assertOk();
    $result = json_decode($response->json('result.content.0.text', '{}'), true);

    expect($result['success'])->toBeTrue();
    expect($result['amount_eur'])->toBe('157.00');
    expect($expense->documents()->count())->toBe(1);
});

it('attaches a document via file_path', function () {
    $expense = Expense::forceCreate([
        'property_id' => $this->property->id,
        'expense_date' => '2024-01-01',
        'amount' => 15700,
        'amount_ht' => 15700,
        'amount_tva' => 0,
        'tva_rate' => 0,
        'category' => 'property_tax',
        'description' => 'CFE 2024',
        'is_dedicated' => true,
        'recurring_type' => 'yearly',
    ]);

    // Create a temp file in the allowed prefix
    $prefix  = sys_get_temp_dir() . '/mcp_test_prefix';
    @mkdir($prefix, 0755, true);
    $tmpFile = $prefix . '/test_document.pdf';
    file_put_contents($tmpFile, '%PDF-1.4 fake pdf content');

    config(['mcp.file_path_prefix' => $prefix]);

    $response = $this->withToken($this->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'attach_document',
                'arguments' => [
                    'type'      => 'expense',
                    'record_id' => $expense->id,
                    'label'     => 'Avis CFE 2024',
                    'file_path' => $tmpFile,
                ],
            ],
        ]);

    @unlink($tmpFile);
    @rmdir($prefix);

    $response->assertOk();
    $result = json_decode($response->json('result.content.0.text', '{}'), true);

    expect($result['success'])->toBeTrue();
    expect($expense->documents()->count())->toBe(1);
});

it('blocks file_path outside the allowed prefix', function () {
    $expense = Expense::forceCreate([
        'property_id' => $this->property->id,
        'expense_date' => '2024-01-01',
        'amount' => 15700,
        'amount_ht' => 15700,
        'amount_tva' => 0,
        'tva_rate' => 0,
        'category' => 'property_tax',
        'description' => 'CFE 2024',
        'is_dedicated' => true,
        'recurring_type' => 'yearly',
    ]);

    config(['mcp.file_path_prefix' => '/tmp/allowed_prefix']);

    $response = $this->withToken($this->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'attach_document',
                'arguments' => [
                    'type'      => 'expense',
                    'record_id' => $expense->id,
                    'label'     => 'Test traversal',
                    'file_path' => '/etc/passwd',
                ],
            ],
        ]);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeTrue();
    expect($expense->documents()->count())->toBe(0);
});

it('blocks file_url targeting a private or link-local IP (SSRF protection)', function () {
    // Garde-fou : si le code tentait malgré tout un appel réseau réel, le test échoue au lieu de sortir.
    Http::preventStrayRequests();

    $expense = Expense::forceCreate([
        'property_id' => $this->property->id,
        'expense_date' => '2024-01-01',
        'amount' => 15700,
        'amount_ht' => 15700,
        'amount_tva' => 0,
        'tva_rate' => 0,
        'category' => 'property_tax',
        'description' => 'CFE 2024',
        'is_dedicated' => true,
        'recurring_type' => 'yearly',
    ]);

    foreach (['http://127.0.0.1/x.pdf', 'http://169.254.169.254/x'] as $maliciousUrl) {
        $response = $this->withToken($this->token->plainTextToken)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'attach_document',
                    'arguments' => [
                        'type'      => 'expense',
                        'record_id' => $expense->id,
                        'label'     => 'Tentative SSRF',
                        'file_url'  => $maliciousUrl,
                    ],
                ],
            ]);

        $response->assertOk();
        expect($response->json('result.isError'))->toBeTrue();
    }

    expect($expense->documents()->count())->toBe(0);
});

it('does not follow redirects on file_url (SSRF protection)', function () {
    // Hôte public (IP littérale → pas de DNS) renvoyant un 3xx vers une IP interne :
    // la redirection ne doit PAS être suivie, sinon le contrôle anti-SSRF est contourné.
    Http::preventStrayRequests();
    Http::fake([
        '1.1.1.1/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
    ]);

    $expense = Expense::forceCreate([
        'property_id' => $this->property->id,
        'expense_date' => '2024-01-01',
        'amount' => 15700,
        'amount_ht' => 15700,
        'amount_tva' => 0,
        'tva_rate' => 0,
        'category' => 'property_tax',
        'description' => 'CFE 2024',
        'is_dedicated' => true,
        'recurring_type' => 'yearly',
    ]);

    $response = $this->withToken($this->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'attach_document',
                'arguments' => [
                    'type'      => 'expense',
                    'record_id' => $expense->id,
                    'label'     => 'Tentative SSRF via redirection',
                    'file_url'  => 'http://1.1.1.1/evil.pdf',
                ],
            ],
        ]);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeTrue();
    expect($expense->documents()->count())->toBe(0);
});

it('rejects the html extension for attach_document', function () {
    $expense = Expense::forceCreate([
        'property_id' => $this->property->id,
        'expense_date' => '2024-01-01',
        'amount' => 15700,
        'amount_ht' => 15700,
        'amount_tva' => 0,
        'tva_rate' => 0,
        'category' => 'property_tax',
        'description' => 'CFE 2024',
        'is_dedicated' => true,
        'recurring_type' => 'yearly',
    ]);

    $content = '<html><body>fake html document</body></html>';
    $base64  = base64_encode($content);

    $response = $this->withToken($this->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'attach_document',
                'arguments' => [
                    'type'        => 'expense',
                    'record_id'   => $expense->id,
                    'label'       => 'Tentative HTML',
                    'file_base64' => $base64,
                    'filename'    => 'document.html',
                ],
            ],
        ]);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->not->toContain('html');
    expect($expense->documents()->count())->toBe(0);
});

it('rejects attach_document with no file source', function () {
    $expense = Expense::forceCreate([
        'property_id' => $this->property->id,
        'expense_date' => '2024-01-01',
        'amount' => 15700,
        'amount_ht' => 15700,
        'amount_tva' => 0,
        'tva_rate' => 0,
        'category' => 'property_tax',
        'description' => 'CFE 2024',
        'is_dedicated' => true,
        'recurring_type' => 'yearly',
    ]);

    $response = $this->withToken($this->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'attach_document',
                'arguments' => [
                    'type'      => 'expense',
                    'record_id' => $expense->id,
                    'label'     => 'Test',
                    'filename'  => 'test.pdf',
                ],
            ],
        ]);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeTrue();
});

// === AUDIT LOGGING ===

it('logs MCP tool calls to audit table', function () {
    $this->withToken($this->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_properties',
                'arguments' => [],
            ],
        ]);

    $log = McpAuditLog::where('user_id', $this->user->id)->first();
    expect($log)->not->toBeNull();
    expect($log->tool_name)->toBe('list_properties');
    expect($log->result_status)->toBe('success');
});

// === CLOSED FISCAL YEAR (lecture sans recalcul) ===

it('computes a closed fiscal year via MCP without recalculating it', function () {
    // Totaux volontairement faux : s'ils changent, un recalcul a eu lieu
    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2024,
        'status' => FiscalYear::STATUS_CLOSED,
        'total_income' => 999999,
        'fiscal_result' => 123456,
    ]);

    $response = $this->withToken($this->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'compute_fiscal_year',
                'arguments' => ['year' => 2024],
            ],
        ]);

    $response->assertOk();
    expect($response->json('result.isError'))->not->toBeTrue();

    $result = json_decode($response->json('result.content.0.text', '{}'), true);
    expect($result['status'])->toBe(FiscalYear::STATUS_CLOSED);
    expect($result['total_income_eur'])->toBe('9999.99');
    expect($result['fiscal_result_eur'])->toBe('1234.56');

    $fiscalYear = FiscalYear::withoutGlobalScopes()
        ->where('user_id', $this->user->id)
        ->where('year', 2024)
        ->first();
    expect($fiscalYear->total_income)->toBe(999999);
    expect($fiscalYear->status)->toBe(FiscalYear::STATUS_CLOSED);
});

it('generates a tax return for a closed fiscal year from frozen totals', function () {
    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2024,
        'status' => FiscalYear::STATUS_CLOSED,
        'total_income' => 999999,
        'fiscal_result' => 123456,
    ]);

    $response = $this->withToken($this->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'generate_tax_return',
                'arguments' => ['year' => 2024],
            ],
        ]);

    $response->assertOk();
    expect($response->json('result.isError'))->not->toBeTrue();

    $result = json_decode($response->json('result.content.0.text', '{}'), true);
    expect($result['fiscal_year']['status'])->toBe(FiscalYear::STATUS_CLOSED);
    expect($result['declaration_summary']['total_income_eur'])->toBe('9999.99');
    expect($result['pdf']['filename'])->toBe('liasse_fiscale_2024.pdf');

    // La génération a abouti (date enregistrée) et les totaux figés n'ont pas bougé
    $fiscalYear = FiscalYear::withoutGlobalScopes()
        ->where('user_id', $this->user->id)
        ->where('year', 2024)
        ->first();
    expect($fiscalYear->tax_return_generated_at)->not->toBeNull();
    expect($fiscalYear->total_income)->toBe(999999);
});

it('generates a FEC for a closed fiscal year without recalculating it', function () {
    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2024,
        'status' => FiscalYear::STATUS_CLOSED,
        'total_income' => 999999,
        'fiscal_result' => 123456,
    ]);

    $response = $this->withToken($this->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'generate_fec',
                'arguments' => ['year' => 2024],
            ],
        ]);

    $response->assertOk();
    expect($response->json('result.isError'))->not->toBeTrue();

    $result = json_decode($response->json('result.content.0.text', '{}'), true);
    expect($result['fiscal_year']['status'])->toBe(FiscalYear::STATUS_CLOSED);
    expect($result['fiscal_year']['fiscal_result_eur'])->toBe('1234.56');

    // Les totaux figés n'ont pas bougé
    $fiscalYear = FiscalYear::withoutGlobalScopes()
        ->where('user_id', $this->user->id)
        ->where('year', 2024)
        ->first();
    expect($fiscalYear->total_income)->toBe(999999);
});

// === SOLDES D'OUVERTURE (reprise de dossier) ===

it('exposes the opening balances of a reprise fiscal year through get_fiscal_year', function () {
    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2026,
        'status' => FiscalYear::STATUS_DRAFT,
        'opening_deferred_depreciation' => 1200000,   // 12 000 €
        'opening_accumulated_depreciation' => 4500000, // 45 000 €
        'opening_source' => FiscalYear::OPENING_SOURCE_LIASSE,
        'opening_deficits' => [
            ['origin_year' => 2022, 'amount' => 120000],
            ['origin_year' => 2023, 'amount' => 80000],
        ],
    ]);

    $response = $this->withToken($this->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_fiscal_year',
                'arguments' => ['year' => 2026],
            ],
        ]);

    $response->assertOk();
    expect($response->json('result.isError'))->not->toBeTrue();

    $result = json_decode($response->json('result.content.0.text', '{}'), true);

    expect($result['opening']['is_opening_year'])->toBeTrue()
        ->and($result['opening']['deferred_depreciation_eur'])->toBe('12000.00')
        ->and($result['opening']['accumulated_depreciation_eur'])->toBe('45000.00')
        ->and($result['opening']['accumulated_depreciation_is_control'])->toBeTrue()
        ->and($result['opening']['source'])->toBe('liasse')
        ->and($result['opening']['deficits'])->toHaveCount(2)
        ->and($result['opening']['deficits'][0]['origin_year'])->toBe(2022)
        ->and($result['opening']['deficits'][0]['amount_eur'])->toBe('1200.00')
        ->and($result['opening']['deficits_total_eur'])->toBe('2000.00');
});

it('reports no opening balance on an ordinary fiscal year', function () {
    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2024,
        'status' => FiscalYear::STATUS_CLOSED,
        'total_income' => 999999,
    ]);

    $response = $this->withToken($this->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_fiscal_year',
                'arguments' => ['year' => 2024],
            ],
        ]);

    $result = json_decode($response->json('result.content.0.text', '{}'), true);

    expect($result['opening']['is_opening_year'])->toBeFalse()
        ->and($result['opening']['source'])->toBeNull()
        ->and($result['opening']['deficits'])->toBe([]);
});

// === ANALYSE DE JUSTIFICATIFS (analyze_document) ===

/**
 * Le nombre d'outils est une promesse publique (README, vitrine, annuaires MCP,
 * server.json). Il a déjà dérivé silencieusement : ce test l'ancre au dossier.
 */
it('garde le nombre d\'outils annoncé en phase avec le serveur', function () {
    $declares = (new ReflectionClass(\App\Mcp\OpenLmnpServer::class))
        ->getDefaultProperties()['tools'];

    $fichiers = glob(app_path('Mcp/Tools/*.php'));

    expect($declares)->toHaveCount(count($fichiers))
        ->and($declares)->toHaveCount(46);

    // La documentation publique annonce le même nombre.
    foreach (['README.md', 'README.en.md'] as $doc) {
        expect(file_get_contents(base_path($doc)))->toContain('46');
    }
});

it('analyse un justificatif via MCP et renvoie des euros sans rien créer', function () {
    config([
        'ai.enabled' => true,
        'ai.api_key' => 'sk-or-v1-factice',
        // Épinglé : l'hôte de test n'est pas openrouter.ai, la détection
        // automatique le classerait donc en serveur local.
        'ai.provider' => 'openrouter',
        'ai.base_url' => 'https://openrouter.test/api/v1',
        'ai.models.documents' => 'modele/test',
        'ai.models.documents_fallback' => '',
        'ai.pdf_text_fallback' => 'never',
        'demo.email' => 'demo@openlmnp.fr',
    ]);

    Http::fake(['https://openrouter.test/api/v1/chat/completions' => Http::response([
        'id' => 'gen-1',
        'model' => 'modele/test',
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => file_get_contents(base_path('tests/Fixtures/ai/facture-energie.json')),
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'cost' => 0.0012],
    ])]);

    $response = $this->withToken($this->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'analyze_document',
                'arguments' => [
                    'property_id' => $this->property->id,
                    'file_base64' => base64_encode("%PDF-1.4\n%\xE2\xE3\xCF\xD3\ntrailer\n<<>>\n%%EOF"),
                    'filename' => 'facture.pdf',
                ],
            ],
        ]);

    $response->assertOk();
    expect($response->json('result.isError'))->not->toBeTrue();

    $result = json_decode($response->json('result.content.0.text', '{}'), true);

    expect($result['document_type'])->toBe('expense_invoice')
        ->and($result['target_entity'])->toBe('expense')
        // Convention MCP : des EUROS, et une clé qui le dit.
        ->and($result['proposal']['amount_eur'])->toBe('143.20')
        ->and($result['proposal'])->not->toHaveKey('amount')
        ->and($result['proposal']['category'])->toBe('energy')
        // Outil non destructif : rien n'est créé.
        ->and(\App\Models\Expense::withoutGlobalScopes()->count())->toBe(0)
        ->and(\App\Models\Document::count())->toBe(0);

    // Journalisé des deux côtés : audit MCP et journal d'usage IA.
    expect(McpAuditLog::where('tool_name', 'analyze_document')->count())->toBe(1)
        ->and(\App\Models\AiUsageLog::withoutGlobalScopes()->where('feature', 'documents')->count())->toBe(1);
});

it('refuse d\'analyser un justificatif pour le bien d\'un autre utilisateur', function () {
    config(['ai.enabled' => true, 'ai.api_key' => 'sk-or-v1-factice', 'demo.email' => 'demo@openlmnp.fr']);

    $autre = User::factory()->create();
    $bienDeLAutre = Property::forceCreate([
        'user_id' => $autre->id,
        'name' => 'Bien de B',
        'address' => '9 rue B',
        'city' => 'Nice',
        'postal_code' => '06000',
        'type' => 'apartment',
        'total_area' => 50,
        'rented_area' => 50,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 1200000,
        'land_percentage' => 20,
        'rental_start_date' => '2022-01-01',
        'rental_type' => 'seasonal',
        'tva_regime' => 'exempt',
        'is_primary_residence' => false,
    ]);

    Http::fake();

    $response = $this->withToken($this->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'analyze_document',
                'arguments' => [
                    'property_id' => $bienDeLAutre->id,
                    'file_base64' => base64_encode('%PDF-1.4'),
                ],
            ],
        ]);

    // Le bien d'autrui est INTROUVABLE (BelongsToUserScope) : findOrFail
    // remonte en 404 HTTP, comme pour tous les outils du serveur. L'essentiel
    // est qu'aucun document ne parte chez le fournisseur.
    $response->assertNotFound();
    Http::assertNothingSent();
});

it('n\'expose pas analyze_document à la démo publique', function () {
    // L'allowlist démo est explicite : tout outil non listé est bloqué. Un
    // compte à identifiants publics ne doit pas pouvoir dépenser nos jetons.
    expect(config('mcp.demo.tools'))->not->toContain('analyze_document');
});


it('refuse un fichier dont le contenu ne correspond pas à son extension', function () {
    // Une extension est DÉCLARATIVE. Sans contrôle du contenu, un HTML piégé
    // nommé « facture.pdf » finirait stocké puis servi depuis notre origine.
    $expense = Expense::create([
        'property_id' => $this->property->id,
        'expense_date' => '2026-02-04',
        'amount' => 14320,
        'tva_rate' => 2000,
        'category' => 'energy',
        'description' => 'EDF',
        'is_dedicated' => true,
        'recurring_type' => 'once',
    ]);

    $response = $this->withToken($this->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'attach_document',
                'arguments' => [
                    'type' => 'expense',
                    'record_id' => $expense->id,
                    'label' => 'Facture piégée',
                    'file_base64' => base64_encode('<html><body><script>alert(1)</script></body></html>'),
                    'filename' => 'facture.pdf',
                ],
            ],
        ]);

    $response->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('ne correspond pas à son extension')
        ->and(\App\Models\Document::count())->toBe(0);
});

it('accepte un vrai PDF via attach_document', function () {
    $expense = Expense::create([
        'property_id' => $this->property->id,
        'expense_date' => '2026-02-04',
        'amount' => 14320,
        'tva_rate' => 2000,
        'category' => 'energy',
        'description' => 'EDF',
        'is_dedicated' => true,
        'recurring_type' => 'once',
    ]);

    $response = $this->withToken($this->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'attach_document',
                'arguments' => [
                    'type' => 'expense',
                    'record_id' => $expense->id,
                    'label' => 'Facture EDF',
                    'file_base64' => base64_encode("%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF"),
                    'filename' => 'facture.pdf',
                ],
            ],
        ]);

    $response->assertOk();
    expect($response->json('result.isError'))->not->toBeTrue()
        ->and(\App\Models\Document::count())->toBe(1);
});
