<?php

/**
 * Verrous de configuration du socle IA.
 *
 * Ces tests ne vérifient pas un comportement mais des INVARIANTS de
 * configuration qu'il est facile de casser sans s'en apercevoir :
 *   - l'allowlist d'outils de l'assistant doit rester un sous-ensemble strict
 *     de l'allowlist démo (sinon l'assistant pourrait écrire dans les données) ;
 *   - toute variable AI_* doit être propagée par docker-entrypoint.sh (sinon
 *     elle est silencieusement ignorée en production) et documentée.
 */

/** Noms des variables d'environnement lues par config/ai.php. */
function variablesAiDeLaConfig(): array
{
    preg_match_all(
        "/env\(\s*'(AI_[A-Z0-9_]+)'/",
        file_get_contents(base_path('config/ai.php')),
        $matches,
    );

    return array_values(array_unique($matches[1]));
}

/** Contenu de la SEULE boucle de propagation de docker-entrypoint.sh. */
function boucleDePropagation(): string
{
    // Ancrée sur APP_KEY : une autre boucle `for` ajoutée avant ne la masquerait pas.
    preg_match(
        '/for var in (APP_KEY.*?); do/s',
        file_get_contents(base_path('docker-entrypoint.sh')),
        $matches,
    );

    return $matches[1] ?? '';
}

// =============================================================================
// Allowlist d'outils de l'assistant
// =============================================================================

it('verrouille l\'allowlist de l\'assistant aux 16 outils de lecture et de simulation', function () {
    $attendu = [
        'list_properties', 'get_property',
        'list_fiscal_years', 'get_fiscal_year',
        'get_onboarding_status', 'get_dashboard_summary',
        'list_expenses', 'list_incomes',
        'list_loans', 'get_loan',
        'list_furniture', 'list_property_works', 'list_property_components',
        'list_categories',
        'compare_micro_bic', 'get_simulation',
    ];

    $actuel = config('ai.chat_tools');

    sort($attendu);
    sort($actuel);

    expect($actuel)->toBe($attendu)
        ->and($actuel)->toHaveCount(16);
});

it('garde l\'allowlist de l\'assistant en sous-ensemble strict de l\'allowlist démo', function () {
    $chat = config('ai.chat_tools');
    $demo = config('mcp.demo.tools');

    // Tout outil offert à l'assistant est déjà jugé sûr pour la démo publique.
    expect(array_diff($chat, $demo))->toBe([])
        // Strict : la démo autorise davantage (calculs, get_income, get_expense…).
        ->and(count($chat))->toBeLessThan(count($demo));
});

it('n\'expose aucun outil d\'écriture à l\'assistant', function () {
    $interdits = ['create_', 'update_', 'delete_', 'import_', 'export_', 'generate_', 'attach_'];

    foreach (config('ai.chat_tools') as $outil) {
        foreach ($interdits as $prefixe) {
            expect($outil)->not->toStartWith($prefixe);
        }
    }
});

// =============================================================================
// ⚠️ Propagation Docker — piège n°1 de ce projet
// =============================================================================

it('propage chaque variable AI_* de la configuration vers .env dans l\'entrypoint', function () {
    $boucle = boucleDePropagation();
    $variables = variablesAiDeLaConfig();

    expect($variables)->not->toBeEmpty();

    foreach ($variables as $variable) {
        // Sans cette ligne, le `-e AI_…=…` du docker run est ignoré en production.
        expect($boucle)->toContain($variable);
    }
});

it('propage aussi les variables de télémétrie', function () {
    // Correctif collatéral : le -e TELEMETRY_ENABLED=false exigé sur le cloud
    // était sans effet, faute d'être dans cette boucle.
    expect(boucleDePropagation())
        ->toContain('TELEMETRY_ENABLED')
        ->toContain('TELEMETRY_INSTALL_TYPE');
});

it('documente chaque variable AI_* dans .env.example', function () {
    $exemple = file_get_contents(base_path('.env.example'));

    foreach (variablesAiDeLaConfig() as $variable) {
        expect($exemple)->toMatch("/^{$variable}=/m");
    }
});

// =============================================================================
// Valeurs par défaut
// =============================================================================

it('laisse toutes les fonctions d\'IA désactivées par défaut', function () {
    // Une installation neuve ne doit émettre aucune requête vers un tiers.
    //
    // On lit les valeurs par défaut DANS LA SOURCE, sans exécuter le fichier :
    // `require` ferait résoudre env() contre l'environnement courant, et tout
    // worktree de développement (où ai-worktree.sh écrit AI_ENABLED=true dans
    // .env) ferait échouer un test censé décrire une installation NEUVE.
    $source = file_get_contents(base_path('config/ai.php'));

    $defauts = [
        // Interrupteur général et clé : rien d'actif, aucune clé en dur.
        "env('AI_ENABLED', false)",
        "env('AI_API_KEY')",
        // Plafonds désactivés : c'est l'administrateur qui les pose.
        "env('AI_DOCS_MONTHLY_QUOTA', 0)",
        "env('AI_CHAT_DAILY_QUOTA', 0)",
        // Sans moteur explicite, OpenRouter facturerait mistral-ocr.
        "env('AI_PDF_ENGINE', 'native')",
        // Conservation refusée par défaut chez le fournisseur.
        "env('AI_DATA_COLLECTION', 'deny')",
    ];

    foreach ($defauts as $defaut) {
        expect($source)->toContain($defaut);
    }

    // Aucun tarif codé en dur : ils se périment en quelques semaines, et un
    // chiffre faux vaut moins que pas de chiffre du tout. La table se remplit
    // par AI_MODEL_PRICES, jamais dans ce fichier.
    expect($source)->toContain("env('AI_MODEL_PRICES', '{}')")
        ->and(config('ai.model_prices'))->toBe([]);
});
