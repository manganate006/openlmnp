<?php

use App\Livewire\AiAssistant;
use App\Models\AiUsageLog;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

/**
 * Option « accès aux données » : sans elle, aucune donnée métier ne quitte l'instance ;
 * avec elle, l'assistant ne voit que le compte connecté.
 */
const BASE_ISOLATION = 'https://openrouter.test/api/v1';

beforeEach(function () {
    Sleep::fake();

    config([
        'ai.enabled' => true,
        'ai.api_key' => 'sk-or-v1-cle-factice-isolation',
        // Épinglé : l'hôte de test n'est pas openrouter.ai, la détection
        // automatique le classerait donc en serveur local.
        'ai.provider' => 'openrouter',
        'ai.base_url' => BASE_ISOLATION,
        'ai.models.chat' => 'google/gemini-3.7-flash',
        'ai.chat_daily_quota' => 0,
        'ai.chat_tool_calls_max' => 4,
        'ai.model_prices' => [],
        'demo.email' => 'demo@openlmnp.fr',
    ]);
});

function bienPour(User $user, string $name): Property
{
    return Property::forceCreate([
        'user_id' => $user->id,
        'name' => $name,
        'address' => '1 rue Test',
        'city' => 'Lyon',
        'postal_code' => '69000',
        'type' => 'apartment',
        'total_area' => 50,
        'rented_area' => 50,
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 30000000,
        'notary_fees' => 1500000,
        'land_percentage' => 15,
        'rental_start_date' => '2022-01-01',
        'rental_type' => 'seasonal',
        'tva_regime' => 'exempt',
    ]);
}

