<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une conversation de l'assistant que l'utilisateur a choisi de nous transmettre avec un
 * pouce vers le bas. Le transcript est déjà masqué à l'écriture : il ne contient ni
 * montant, ni nom de bien, ni adresse, ni nom de voyageur, ni résultat d'outil.
 */
class AiChatReport extends Model
{
    use MassPrunable;

    /** Durée de conservation, annoncée dans l'aide de l'assistant. */
    public const RETENTION_DAYS = 90;

    public $timestamps = false;

    protected $fillable = ['user_id', 'reason', 'route', 'model', 'transcript', 'created_at'];

    protected function casts(): array
    {
        return [
            'transcript' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
