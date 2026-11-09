<?php

namespace App\Models;

use App\Models\Scopes\BelongsToUserScope;
use App\Support\AiAccess;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal des appels aux fonctions d'intelligence artificielle.
 *
 * Miroir de McpAuditLog, même philosophie : on journalise l'APPEL (modèle,
 * jetons, coût, durée, statut), jamais le CONTENU. `meta` ne contient que des
 * compteurs et des types — aucune valeur extraite, aucun texte, aucun base64,
 * et jamais la clé d'API.
 */
#[ScopedBy([BelongsToUserScope::class])]
class AiUsageLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'feature',
        'model',
        'key_source',
        'status',
        'error_code',
        'prompt_tokens',
        'completion_tokens',
        'cost_usd',
        'duration_ms',
        'meta',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
            // Chaîne décimale, jamais un float : les coûts se cumulent en bcmath.
            'cost_usd' => 'decimal:6',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Journalise un appel REFUSÉ pour quota épuisé (aucun appel réseau émis).
     *
     * Seul cas où une fonctionnalité écrit elle-même dans le journal : AiClient
     * n'applique pas les quotas, il ne peut donc pas tracer leurs refus.
     *
     * @param  array<mixed>  $meta  Compteurs et types uniquement.
     */
    public static function recordQuota(User $user, string $feature, array $meta = []): self
    {
        return self::recordRefusal($user, $feature, 'quota', $meta);
    }

    /**
     * Journalise une réponse REFUSÉE par un garde-fou applicatif (hors périmètre
     * de l'assistant, contrôle de sortie) — l'appel a eu lieu et a déjà sa ligne
     * `ok` ; celle-ci compte le refus qui a suivi.
     *
     * @param  array<mixed>  $meta  Compteurs et types uniquement.
     */
    public static function recordRefused(User $user, string $feature, array $meta = []): self
    {
        return self::recordRefusal($user, $feature, 'refused', $meta);
    }

    /**
     * Rattache un pouce à la dernière réponse de l'assistant. Le motif d'un pouce vers
     * le bas est un **type** choisi dans une liste, jamais un texte libre : le journal
     * ne contient aucun contenu de conversation.
     */
    public static function recordFeedback(User $user, bool $up, ?string $reason = null): ?self
    {
        $line = self::where('user_id', $user->id)
            ->where('feature', 'chat')
            ->where('status', 'ok')
            ->latest('id')
            ->first();

        if ($line === null) {
            return null;
        }

        $line->meta = array_merge($line->meta ?? [], array_filter([
            'feedback' => $up ? 'up' : 'down',
            'reason' => $up ? null : $reason,
        ], fn ($value) => $value !== null));

        $line->save();

        return $line;
    }

    /**
     * Journalise l'exécution d'un outil MCP par l'assistant. Elle ne passe pas par
     * `AiClient` (aucune requête réseau) et ne consomme aucun quota : c'est le seul
     * autre cas où une fonctionnalité écrit elle-même dans le journal.
     */
    public static function recordToolCall(User $user, string $tool, int $durationMs = 0): self
    {
        $access = AiAccess::for($user);
        $model = $access->model('chat');

        return self::create([
            'user_id' => $user->id,
            'feature' => 'chat_tool',
            'model' => $model !== '' ? $model : null,
            'key_source' => $access->source(),
            'status' => 'ok',
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'duration_ms' => $durationMs,
            'meta' => ['tool' => $tool],
            'created_at' => now(),
        ]);
    }

    /**
     * Journalise une remise de clé par OAuth (« Se connecter avec OpenRouter »).
     *
     * ⚠️ `meta` reste NUL, sans exception. La réponse d'échange contient la clé
     * de l'utilisateur et l'URL de retour contient le code d'autorisation :
     * c'est la ligne de journal du projet où il est le plus tentant, et le plus
     * grave, d'écrire quelque chose de trop.
     */
    public static function recordKeyHandover(User $user, string $status, ?string $errorCode = null, int $durationMs = 0): self
    {
        return self::create([
            'user_id' => $user->id,
            'feature' => 'key_oauth',
            'model' => null,
            'key_source' => AiAccess::SOURCE_USER,
            'status' => $status,
            'error_code' => $errorCode,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'duration_ms' => $durationMs,
            'meta' => null,
            'created_at' => now(),
        ]);
    }

    /** @param array<mixed> $meta */
    private static function recordRefusal(User $user, string $feature, string $status, array $meta): self
    {
        $access = AiAccess::for($user);
        $model = $access->model(in_array($feature, ['chat', 'chat_tool'], strict: true) ? 'chat' : 'documents');

        return self::create([
            'user_id' => $user->id,
            'feature' => $feature,
            'model' => $model !== '' ? $model : null,
            'key_source' => $access->source(),
            'status' => $status,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'duration_ms' => 0,
            'meta' => $meta === [] ? null : $meta,
            'created_at' => now(),
        ]);
    }
}
