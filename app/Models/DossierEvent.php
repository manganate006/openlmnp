<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal d'audit des opérations sur un dossier lié. Jamais relu par un calcul.
 */
class DossierEvent extends Model
{
    use HasFactory;

    public const TYPE_CREATED = 'created';
    public const TYPE_SWITCHED = 'switched';
    public const TYPE_LEFT = 'left';
    public const TYPE_MEMBER_INVITED = 'member_invited';
    public const TYPE_MEMBER_ACCEPTED = 'member_accepted';
    public const TYPE_MEMBER_REVOKED = 'member_revoked';
    public const TYPE_ARCHIVED = 'archived';
    public const TYPE_TRANSFERRED = 'transferred';

    protected $fillable = ['linked_dossier_id', 'user_id', 'type', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(int $linkedDossierId, string $type, ?User $actor = null, array $meta = []): self
    {
        return self::create([
            'linked_dossier_id' => $linkedDossierId,
            'user_id' => $actor?->id,
            'type' => $type,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }
}
