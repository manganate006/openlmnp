<?php

namespace App\Models\Scopes;

/**
 * Les scopes `BelongsToUser*` ne filtrent que si un utilisateur est connecté : c'est
 * voulu pour les commandes de maintenance, qui travaillent sur toute l'instance.
 *
 * Le serveur MCP local (`php artisan mcp:start openlmnp`), lui, agit au nom d'UN compte.
 * Quand il n'a pas pu le désigner (plusieurs comptes réels, OPENLMNP_MCP_USER absent),
 * un scope qui ne filtre rien rendait les biens de tout le monde (GHSA-j4gm-g8m8-93x2,
 * point 11). Il pose alors ce marqueur, et les scopes ne rendent plus RIEN.
 */
final class UnresolvedUser
{
    private const KEY = 'openlmnp.scopes.unresolved-user';

    public static function mark(): void
    {
        app()->instance(self::KEY, true);
    }

    public static function isMarked(): bool
    {
        return app()->bound(self::KEY);
    }
}
