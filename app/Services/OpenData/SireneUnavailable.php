<?php

namespace App\Services\OpenData;

use RuntimeException;

/**
 * Échec d'une consultation Sirene, avec un message DÉJÀ destiné à l'utilisateur.
 *
 * Même logique que `DvfUnavailable` : les fabriques nommées existent pour que l'écran n'ait
 * jamais à interpréter un code HTTP. Une entreprise absente de la base n'est PAS une panne
 * (c'est `SireneClient::lookup()` qui rend `null`) : cette exception ne porte que les cas où
 * la vérification elle-même n'a pas pu avoir lieu.
 */
final class SireneUnavailable extends RuntimeException
{
    public static function disabled(): self
    {
        return new self('La vérification Sirene est désactivée sur cette instance.');
    }

    public static function network(): self
    {
        return new self('La base Sirene ne répond pas pour le moment. Réessayez dans quelques minutes.');
    }

    public static function throttled(): self
    {
        return new self('Trop de vérifications en peu de temps. Patientez une minute avant de recommencer.');
    }
}
