<?php

namespace App\Services\OpenData;

use RuntimeException;

/**
 * Échec d'une consultation DVF, avec un message DÉJÀ destiné à l'utilisateur.
 *
 * Les fabriques nommées existent pour que la page n'ait jamais à interpréter un code HTTP :
 * « données indisponibles » et « ce département n'est pas couvert par DVF » ne se corrigent
 * pas de la même façon, et l'un des deux n'est pas une panne.
 */
final class DvfUnavailable extends RuntimeException
{
    public static function uncoveredDepartment(): self
    {
        return new self(
            'DVF ne couvre pas l\'Alsace-Moselle (Bas-Rhin, Haut-Rhin, Moselle) ni Mayotte : '
            .'ces territoires relèvent du livre foncier ou d\'un cadastre distinct.'
        );
    }

    public static function unknownCommune(): self
    {
        return new self('Aucune commune ne correspond à cette recherche.');
    }

    public static function noData(): self
    {
        return new self('Aucune vente publiée pour cette commune sur les millésimes disponibles.');
    }

    public static function network(): self
    {
        return new self('Les données de data.gouv.fr sont momentanément indisponibles. Réessayez dans un instant.');
    }

    public static function throttled(): self
    {
        return new self('Trop de recherches en peu de temps. Patientez une minute avant de recommencer.');
    }

    public static function disabled(): self
    {
        return new self('La consultation des données DVF est désactivée sur cette instance.');
    }
}
