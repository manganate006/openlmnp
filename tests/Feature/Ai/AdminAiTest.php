<?php

use App\Filament\Pages\AdminAi;
use App\Models\AiUsageLog;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    config([
        'ai.enabled' => true,
        'ai.api_key' => 'sk-or-v1-factice-pour-les-tests',
        'ai.models.documents' => 'modele/rapide',
        'ai.models.documents_fallback' => 'modele/renforce',
        'ai.usage_retention_days' => 90,
    ]);

    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->membre = User::factory()->create();
});

/** Ligne de journal arbitraire. */
function journaliser(User $user, array $attributs = []): AiUsageLog
{
    return AiUsageLog::create(array_merge([
        'user_id' => $user->id,
        'feature' => 'documents',
        'model' => 'modele/rapide',
        'key_source' => 'instance',
        'status' => 'ok',
        'prompt_tokens' => 1000,
        'completion_tokens' => 200,
        'cost_usd' => '0.002400',
        'duration_ms' => 1500,
        'created_at' => now(),
    ], $attributs));
}

// =============================================================================
// Accès
// =============================================================================

it('n\'est accessible qu\'aux administrateurs', function () {
    actingAs($this->membre);
    expect(AdminAi::canAccess())->toBeFalse();
    get(AdminAi::getUrl())->assertForbidden();

    actingAs($this->admin);
    expect(AdminAi::canAccess())->toBeTrue();
});

it('disparaît quand l\'IA est désactivée', function () {
    config(['ai.enabled' => false]);
    actingAs($this->admin);

    expect(AdminAi::canAccess())->toBeFalse();
});

// =============================================================================
// Agrégation
// =============================================================================

it('agrège la consommation de TOUS les comptes, pas seulement celle de l\'administrateur', function () {
    // Le journal porte un scope global par utilisateur : sans
    // withoutGlobalScopes(), un administrateur croirait l'instance inactive.
    journaliser($this->membre);
    journaliser($this->membre);
    journaliser($this->admin);

    actingAs($this->admin);

    $stats = Livewire::test(AdminAi::class)->instance()->getStats();

    expect($stats['documents_month'])->toBe(3)
        ->and($stats['active_users'])->toBe(2);
});

it('agrège les compteurs de l\'assistant du mois, tous comptes confondus', function () {
    $ligne = fn (User $user, array $attrs) => AiUsageLog::create($attrs + [
        'user_id' => $user->id,
        'feature' => 'chat',
        'status' => 'ok',
        'model' => 'google/gemini-3.7-flash',
        'created_at' => now(),
    ]);

    $ligne($this->membre, ['cost_usd' => '0.000700', 'meta' => ['feedback' => 'up']]);
    $ligne($this->membre, ['cost_usd' => '0.000300', 'meta' => ['feedback' => 'down', 'reason' => 'inexact']]);
    $ligne($this->admin, ['cost_usd' => '0.000500']);
    $ligne($this->membre, ['status' => 'refused', 'meta' => ['guard' => 'output']]);
    $ligne($this->membre, ['status' => 'quota']);
    $ligne($this->membre, ['status' => 'error', 'error_code' => 'AiTimeout']);
    // Exécution d'outil (compte) et tour de modèle supplémentaire (ne compte pas).
    $ligne($this->membre, ['feature' => 'chat_tool', 'cost_usd' => '0.000100', 'meta' => ['tool' => 'list_properties']]);
    $ligne($this->membre, ['feature' => 'chat_tool', 'cost_usd' => '0.000200', 'meta' => ['round' => 1]]);
    // Le mois dernier ne compte pas.
    $ligne($this->membre, ['created_at' => now()->subMonths(2)]);

    actingAs($this->admin);

    $stats = Livewire::test(AdminAi::class)->instance()->getAssistantStats();

    expect($stats['questions'])->toBe(3)
        ->and($stats['refused'])->toBe(1)
        ->and($stats['refused_share'])->toBe(33)
        ->and($stats['quota_hits'])->toBe(1)
        ->and($stats['errors'])->toBe(1)
        ->and($stats['tool_calls'])->toBe(1)
        ->and($stats['thumbs_up'])->toBe(1)
        ->and($stats['thumbs_down'])->toBe(1)
        ->and($stats['cost_usd'])->toBe('0.001800');
});

it('cumule les coûts en bcmath sans dériver', function () {
    // cost_usd est une CHAÎNE (decimal:6) : additionner 300 lignes en float
    // ferait apparaître des centièmes de cent fantômes.
    foreach (range(1, 300) as $ignored) {
        journaliser($this->membre, ['cost_usd' => '0.000100']);
    }

    actingAs($this->admin);

    $stats = Livewire::test(AdminAi::class)->instance()->getStats();

    expect(bccomp($stats['cost_month'], '0.030000', 6))->toBe(0);
});

