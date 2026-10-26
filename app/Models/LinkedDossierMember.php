<?php

namespace App\Models;

use App\Enums\DossierMemberRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LinkedDossierMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'linked_dossier_id', 'user_id', 'role', 'invited_by', 'accepted_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => DossierMemberRole::class,
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(LinkedDossier::class, 'linked_dossier_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isValid(): bool
    {
        return $this->accepted_at !== null && $this->revoked_at === null;
    }
}
