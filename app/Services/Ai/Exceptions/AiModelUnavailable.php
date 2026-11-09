<?php

namespace App\Services\Ai\Exceptions;

/**
 * HTTP 404 / 503 — modèle inconnu ou fournisseur hors service.
 */
final class AiModelUnavailable extends AiException
{
    public function __construct(string $message = "Le modèle d'intelligence artificielle demandé est momentanément indisponible.")
    {
        parent::__construct($message);
    }
}
