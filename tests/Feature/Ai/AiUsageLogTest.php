<?php

use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\AiKeyInvalid;
use Illuminate\Support\Facades\Http;

const CLE_JOURNAL = 'sk-or-v1-journal-ne-doit-pas-fuiter';
const BASE_JOURNAL = 'https://openrouter.test/api/v1';

beforeEach(function () {
    config([
        'ai.enabled' => true,
        'ai.api_key' => CLE_JOURNAL,
        // Épinglé : l'hôte de test n'est pas openrouter.ai, la détection
        // automatique le classerait donc en serveur local.
        'ai.provider' => 'openrouter',
        'ai.base_url' => BASE_JOURNAL,
        'ai.models.documents' => 'google/gemini-3.7-flash',
        'ai.usage_retention_days' => 90,
        'ai.model_prices' => [],
        'demo.email' => 'demo@openlmnp.fr',
    ]);

    $this->user = User::factory()->create();
});

function repondreOk(): void
{
    Http::fake([BASE_JOURNAL . '/chat/completions' => Http::response([
        'id' => 'gen-1',
        'model' => 'google/gemini-3.7-flash',
        'choices' => [['message' => ['content' => '{"montant":"143.20"}'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 4000, 'completion_tokens' => 500, 'cost' => 0.0024],
    ])]);
}

function analyser(User $user, array $options = []): void
{
    AiClient::for($user)->completeJson(
        'documents',
        [['role' => 'user', 'content' => 'facture']],
        ['name' => 'facture', 'schema' => ['type' => 'object']],
        options: $options,
    );
}

// === Une ligne par appel ===

it('écrit une ligne complète par appel réussi', function () {
    repondreOk();

    analyser($this->user);

    $log = AiUsageLog::withoutGlobalScopes()->sole();

    expect($log->user_id)->toBe($this->user->id)
        ->and($log->feature)->toBe('documents')
        ->and($log->model)->toBe('google/gemini-3.7-flash')
        ->and($log->key_source)->toBe('instance')
        ->and($log->status)->toBe('ok')
        ->and($log->error_code)->toBeNull()
        ->and($log->prompt_tokens)->toBe(4000)
        ->and($log->completion_tokens)->toBe(500)
        ->and($log->cost_usd)->toBe('0.002400')
        ->and($log->duration_ms)->toBeGreaterThanOrEqual(0);
});

it('journalise aussi les échecs, avec la classe d\'erreur mais sans message brut', function () {
    Http::fake([BASE_JOURNAL . '/chat/completions' => Http::response([
        'error' => ['message' => 'clé ' . CLE_JOURNAL . ' invalide'],
    ], 401)]);

    expect(fn () => analyser($this->user))->toThrow(AiKeyInvalid::class);

    $log = AiUsageLog::withoutGlobalScopes()->sole();

    expect($log->status)->toBe('error')
        ->and($log->error_code)->toBe('AiKeyInvalid')
        ->and(json_encode($log->toArray()))->not->toContain(CLE_JOURNAL)
        ->and(json_encode($log->toArray()))->not->toContain('invalide');
});

it('écrit une ligne par appel logique — une cascade en produit deux', function () {
    repondreOk();

    analyser($this->user);                                    // modèle principal
    analyser($this->user, ['meta' => ['fallback' => true]]);  // cascade

    $logs = AiUsageLog::withoutGlobalScopes()->orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->meta)->toBeNull()
        ->and($logs[1]->meta)->toBe(['fallback' => true]);
});

// === ⚠️ Redaction ===

it('ne stocke ni contenu, ni base64, ni clé dans meta', function () {
    repondreOk();

    analyser($this->user, ['meta' => [
        'document_type' => 'expense_invoice',
        'confidence_bucket' => 'high',
        'fields_corrected' => 2,
    ]]);

    $log = AiUsageLog::withoutGlobalScopes()->sole();
    $serialise = json_encode($log->toArray());

    expect($log->meta)->toBe([
        'document_type' => 'expense_invoice',
        'confidence_bucket' => 'high',
        'fields_corrected' => 2,
    ])
        ->and($serialise)->not->toContain(CLE_JOURNAL)
        ->and($serialise)->not->toContain('143.20')   // aucune valeur extraite
        ->and($serialise)->not->toContain('base64');
});

it('ne conserve aucune trace du document envoyé', function () {
    repondreOk();

    $base64 = base64_encode(str_repeat('CONTENU-CONFIDENTIEL', 100));

    AiClient::for($this->user)->completeJson(
        'documents',
        [['role' => 'user', 'content' => [AiClient::filePart('facture.pdf', $base64)]]],
        ['name' => 'facture', 'schema' => ['type' => 'object']],
    );

    $serialise = json_encode(AiUsageLog::withoutGlobalScopes()->sole()->toArray());

    expect($serialise)->not->toContain('CONTENU-CONFIDENTIEL')
        ->and($serialise)->not->toContain(substr($base64, 0, 40));
});

// === Isolation multi-utilisateurs ===

it('scope le journal à l\'utilisateur authentifié', function () {
    repondreOk();

    $autre = User::factory()->create();
    analyser($this->user);
    analyser($autre);

    expect(AiUsageLog::withoutGlobalScopes()->count())->toBe(2);

    $this->actingAs($this->user);
    expect(AiUsageLog::count())->toBe(1)
        ->and(AiUsageLog::first()->user_id)->toBe($this->user->id);

    $this->actingAs($autre);
    expect(AiUsageLog::count())->toBe(1)
        ->and(AiUsageLog::first()->user_id)->toBe($autre->id);
});

it('supprime le journal avec le compte', function () {
    repondreOk();
    analyser($this->user);

    $this->user->delete();

    expect(AiUsageLog::withoutGlobalScopes()->count())->toBe(0);
});

// === Purge de rétention ===

it('purge les entrées au-delà de la rétention configurée', function () {
    config(['ai.usage_retention_days' => 30]);

    AiUsageLog::create([
        'user_id' => $this->user->id, 'feature' => 'documents', 'status' => 'ok',
        'created_at' => now()->subDays(31),
    ]);
    AiUsageLog::create([
        'user_id' => $this->user->id, 'feature' => 'documents', 'status' => 'ok',
        'created_at' => now()->subDays(29),
    ]);

    $this->artisan('openlmnp:ai-usage-cleanup')->assertSuccessful();

    expect(AiUsageLog::withoutGlobalScopes()->count())->toBe(1);
});

it('purge tous les comptes, pas seulement celui de la session', function () {
    config(['ai.usage_retention_days' => 30]);

    foreach ([$this->user, User::factory()->create()] as $user) {
        AiUsageLog::create([
            'user_id' => $user->id, 'feature' => 'documents', 'status' => 'ok',
            'created_at' => now()->subDays(60),
        ]);
    }

    // Avec une session active, le global scope limiterait une requête naïve au
    // seul compte connecté : la commande doit l'ignorer explicitement.
    $this->actingAs($this->user);
    $this->artisan('openlmnp:ai-usage-cleanup')->assertSuccessful();

    expect(AiUsageLog::withoutGlobalScopes()->count())->toBe(0);
});

// === Fabriques : refus de quota et refus applicatifs ===

it('journalise un refus de quota sans appel réseau', function () {
    Http::fake();

    $log = AiUsageLog::recordQuota($this->user, 'documents', ['document_type' => 'expense_invoice']);

    expect($log->status)->toBe('quota')
        ->and($log->feature)->toBe('documents')
        ->and($log->model)->toBe('google/gemini-3.7-flash')
        ->and($log->key_source)->toBe('instance')
        ->and($log->prompt_tokens)->toBe(0)
        ->and($log->cost_usd)->toBeNull()
        ->and($log->meta)->toBe(['document_type' => 'expense_invoice'])
        ->and(json_encode($log->toArray()))->not->toContain(CLE_JOURNAL);

    Http::assertNothingSent();
});

it('journalise un refus applicatif de l\'assistant', function () {
    config(['ai.models.chat' => 'google/gemini-3.7-flash']);

    $log = AiUsageLog::recordRefused($this->user, 'chat', ['guard' => 'output']);

    expect($log->status)->toBe('refused')
        ->and($log->feature)->toBe('chat')
        ->and($log->model)->toBe('google/gemini-3.7-flash')
        ->and($log->meta)->toBe(['guard' => 'output']);
});

it('journalise aussi un accès indisponible', function () {
    config(['ai.enabled' => false]);

    expect(fn () => analyser($this->user))->toThrow(App\Services\Ai\Exceptions\AiUnavailable::class);

    $log = AiUsageLog::withoutGlobalScopes()->sole();

    expect($log->status)->toBe('error')
        ->and($log->error_code)->toBe('AiUnavailable')
        ->and($log->key_source)->toBeNull();
});

it('ne purge rien quand la rétention est illimitée', function () {
    config(['ai.usage_retention_days' => 0]);

    AiUsageLog::create([
        'user_id' => $this->user->id, 'feature' => 'documents', 'status' => 'ok',
        'created_at' => now()->subYears(5),
    ]);

    $this->artisan('openlmnp:ai-usage-cleanup')->assertSuccessful();

    expect(AiUsageLog::withoutGlobalScopes()->count())->toBe(1);
});