it('compte le coût de la cascade dans le coût par document, jamais dans le volume', function () {
    // Un document analysé = 1 appel « documents » + éventuellement 1 appel
    // automatique. Le coût par document doit refléter les DEUX.
    journaliser($this->membre, ['cost_usd' => '0.002000']);
    journaliser($this->membre, [
        'feature' => 'documents_auto',
        'model' => 'modele/renforce',
        'cost_usd' => '0.010000',
        'meta' => ['fallback' => true],
    ]);

    actingAs($this->admin);

    $stats = Livewire::test(AdminAi::class)->instance()->getStats();

    expect($stats['documents_month'])->toBe(1)
        ->and(bccomp($stats['cost_per_document'], '0.0120', 4))->toBe(0);
});

it('compte les refus pour quota et les refus des garde-fous', function () {
    // Ces deux statuts n'existent que parce qu'une fonctionnalité les écrit
    // elle-même : les oublier masquerait les utilisateurs bloqués.
    AiUsageLog::recordQuota($this->membre, 'documents', ['limit' => 30]);
    AiUsageLog::recordRefused($this->membre, 'chat', ['guard' => 'scope']);
    journaliser($this->membre, ['status' => 'error', 'error_code' => 'AiTimeout']);

    actingAs($this->admin);

    $stats = Livewire::test(AdminAi::class)->instance()->getStats();

    expect($stats['quota_month'])->toBe(1)
        ->and($stats['refused_month'])->toBe(1)
        ->and($stats['errors_month'])->toBe(1);
});

it('regroupe les erreurs par code sans exposer de message brut', function () {
    journaliser($this->membre, ['status' => 'error', 'error_code' => 'AiTimeout']);
    journaliser($this->membre, ['status' => 'error', 'error_code' => 'AiTimeout']);
    journaliser($this->membre, ['status' => 'error', 'error_code' => 'AiKeyInvalid']);

    actingAs($this->admin);

    $erreurs = collect(Livewire::test(AdminAi::class)->instance()->getErrors());

    expect($erreurs)->toHaveCount(2)
        ->and($erreurs->firstWhere('error_code', 'AiTimeout')['total'])->toBe(2);
});

it('classe les comptes par consommation', function () {
    journaliser($this->membre);
    journaliser($this->membre);
    journaliser($this->admin);

    actingAs($this->admin);

    $top = Livewire::test(AdminAi::class)->instance()->getTopUsers();

    expect($top[0]['email'])->toBe($this->membre->email)
        ->and($top[0]['total'])->toBe(2)
        ->and($top[0]['documents'])->toBe(2);
});

// =============================================================================
// Confidentialité et purge
// =============================================================================

it('n\'affiche jamais la clé d\'API', function () {
    journaliser($this->membre);
    actingAs($this->admin);

    Livewire::test(AdminAi::class)
        ->assertOk()
        ->assertDontSee('sk-or-v1')
        // Le service et les modèles, eux, sont utiles au diagnostic.
        ->assertSee('modele/rapide');
});

it('purge les lignes au-delà de la rétention, tous comptes confondus', function () {
    journaliser($this->membre, ['created_at' => now()->subDays(120)]);
    journaliser($this->membre, ['created_at' => now()->subDays(10)]);

    actingAs($this->admin);

    Livewire::test(AdminAi::class)->callAction('purgeOldLogs');

    expect(AiUsageLog::withoutGlobalScopes()->count())->toBe(1);
});

// === Aide contextuelle ===

it('dispose d\'une aide contextuelle dédiée', function () {
    $aide = App\Support\HelpContentRegistry::resolve('filament.admin.pages.admin-ai');

    expect($aide['view'])->toBe('help.admin-ai')
        ->and($aide['title'])->toBe('Intelligence artificielle');

    $html = view('help.admin-ai')->render();

    expect($html)->toContain('Refus des garde-fous')
        ->and($html)->toContain('Refus pour quota atteint')
        ->and($html)->toContain('Purger les anciens journaux')
        ->and($html)->toContain('La clé n\'est jamais affichée');

    expect(App\Support\HelpContentRegistry::suggestions('filament.admin.pages.admin-ai'))
        ->toHaveCount(3)
        ->and(App\Support\HelpContentRegistry::suggestions('filament.admin.pages.admin-ai')[0])
        ->toContain('refus');
});
