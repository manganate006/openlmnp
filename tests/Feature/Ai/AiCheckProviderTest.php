<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * `ai:check-provider` — l'outil de la règle « valider par un appel réel ».
 *
 * Ces tests vérifient la mécanique de la commande, pas la conformité d'un
 * fournisseur : cette dernière ne se simule pas, c'est tout le sujet. Ce qu'on
 * verrouille ici, c'est qu'elle refuse proprement quand il faut, qu'elle
 * n'émet rien quand elle a annoncé ne rien émettre, qu'elle rend un code
 * d'échec exploitable, et qu'elle ne recrache jamais la clé.
 *
 * ⚠️ Un seul `Http::fake()` par test : deux s'empilent et la séquence s'épuise.
 */
const CLE_SONDE = 'sk-or-v1-cle-de-sonde-ne-doit-jamais-fuiter';
const BASE_SONDE = 'https://openrouter.test/api/v1';

beforeEach(function () {
    config([
        'ai.enabled' => true,
        'ai.api_key' => CLE_SONDE,
        'ai.provider' => 'openrouter',
        'ai.base_url' => BASE_SONDE,
        'ai.models.documents' => 'modele/test',
        'ai.models.chat' => 'modele/test',
        'ai.model_prices' => [],
        'demo.email' => 'demo@openlmnp.fr',
    ]);

    $this->user = User::factory()->create(['is_demo' => false]);
});

/** Réponse d'introspection de clé, telle qu'OpenRouter la rend. */
function reponseCle(): array
{
    return ['data' => ['label' => 'sonde', 'usage' => 0.1, 'limit_remaining' => 4.82]];
}

/** Complétion JSON exploitable. */
function reponseJson(array $contenu): array
{
    return [
        'id' => 'gen-sonde',
        'model' => 'modele/test',
        'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode($contenu)], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'cost' => 0.0012],
    ];
}

