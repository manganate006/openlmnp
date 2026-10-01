<?php

use App\Console\Commands\PurgeExportsCommand;
use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\McpAuditLog;
use App\Models\Property;
use App\Models\Scopes\UnresolvedUser;
use App\Models\User;
use App\Services\CsvExportService;
use App\Services\FecService;
use App\Services\FiscalYearService;
use App\Services\OpenData\DvfClient;
use App\Services\OpenData\DvfUnavailable;
use App\Support\McpAuditRedactor;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

/**
 * Suite de GHSA-j4gm-g8m8-93x2 : points 9 à 21 du signalement, plus les restes de la
 * relecture. Chaque test rejoue l'attaque ou le défaut décrit.
 */
beforeEach(function () {
    config(['mcp.enabled' => true]);

    $this->user = User::factory()->create(['mcp_enabled' => true]);
    $this->token = $this->user->createToken('test')->plainTextToken;
    $this->property = Property::forceCreate([
        'user_id' => $this->user->id, 'name' => 'Studio', 'address' => '1 rue du Test',
        'city' => 'Lyon', 'postal_code' => '69003', 'type' => 'apartment',
        'total_area' => 45, 'rented_area' => 45, 'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000, 'land_percentage' => 15,
        'rental_start_date' => '2022-03-01', 'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
});

function appelMcp(string $token, string $outil, array $arguments = []): Illuminate\Testing\TestResponse
{
    return test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => $outil, 'arguments' => $arguments],
    ]);
}

function resultatMcp(Illuminate\Testing\TestResponse $response): array
{
    return json_decode($response->json('result.content.0.text', '{}'), true) ?? [];
}

// --- Point 9 : les outils de consultation n'écrivent plus ---------------------------

it('calcule un exercice par MCP sans le créer ni le recalculer', function (string $outil, array $arguments) {
    appelMcp($this->token, $outil, $arguments)->assertOk();

    expect(FiscalYear::withoutGlobalScopes()->where('user_id', $this->user->id)->count())->toBe(0);
})->with([
    'compute_fiscal_year' => ['compute_fiscal_year', ['year' => 2024]],
    'compare_micro_bic' => ['compare_micro_bic', ['year' => 2024]],
    'get_simulation' => ['get_simulation', ['year' => 2024]],
]);

it('rend un exercice clôturé tel quel, sans recalcul ni cascade', function () {
    $clos = FiscalYear::withoutGlobalScopes()->create([
        'user_id' => $this->user->id, 'year' => 2023, 'status' => FiscalYear::STATUS_CLOSED,
        'fiscal_result' => 123456,
    ]);

    $apercu = app(FiscalYearService::class)->preview($this->user, 2023);

    expect($apercu->fiscal_result)->toBe(123456)
        ->and($clos->fresh()->updated_at->equalTo($clos->updated_at))->toBeTrue();
});

// --- Point 10 : limite de débit et taille du journal ---------------------------------

it('limite le débit MCP d\'un compte ordinaire', function () {
    config(['mcp.rate_limit' => 2]);
    RateLimiter::clear('mcp-user:' . $this->user->id);

    appelMcp($this->token, 'list_properties')->assertOk();
    appelMcp($this->token, 'list_properties')->assertOk();
    appelMcp($this->token, 'list_properties')->assertStatus(429);
});

it('ne journalise ni un contenu de fichier ni des arguments démesurés', function () {
    appelMcp($this->token, 'import_airbnb_csv', [
        'property_id' => $this->property->id,
        'csv_base64' => base64_encode(str_repeat("Date;Voyageur\n01/01/2024;Jean Dupont\n", 2000)),
        'preview' => true,
    ]);

    $journal = McpAuditLog::latest('id')->first();

    expect($journal->parameters['csv_base64'])->toStartWith('[masqué')
        ->and(strlen(json_encode($journal->parameters)))->toBeLessThan(McpAuditRedactor::MAX_TOTAL_BYTES);
});

it('masque le jeton d\'une URL signée dans le journal', function () {
    expect(McpAuditRedactor::redact(['file_url' => 'https://exemple.test/f.pdf?signature=secret']))
        ->toBe(['file_url' => 'https://exemple.test/f.pdf?[masqué]']);
});

// --- Point 11 : serveur MCP local sans compte désigné --------------------------------

it('ne rend aucun bien quand le serveur MCP local n\'a pas de compte', function () {
    auth()->logout();
    UnresolvedUser::mark();

    expect(Property::count())->toBe(0)
        ->and(Expense::count())->toBe(0);
});

// --- Point 13 : purge du journal ------------------------------------------------------

it('purge le journal MCP au-delà de la durée de conservation', function () {
    config(['mcp.audit_retention_days' => 90]);
    McpAuditLog::create(['user_id' => $this->user->id, 'tool_name' => 'vieux', 'result_status' => 'success', 'created_at' => now()->subDays(91)]);
    McpAuditLog::create(['user_id' => $this->user->id, 'tool_name' => 'recent', 'result_status' => 'success', 'created_at' => now()->subDays(10)]);

    $this->artisan('model:prune', ['--model' => [McpAuditLog::class]])->assertSuccessful();

    expect(McpAuditLog::pluck('tool_name')->all())->toBe(['recent']);
});

// --- Point 14 : export_documents rend un lien qui fonctionne --------------------------

