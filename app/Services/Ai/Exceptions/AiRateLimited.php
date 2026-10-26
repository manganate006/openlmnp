<?php

namespace App\Services\Ai\Exceptions;

/**
 * HTTP 429 — trop de requêtes, après une nouvelle tentative.
 */
final class AiRateLimited extends AiException
{
    public function __construct(string $message = "Le fournisseur d'IA limite temporairement les requêtes. Réessayez dans un instant.")
    {
        parent::__construct($message);
    }
}