/** Un flux qui demande un outil, puis un flux qui rédige la réponse. */
function fluxAvecOutil(string $tool, array $arguments, string $reponse): void
{
    $appel = 'data: '.json_encode(['choices' => [['delta' => ['tool_calls' => [[
        'index' => 0, 'id' => 'call_1',
        'function' => ['name' => $tool, 'arguments' => json_encode($arguments)],
    ]]]]]])."\n\n"
        .'data: '.json_encode(['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]])."\n\n"
        ."data: [DONE]\n\n";

    $texte = 'data: '.json_encode(['choices' => [['delta' => ['content' => $reponse]]]])."\n\n"
        .'data: '.json_encode([
            'choices' => [['delta' => [], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 60],
        ])."\n\n"
        ."data: [DONE]\n\n";

    Http::fake([BASE_ISOLATION.'/chat/completions' => Http::sequence()
        ->push($appel)
        ->push($texte),
    ]);
}

// =============================================================================
// Sans l'option
// =============================================================================

it('n\'interroge aucune table métier quand l\'option est désactivée', function () {
    $user = User::factory()->create(['ai_data_access' => false]);
    bienPour($user, 'Studio Lyon');

    Http::fake([BASE_ISOLATION.'/chat/completions' => Http::response(
        'data: '.json_encode(['choices' => [['delta' => ['content' => 'Réponse générale.']]]])."\n\n"
        .'data: '.json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']]])."\n\n"
        ."data: [DONE]\n\n"
    )]);

    $tables = [];
    DB::listen(function ($query) use (&$tables) {
        foreach (['properties', 'expenses', 'incomes', 'loans', 'furniture', 'fiscal_years', 'property_works'] as $table) {
            if (str_contains($query->sql, "\"{$table}\"")) {
                $tables[] = $table;
            }
        }
    });

    Livewire::actingAs($user)
        ->test(AiAssistant::class)
        ->set('input', 'Combien de charges ai-je saisies en 2025 ?')
        ->call('send');

    expect($tables)->toBe([]);
});

it('n\'envoie aucun outil au modèle quand l\'option est désactivée', function () {
    $user = User::factory()->create(['ai_data_access' => false]);

    Http::fake([BASE_ISOLATION.'/chat/completions' => Http::response(
        'data: '.json_encode(['choices' => [['delta' => ['content' => 'Réponse.']]]])."\n\n"
        ."data: [DONE]\n\n"
    )]);

    Livewire::actingAs($user)
        ->test(AiAssistant::class)
        ->set('input', 'Mes exercices sont-ils à jour ?')
        ->call('send');

    Http::assertSent(fn ($request) => ! isset($request['tools']));
});

it('annonce au modèle qu\'il ne connaît rien du compte', function () {
    $user = User::factory()->create(['ai_data_access' => false]);

    Http::fake([BASE_ISOLATION.'/chat/completions' => Http::response(
        'data: '.json_encode(['choices' => [['delta' => ['content' => 'Réponse.']]]])."\n\n"
        ."data: [DONE]\n\n"
    )]);

    Livewire::actingAs($user)
        ->test(AiAssistant::class)
        ->set('input', 'Une question ?')
        ->call('send');

    Http::assertSent(fn ($request) => collect($request['messages'])->contains(
        fn ($m) => $m['role'] === 'system' && str_contains($m['content'], "Aucun outil n'est disponible")
    ));
});

// =============================================================================
// Avec l'option
// =============================================================================

it('consulte les données du compte et affiche ce qui a été lu', function () {
    $user = User::factory()->create(['ai_data_access' => true]);
    bienPour($user, 'Studio Lyon');

    fluxAvecOutil('list_properties', [], 'Vous avez un bien : Studio Lyon.');

    Livewire::actingAs($user)
        ->test(AiAssistant::class)
        ->set('input', 'Quels sont mes biens ?')
        ->call('send')
        ->assertSee('Vous avez un bien')
        ->assertSee('Consulté : vos biens');
});

it('transmet les définitions d\'outils au modèle', function () {
    $user = User::factory()->create(['ai_data_access' => true]);

    fluxAvecOutil('list_properties', [], 'Aucun bien enregistré.');

    Livewire::actingAs($user)
        ->test(AiAssistant::class)
        ->set('input', 'Quels sont mes biens ?')
        ->call('send');

    Http::assertSent(function ($request) {
        if (! isset($request['tools'])) {
            return false;
        }

        $noms = array_column(array_column($request['tools'], 'function'), 'name');

        return in_array('list_properties', $noms, true)
            && ! in_array('create_expense', $noms, true)
            && count($noms) === 16;
    });
});

it('ne renvoie que les données du compte connecté', function () {
    $alice = User::factory()->create(['ai_data_access' => true]);
    $bob = User::factory()->create(['ai_data_access' => true]);

    bienPour($alice, 'Studio Alice');
    bienPour($bob, 'Villa Bob');

    fluxAvecOutil('list_properties', [], 'Voici vos biens.');

    Livewire::actingAs($alice)
        ->test(AiAssistant::class)
        ->set('input', 'Quels sont mes biens ?')
        ->call('send');

    // Le résultat d'outil part dans la deuxième requête : il ne doit contenir que
    // le bien d'Alice.
    Http::assertSent(function ($request) {
        $outil = collect($request['messages'])->firstWhere('role', 'tool');

        if ($outil === null) {
            return false;
        }

        return str_contains($outil['content'], 'Studio Alice')
            && ! str_contains($outil['content'], 'Villa Bob');
    });
});

it('refuse un identifiant appartenant à un autre compte', function () {
    $alice = User::factory()->create(['ai_data_access' => true]);
    $bob = User::factory()->create(['ai_data_access' => true]);

    $bienDeBob = bienPour($bob, 'Villa Bob');

    fluxAvecOutil('get_property', ['property_id' => $bienDeBob->id], 'Je ne trouve pas ce bien.');

    Livewire::actingAs($alice)
        ->test(AiAssistant::class)
        ->set('input', 'Montre-moi le bien numéro '.$bienDeBob->id)
        ->call('send');

    Http::assertSent(function ($request) {
        $outil = collect($request['messages'])->firstWhere('role', 'tool');

        return $outil === null || (
            ! str_contains($outil['content'], 'Villa Bob')
            && str_contains($outil['content'], 'introuvable')
        );
    });
});

// =============================================================================
// Journal et quota
// =============================================================================

it('journalise chaque exécution d\'outil sans son résultat', function () {
    $user = User::factory()->create(['ai_data_access' => true]);
    bienPour($user, 'Studio Lyon');

    fluxAvecOutil('list_properties', [], 'Vous avez un bien.');

    Livewire::actingAs($user)
        ->test(AiAssistant::class)
        ->set('input', 'Quels sont mes biens ?')
        ->call('send');

    $lignes = AiUsageLog::where('feature', 'chat_tool')->get();
    $outil = $lignes->firstWhere('meta.tool', 'list_properties');

    expect($outil)->not->toBeNull()
        ->and($outil->meta)->toBe(['tool' => 'list_properties'])
        ->and(json_encode($lignes->pluck('meta')))->not->toContain('Studio Lyon');
});

it('ne compte qu\'une unité de quota par question, malgré les tours d\'outils', function () {
    $user = User::factory()->create(['ai_data_access' => true]);
    bienPour($user, 'Studio Lyon');

    fluxAvecOutil('list_properties', [], 'Vous avez un bien.');

    Livewire::actingAs($user)
        ->test(AiAssistant::class)
        ->set('input', 'Quels sont mes biens ?')
        ->call('send');

    // Deux appels au modèle, mais un seul porte la fonctionnalité soumise au quota.
    expect(AiUsageLog::where('feature', 'chat')->where('status', 'ok')->count())->toBe(1)
        ->and(AiUsageLog::where('feature', 'chat_tool')->where('status', 'ok')->count())->toBe(2);
});