it('rend un lien signé utilisable et range l\'archive chez l\'utilisateur', function () {
    Storage::fake('local');
    $expense = Expense::create([
        'property_id' => $this->property->id, 'category' => 'insurance',
        'description' => 'Assurance', 'amount' => 25000, 'expense_date' => '2024-05-10',
    ]);
    $chemin = "documents/{$this->user->id}/pieces-comptables/avis.pdf";
    Storage::disk('local')->put($chemin, '%PDF-1.4');
    $expense->documents()->create(['label' => 'Avis', 'file_path' => $chemin, 'document_date' => '2024-05-10']);

    $resultat = resultatMcp(appelMcp($this->token, 'export_documents', ['year' => 2024]));

    expect($resultat['download_url'])->toContain('signature=')->not->toContain('signature=mcp')
        ->and(Storage::disk('local')->files("documents/{$this->user->id}/exports"))->toHaveCount(1);

    $this->app['auth']->forgetGuards();
    $this->actingAs($this->user)->get($resultat['download_url'])->assertOk();
});

it('purge les archives d\'export de plus de 24 heures', function () {
    Storage::fake('local');
    $vieille = "documents/{$this->user->id}/exports/vieille.zip";
    Storage::disk('local')->put($vieille, 'zip');
    touch(Storage::disk('local')->path($vieille), now()->subDays(2)->getTimestamp());
    Storage::disk('local')->put("documents/{$this->user->id}/exports/recente.zip", 'zip');

    $this->artisan(PurgeExportsCommand::class)->assertSuccessful();

    expect(Storage::disk('local')->files("documents/{$this->user->id}/exports"))
        ->toBe(["documents/{$this->user->id}/exports/recente.zip"]);
});

// --- Points 15 et 16 : import de document par URL ou contenu --------------------------

it('refuse une URL qui vise une plage interne oubliée par le filtre', function (string $url) {
    $expense = Expense::create([
        'property_id' => $this->property->id, 'category' => 'insurance',
        'description' => 'Assurance', 'amount' => 25000, 'expense_date' => '2024-05-10',
    ]);

    $response = appelMcp($this->token, 'attach_document', [
        'type' => 'expense', 'record_id' => $expense->id, 'label' => 'Avis', 'file_url' => $url,
    ]);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('anti-SSRF');
})->with([
    'CGNAT' => 'http://100.64.1.1/f.pdf',
    'banc de test' => 'http://198.18.0.1/f.pdf',
    'IETF' => 'http://192.0.0.8/f.pdf',
    'multicast' => 'http://224.0.0.1/f.pdf',
    'NAT64' => 'http://[64:ff9b::a00:1]/f.pdf',
    'IPv4 mappée' => 'http://[::ffff:10.0.0.1]/f.pdf',
]);

it('refuse un fichier HTML déguisé en PDF', function () {
    $expense = Expense::create([
        'property_id' => $this->property->id, 'category' => 'insurance',
        'description' => 'Assurance', 'amount' => 25000, 'expense_date' => '2024-05-10',
    ]);

    $response = appelMcp($this->token, 'attach_document', [
        'type' => 'expense', 'record_id' => $expense->id, 'label' => 'Facture',
        'file_base64' => base64_encode('<html><script>alert(1)</script></html>'),
        'filename' => 'facture.pdf',
    ]);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($expense->documents()->count())->toBe(0);
});

// --- Point 17 : code INSEE ------------------------------------------------------------

it('refuse un code INSEE qui n\'en est pas un', function (string $insee) {
    expect(fn () => app(DvfClient::class)->samples($insee, 2024))->toThrow(DvfUnavailable::class);
})->with(['../35238', '3523', '35238/..', 'ABCDE']);

// --- Point 21 et restes de la relecture -----------------------------------------------

it('répond 401 et non 500 à un appel MCP anonyme sans Accept JSON', function () {
    config(['mcp.demo.enabled' => false]);

    $this->call('POST', '/mcp', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertUnauthorized();
});

it('garde les colonnes du FEC quand un libellé contient une tabulation ou un saut de ligne', function () {
    expect(FecService::field("Facture\tplombier\r\nurgence"))->toBe('Facture plombier urgence');
});

it('neutralise une formule précédée d\'espaces dans un export CSV', function () {
    expect(CsvExportService::cell('  =1+1'))->toBe("'  =1+1")
        ->and(CsvExportService::cell(' Assurance'))->toBe(' Assurance');
});

it('n\'expose plus le chemin des fichiers d\'un exercice', function () {
    FiscalYear::withoutGlobalScopes()->create([
        'user_id' => $this->user->id, 'year' => 2024, 'status' => FiscalYear::STATUS_DRAFT,
        'pdf_path' => 'tax-returns/2024/liasse_fiscale_2024.pdf',
    ]);

    $resultat = resultatMcp(appelMcp($this->token, 'get_fiscal_year', ['year' => 2024]));

    expect($resultat)->not->toHaveKey('pdf_path')->not->toHaveKey('fec_path')
        ->and(json_encode($resultat))->not->toContain('tax-returns');
});

it('pose HSTS et un cookie de session Secure sur une requête HTTPS', function () {
    config(['session.secure' => null]);

    $response = $this->get('https://localhost/login');

    expect($response->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000')
        ->and(config('session.secure'))->toBeTrue();
});

it('ne pose pas HSTS sur une requête HTTP', function () {
    expect($this->get('http://localhost/login')->headers->has('Strict-Transport-Security'))->toBeFalse();
});
