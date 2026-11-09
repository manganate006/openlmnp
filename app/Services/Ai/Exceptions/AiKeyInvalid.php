<?php

namespace App\Services\Ai\Exceptions;

/**
 * HTTP 401 — clé refusée par le fournisseur.
 */
final class AiKeyInvalid extends AiException
{
    public function __construct(string $message = "La clé d'intelligence artificielle est invalide ou révoquée.")
    {
        parent::__construct($message);
    }
}
