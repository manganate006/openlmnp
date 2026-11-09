<?php

namespace App\Services\Ai\Exceptions;

/**
 * Garde-fou : appel tenté alors qu'AiAccess::available() est faux (IA désactivée, compte démo, aucune clé).
 */
final class AiUnavailable extends AiException
{
    public function __construct(string $message = "Les fonctions d'intelligence artificielle ne sont pas disponibles pour ce compte.")
    {
        parent::__construct($message);
    }
}
