<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

/**
 * Base de toutes les erreurs des fonctions d'IA.
 *
 * Règle absolue : le message est un texte FRANÇAIS FIXE, destiné à
 * l'utilisateur. Il ne contient jamais la clé d'API, ni la réponse brute du
 * fournisseur, ni la requête émise. C'est pour cela qu'AiClient n'utilise
 * jamais `Http::throw()` (dont l'exception embarque le corps de la réponse).
 *
 * `code()` renvoie l'identifiant court journalisé dans ai_usage_logs.error_code.
 */
abstract class AiException extends RuntimeException
{
    /** Identifiant court et stable de la classe d'erreur (journal + analytics). */
    public function code(): string
    {
        return class_basename(static::class);
    }
}