function fluxDeSonde(string $texte): string
{
    return 'data: ' . json_encode(['choices' => [['delta' => ['content' => $texte]]]]) . "\n\n"
        . 'data: ' . json_encode(['usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'cost' => 0.0001]]) . "\n\n"
        . "data: [DONE]\n\n";
}

/** Les trois sondes facturées d'affilée, plus l'introspection de clé. */
function faireRepondreLesSondes(): void
{
    Http::fake([
        BASE_SONDE . '/auth/key' => Http::response(reponseCle()),
        BASE_SONDE . '/chat/completions' => Http::sequence()
            ->push(fluxDeSonde('OK'))
            ->push(reponseJson(['montant' => '143,20', 'date' => '2026-02-04']))
            ->push(reponseJson(['montant' => '143,20'])),
    ]);
}

// =============================================================================
// Refus
// =============================================================================

it('refuse de s\'exécuter quand l\'IA est désactivée', function () {
    config(['ai.enabled' => false]);

    $this->artisan('ai:check-provider')
        ->expectsOutputToContain('AI_ENABLED')
        ->assertFailed();

    Http::assertNothingSent();
});

it('refuse un fournisseur inconnu, et dit lesquels existent', function () {
    $this->artisan('ai:check-provider', ['--provider' => ['openrouteur']])
        ->expectsOutputToContain('Fournisseur inconnu')
        ->expectsOutputToContain('openrouter')
        ->assertFailed();

    Http::assertNothingSent();
});

it('refuse proprement quand aucune clé ne se résout, sans rien envoyer', function () {
    // Cas d'une instance sans clé d'instance et d'un compte qui n'en a pas :
    // la commande doit le dire, pas partir en erreur.
    config(['ai.api_key' => null, 'ai.allow_user_keys' => true]);

    $this->artisan('ai:check-provider', ['--free-only' => true])
        ->expectsOutputToContain('Renseignez votre clé')
        ->assertFailed();

    Http::assertNothingSent();
});

it('exige un compte réel plutôt que de deviner', function () {
    User::query()->delete();

    $this->artisan('ai:check-provider', ['--free-only' => true])
        ->expectsOutputToContain('--user')
        ->assertFailed();
});

// =============================================================================
// Contrôles gratuits
// =============================================================================

it('n\'émet aucun appel facturé en mode gratuit', function () {
    Http::fake([BASE_SONDE . '/auth/key' => Http::response(reponseCle())]);

    $this->artisan('ai:check-provider', ['--free-only' => true])
        ->expectsOutputToContain('Clé acceptée')
        ->assertSuccessful();

    // Une seule requête : l'introspection de clé, qui est un GET gratuit.
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat/completions'));
});

it('annonce le nombre d\'appels facturés avant de les émettre', function () {
    Http::fake([BASE_SONDE . '/auth/key' => Http::response(reponseCle())]);

    // Le volume est annoncé AVANT la question, et un refus n'est pas un échec :
    // on n'a simplement rien fait.
    $this->artisan('ai:check-provider')
        ->expectsOutputToContain('appels facturés')
        ->expectsConfirmation('Lancer ?', 'no')
        ->assertSuccessful();

    Http::assertNothingSent();
});

// =============================================================================
// Parcours complet
// =============================================================================

it('valide un fournisseur dont les quatre sondes passent', function () {
    faireRepondreLesSondes();

    $this->artisan('ai:check-provider')
        ->expectsConfirmation('Lancer ?', 'yes')
        ->expectsOutputToContain('Clé acceptée')
        ->expectsOutputToContain('Assistant')
        ->expectsOutputToContain('Schéma honoré')
        ->expectsOutputToContain('Pièce jointe')
        ->expectsOutputToContain('peut servir')
        ->assertSuccessful();
});

it('déclare le fournisseur inapte quand le schéma n\'est pas honoré', function () {
    // Le cas qui justifie la commande : la clé marche, l'assistant répond, et
    // pourtant l'extraction de justificatifs est impossible.
    Http::fake([
        BASE_SONDE . '/auth/key' => Http::response(reponseCle()),
        BASE_SONDE . '/chat/completions' => Http::sequence()
            ->push(fluxDeSonde('OK'))
            ->push(reponseJson(['autre_chose' => 'sans rapport']))
            ->push(reponseJson(['montant' => '143,20'])),
    ]);

    // ⚠️ UNE SEULE attente de sortie ici : deux attentes dont l'une est
    // sous-chaîne de l'autre ne se vérifient pas toutes les deux. On vise donc
    // la ligne de conséquence, qui contient déjà le nom de l'étape.
    $this->artisan('ai:check-provider')
        ->expectsConfirmation('Lancer ?', 'yes')
        ->expectsOutputToContain('Schéma honoré : ce fournisseur ne peut pas servir')
        ->assertFailed();
});

it('signale un montant que la pièce jointe n\'a pas permis de retrouver', function () {
    Http::fake([
        BASE_SONDE . '/auth/key' => Http::response(reponseCle()),
        BASE_SONDE . '/chat/completions' => Http::sequence()
            ->push(fluxDeSonde('OK'))
            ->push(reponseJson(['montant' => '143,20', 'date' => '2026-02-04']))
            ->push(reponseJson(['montant' => '0,00'])),
    ]);

    $this->artisan('ai:check-provider')
        ->expectsConfirmation('Lancer ?', 'yes')
        ->expectsOutputToContain('mal lus')
        ->assertFailed();
});

// =============================================================================
// ⚠️ La clé ne sort jamais
// =============================================================================

it('ne recrache jamais la clé dans sa sortie', function () {
    faireRepondreLesSondes();

    $this->artisan('ai:check-provider')
        ->expectsConfirmation('Lancer ?', 'yes')
        ->doesntExpectOutputToContain(CLE_SONDE)
        ->assertSuccessful();
});

it('restaure la configuration du fournisseur après son passage', function () {
    // La commande écrase `ai.provider` et `ai.base_url` le temps d'une sonde :
    // les laisser modifiées empoisonnerait la suite du processus.
    Http::fake([BASE_SONDE . '/auth/key' => Http::response(reponseCle())]);

    $this->artisan('ai:check-provider', ['--provider' => ['openrouter'], '--free-only' => true])
        ->assertSuccessful();

    expect(config('ai.provider'))->toBe('openrouter')
        ->and(config('ai.base_url'))->toBe(BASE_SONDE)
        ->and(config('ai.api_key'))->toBe(CLE_SONDE);
});
