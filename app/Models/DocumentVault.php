<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Coffre-fort d'un utilisateur : les paramètres de dérivation et la clé de
 * données scellée. Aucun secret exploitable sans la phrase de passe.
 */
class DocumentVault extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENCRYPTING = 'encrypting';

    public const STATUS_DECRYPTING = 'decrypting';

    protected $fillable = [
        'user_id',
        'kdf_salt',
        'kdf_ops',
        'kdf_mem',
        'kdf_alg',
        'wrapped_dek',
        'wrap_nonce',
        'dek_fingerprint',
        'public_key',
        'wrapped_secret_key',
        'secret_key_nonce',
        'key_version',
        'legacy_files_remaining',
        'status',
        'enabled_at',
    ];

    protected function casts(): array
    {
        return [
            'kdf_ops' => 'integer',
            'kdf_mem' => 'integer',
            'kdf_alg' => 'integer',
            'key_version' => 'integer',
            'legacy_files_remaining' => 'integer',
            'enabled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Une migration de masse est en cours (ou a été interrompue). */
    public function isMigrating(): bool
    {
        return in_array($this->status, [self::STATUS_ENCRYPTING, self::STATUS_DECRYPTING], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ENCRYPTING => 'chiffrement en cours',
            self::STATUS_DECRYPTING => 'déchiffrement en cours',
            default => 'actif',
        };
    }
}
