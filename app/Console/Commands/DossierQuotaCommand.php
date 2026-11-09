<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Pose le quota de dossiers liés d'un compte (`users.linked_dossier_quota`).
 *
 * Sur le cloud, cette colonne sera posée par l'API de provisioning de la vitrine
 * (lot 4) à chaque paiement ; en attendant, et pour les bêta-testeurs (plan des
 * dossiers liés, décision n°2), cette commande fait le même geste à la main.
 * Sans effet sur une instance auto-hébergée, où le quota par défaut est déjà
 * illimité (voir `App\Support\DossierQuota`) — y poser une valeur la restreint.
 */
class DossierQuotaCommand extends Command
{
    protected $signature = 'openlmnp:dossier-quota {email : Adresse du compte} {quota : Nombre de dossiers liés offerts (entier naturel)}';

    protected $description = 'Pose le quota de dossiers liés d\'un compte';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $quota = (int) $this->argument('quota');

        if ($quota < 0) {
            $this->error('Le quota doit être un entier naturel (0 ou plus).');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->where('is_linked_dossier', false)->first();

        if ($user === null) {
            $this->error("Aucun compte principal pour {$email}.");

            return self::FAILURE;
        }

        $user->update(['linked_dossier_quota' => $quota]);

        $this->info("Quota de dossiers liés de {$email} posé à {$quota}.");

        return self::SUCCESS;
    }
}
