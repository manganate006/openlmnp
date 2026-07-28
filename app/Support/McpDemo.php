<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Garde du token MCP démo public (lecture seule).
 *
 * La détection se fait par COMPTE (email démo), pas par nom de token : les
 * identifiants du compte démo sont publics (demo@openlmnp.fr / demo2026), donc
 * n'importe qui pourrait s'y connecter et créer son propre token MCP. Traiter
 * TOUT token porté par le compte démo comme lecture seule ferme cette brèche.
 */
class McpDemo
{
    /**
     * La requête courante est-elle authentifiée en tant que compte démo public ?
     */
    public static function isDemoRequest(?Request $request = null): bool
    {
        if (! config('mcp.demo.enabled')) {
            return false;
        }

        $user = ($request ?? request())->user();

        return $user instanceof User
            && $user->email === config('mcp.demo.email');
    }

    /**
     * L'outil est-il exécutable dans le contexte courant ?
     * Hors démo : tout est permis. En démo : uniquement l'allowlist.
     */
    public static function allows(string $toolName, ?Request $request = null): bool
    {
        if (! self::isDemoRequest($request)) {
            return true;
        }

        return in_array($toolName, config('mcp.demo.tools', []), true);
    }

    /**
     * Message d'upsell renvoyé quand un outil non autorisé est appelé en démo.
     */
    public static function blockedMessage(): string
    {
        return "🔒 Action désactivée dans la démo publique OpenLMNP (lecture seule). "
            . "Créez un compte gratuit sur https://openlmnp.fr pour gérer vos propres données.";
    }
}
