<?php

namespace App\Services\Ai\Exceptions;

/**
 * Délai HTTP (AI_TIMEOUT) dépassé, après une nouvelle tentative.
 */
final class AiTimeout extends AiException
{
    public function __construct(string $message = "Le délai d'attente du service d'intelligence artificielle est dépassé.")
    {
        parent::__construct($message);
    }
}
