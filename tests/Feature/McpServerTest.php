<?php

use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Income;
use App\Models\McpAuditLog;
use App\Models\Property;
use App\Models\User;

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
