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
];
