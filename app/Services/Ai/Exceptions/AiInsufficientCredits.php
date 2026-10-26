<?php

namespace App\Services\Ai\Exceptions;

/**
 * HTTP 402 — crédits épuisés côté fournisseur.
 */
final class AiInsufficientCredits extends AiException
{
    public function __construct(string $message = "Le compte du fournisseur d'IA n'a plus de crédit disponible.")
    {
        parent::__construct($message);
    }
}
