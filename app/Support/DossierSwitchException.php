<?php

namespace App\Support;

use RuntimeException;

/**
 * Échec d'une bascule vers un dossier lié, avec un message déjà destiné à
 * l'utilisateur. Même patron que `SireneUnavailable` / `DvfUnavailable`.
 */
final class DossierSwitchException extends RuntimeException
{
    public static function unauthenticated(): self
    {
        return new self('Vous devez être connecté pour ouvrir un dossier.');
    }

    public static function alreadyLinked(): self
    {
        return new self('Revenez d\'abord à votre compte principal avant d\'ouvrir un autre dossier.');
    }

    public static function principalSuspended(): self
    {
        return new self('Votre compte est suspendu : impossible d\'ouvrir un dossier lié.');
    }

    public static function demo(): self
    {
        return new self('Un compte de démonstration ne peut pas ouvrir de dossier lié.');
    }

    public static function archived(): self
    {
        return new self('Ce dossier est archivé.');
    }

    public static function notAMember(): self
    {
        return new self('Vous n\'avez pas accès à ce dossier.');
    }

    /**
     * Anti-détournement du lot « dossiers membre » (décision du 2026-10-15) : un
     * compte membre ne peut ouvrir un dossier qui n'a plus aucun membre actif autre
     * que des comptes membres. Voir `LinkedDossier::hasActiveNonMemberBacker()`.
     */
    public static function noActiveBacker(): self
    {
        return new self('Ce dossier n\'a plus aucun membre abonné ou en compte standard actif : il n\'est plus accessible.');
    }
}
