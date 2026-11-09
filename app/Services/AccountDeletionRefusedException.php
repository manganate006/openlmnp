<?php

namespace App\Services;

use RuntimeException;

/**
 * Suppression de compte refusée, avec un message déjà destiné à l'utilisateur.
 *
 * ⚠️ `$reason` est un code MACHINE (constantes `AccountDeletionService::REFUSAL_*`) —
 * nommé différemment de `$code`, propriété déjà déclarée (non readonly) par
 * `Exception` elle-même : la redéclarer en readonly ici est un conflit de type
 * fatal, pas une simple coquille de nommage. Ce code permet à l'appelant
 * (commande, action Livewire) de distinguer les cas sans reparser le message, par
 * exemple pour décider s'il doit proposer un lien vers la résiliation (cloud)
 * plutôt qu'une simple explication.
 */
final class AccountDeletionRefusedException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
