<?php

use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', \App\Mcp\OpenLmnpServer::class)
    ->middleware(['auth:sanctum', \App\Http\Middleware\McpGuard::class]);
