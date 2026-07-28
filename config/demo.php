<?php

return [
    'enabled' => env('DEMO_MODE', false),
    'ttl_hours' => (int) env('DEMO_TTL_HOURS', 24),
    'max_accounts' => (int) env('DEMO_MAX_ACCOUNTS', 200),

    // Compte de démonstration fixe (seedé par DemoSeeder, is_demo=false → jamais purgé).
    'email' => env('DEMO_EMAIL', 'demo@openlmnp.fr'),
];
