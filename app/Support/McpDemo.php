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

        return self::isDemoUser(($request ?? request())->user());
    }

    /**
     * Est-ce le compte démo public ?
     *
     * Il est reconnu à son e-mail, que tout le monde peut modifier puisque ses
     * identifiants sont publics. EditProfile et McpTokens bloquent donc pour lui
     * l'e-mail, le mot de passe, la bascule MCP et la révocation des jetons :
     * sinon un visiteur rend le jeton public lecture-écriture, ou casse la démo.
     */
    public static function isDemoUser(mixed $user): bool
    {
        return config('mcp.demo.enabled')
            && $user instanceof User
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
