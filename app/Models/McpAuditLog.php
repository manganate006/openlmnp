<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class McpAuditLog extends Model
{
    use MassPrunable;

    public $timestamps = false;

    /**
     * Purge quotidienne (`model:prune`, routes/console.php) au-delà de MCP_AUDIT_RETENTION
     * jours (90 par défaut, 0 = jamais). Elle n'était que manuelle, depuis l'admin MCP.
     */
    public function prunable(): Builder
    {
        $days = (int) config('mcp.audit_retention_days', 90);

        return $days > 0
            ? static::where('created_at', '<', now()->subDays($days))
            : static::whereRaw('1 = 0');
    }

    protected $fillable = [
        'user_id',
        // Lot 5 des dossiers liés : NULL pour un jeton personnel ; sinon le
        // `User` du dossier pour lequel ce jeton a agi (`user_id` reste TOUJOURS
        // le PRINCIPAL — voir App\Http\Middleware\McpGuard::audit()).
        'dossier_user_id',
        'token_name',
        'tool_name',
        'parameters',
        'result_status',
        'ip_address',
        'duration_ms',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Le `User` du dossier pour lequel ce jeton a agi, si c'en était un. */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dossier_user_id');
    }
}
