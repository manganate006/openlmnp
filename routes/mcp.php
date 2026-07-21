<?php

use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', \App\Mcp\OpenLmnpServer::class)
    ->middleware(['auth:sanctum', \App\Http\Middleware\McpGuard::class]);

// Transport stdio pour un usage local self-hosté (`php artisan mcp:start openlmnp`) :
// pas de HTTP ni de token — réservé à un opérateur ayant déjà accès au serveur.
Mcp::local('openlmnp', \App\Mcp\OpenLmnpServer::class);
