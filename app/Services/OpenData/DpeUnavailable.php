<?php

namespace App\Services\OpenData;

use RuntimeException;

/**
 * Échec d'une recherche de DPE, avec un message DÉJÀ destiné à l'utilisateur.
 *
 * Même patron que `DvfUnavailable` : les fabriques nommées évitent à l'écran d'avoir à
 * interpréter un code HTTP.
 */
final class DpeUnavailable extends RuntimeException
{
    public static function network(): self
    {
        return new self(
            'La Géoplateforme (IGN) ou la base de l\'ADEME sont momentanément indisponibles. '
            .'Réessayez dans un instant.'
        );
    }

    public static function throttled(): self
    {
        return new self('Trop de recherches en peu de temps. Patientez une minute avant de recommencer.');
    }

    public static function disabled(): self
    {
        return new self('La recherche de DPE est désactivée sur cette instance.');
    }
}
