<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Jeton MCP, éventuellement rattaché à un dossier lié (lot 5 des dossiers liés,
 * voir `_admin/docs/plan-dossiers-lies-2026-10-14.md` § 3, option C retenue).
 *
 * Le jeton reste porté par le PRINCIPAL (`tokenable`, colonnes Sanctum natives
 * inchangées) : `dossier_user_id` ne fait que désigner, en plus, le `User` du
 * dossier pour lequel ce jeton agit — NULL = jeton personnel, comme avant ce
 * lot. La revérification d'appartenance à chaque appel se fait dans
 * `App\Http\Middleware\McpGuard`, jamais ici : ce modèle ne fait que porter la
 * colonne.
 *
 * Enregistré comme modèle de jeton Sanctum par
 * `Sanctum::usePersonalAccessTokenModel()` dans `App\Providers\AppServiceProvider`.
 */
class McpPersonalAccessToken extends PersonalAccessToken
{
    // ⚠️ Eloquent devine le nom de table depuis le nom de la CLASSE (snake_case
    // pluriel) : sans cette ligne, ce modèle chercherait `mcp_personal_access_tokens`
    // (table inexistante) au lieu de la vraie table Sanctum `personal_access_tokens`,
    // que ce modèle ne fait qu'étendre.
    protected $table = 'personal_access_tokens';

    // Reprend intégralement les casts du parent (une redéclaration de propriété
    // REMPLACE, elle ne fusionne pas) et ajoute `dossier_user_id` : explicite en
    // entier, pour que les comparaisons de App\Http\Middleware\McpGuard ne
    // dépendent jamais du type brut rendu par le pilote PDO.
    protected $casts = [
        'abilities' => 'json',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'dossier_user_id' => 'integer',
    ];

    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'dossier_user_id',
    ];

    /** Le `User` du dossier pour lequel ce jeton agit, si c'en est un. */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dossier_user_id');
    }
}
