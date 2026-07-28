<?php

return [
    'enabled' => env('MCP_ENABLED', false),
    'rate_limit' => env('MCP_RATE_LIMIT', 60),
    'max_tokens_per_user' => env('MCP_MAX_TOKENS', 5),
    'audit_retention_days' => env('MCP_AUDIT_RETENTION', 90),
    'file_path_prefix' => env('MCP_FILE_PATH_PREFIX', ''),

    // Compte à authentifier en transport stdio (`php artisan mcp:start openlmnp`),
    // où aucune authentification HTTP n'a lieu. À défaut, l'unique compte réel
    // (hors démo) de l'instance est utilisé.
    'local_user' => env('OPENLMNP_MCP_USER'),

    /*
    |--------------------------------------------------------------------------
    | Token MCP démo public (lecture seule)
    |--------------------------------------------------------------------------
    |
    | Un token Bearer partagé, adossé au compte démo à données fictives
    | (config('demo.email')), pour permettre aux annuaires MCP (Smithery,
    | inspecteur Glama) et aux curieux d'essayer le serveur sans compte.
    |
    | Les 44 outils restent visibles (marketing), mais SEULS les outils de
    | l'allowlist `tools` s'exécutent : les autres renvoient un message
    | d'upsell (barrière appliquée dans App\Http\Middleware\McpGuard).
    | La détection se fait par COMPTE (email démo), car les identifiants du
    | compte démo sont publics : tout token MCP sur ce compte est lecture seule.
    |
    */
    'demo' => [
        'enabled' => env('MCP_DEMO_ENABLED', false),

        // Compte démo (partagé avec le mode démo web). Réutilise config('demo.email').
        'email' => env('DEMO_EMAIL', 'demo@openlmnp.fr'),

        // Valeur brute du token public stable (SANS caractère « | »). Le hash
        // sha256 est stocké côté Sanctum par `php artisan openlmnp:mcp-demo-token`.
        'token' => env('MCP_DEMO_TOKEN'),

        // Requêtes/minute par IP (le token est partagé/public).
        'rate_limit_per_minute' => (int) env('MCP_DEMO_RATE_LIMIT', 20),

        // Allowlist des outils RÉELLEMENT exécutables en démo (lecture + calculs
        // non-persistants). Source de vérité unique — verrouillée par un test.
        'tools' => [
            // Lecture
            'list_properties', 'get_property',
            'list_incomes', 'get_income',
            'list_expenses', 'get_expense',
            'list_loans', 'get_loan', 'get_loan_schedule',
            'list_fiscal_years', 'get_fiscal_year',
            'list_furniture', 'list_property_works', 'list_property_components',
            'get_onboarding_status', 'get_dashboard_summary',
            'list_categories',
            // Calcul & simulation (aucune écriture)
            'compute_depreciation', 'compute_fiscal_year', 'compute_tva',
            'compare_micro_bic', 'get_projection', 'get_simulation',
        ],
    ],
];
