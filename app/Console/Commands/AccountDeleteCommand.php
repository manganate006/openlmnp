<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Console\Command;

/**
 * Suppression définitive d'un compte et de toutes ses données — droit à
 * l'effacement (RGPD, art. 17). Aucune autre voie n'existait jusqu'ici : les comptes
 * de test de la bêta du 2026-10-15 avaient dû être effacés à la main dans `tinker`,
 * sans jamais purger ni les justificatifs sur disque ni les jetons MCP.
 *
 * Toute la logique (refus, suppression, sort des dossiers d'indivision partagés) vit
 * dans `App\Services\AccountDeletionService`, partagée avec l'action « Supprimer mon
 * compte » du profil.
 */
class AccountDeleteCommand extends Command
{
    protected $signature = 'openlmnp:account-delete
        {email : Adresse du compte à supprimer}
        {--dry-run : Affiche ce qui serait supprimé, sans rien supprimer}
        {--force : Ne pas demander de confirmation}';

    protected $description = 'Supprime définitivement un compte et toutes ses données (RGPD, droit à l\'effacement)';

    public function handle(AccountDeletionService $service): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("Aucun compte pour {$email}.");

            return self::FAILURE;
        }

        if ($reason = $service->refusalReason($user)) {
            $this->error('Suppression refusée : '.$service->refusalMessage($reason));

            return self::FAILURE;
        }

        $this->displayPlan($user, $service->plan($user));

        if ($this->option('dry-run')) {
            $this->comment('Dry-run : aucune suppression effectuée.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Confirmer la suppression DÉFINITIVE du compte {$email} ?", false)) {
            $this->comment('Suppression annulée.');

            return self::SUCCESS;
        }

        $report = $service->delete($user);

        $this->info("Compte {$email} supprimé.");
        $this->line('Dossiers d\'indivision supprimés avec lui : '.$report['dossiers_deleted']);
        $this->line('Dossiers d\'indivision partagés quittés (conservés pour les autres membres) : '.$report['dossiers_kept']);

        return self::SUCCESS;
    }

    /**
     * @param  array{properties: int, fiscal_years: int, mcp_tokens: int, vault_enabled: bool,
     *               documents_on_disk: bool, dossiers_deleted: list<string>, dossiers_kept: list<string>}  $plan
     */
    private function displayPlan(User $user, array $plan): void
    {
        $this->line("Compte : {$user->email} (#{$user->id})");
        $this->line('Biens : '.$plan['properties']);
        $this->line('Exercices fiscaux : '.$plan['fiscal_years']);
        $this->line('Jetons MCP : '.$plan['mcp_tokens']);
        $this->line('Coffre-fort activé : '.($plan['vault_enabled'] ? 'oui' : 'non'));
        $this->line('Justificatifs sur disque : '.($plan['documents_on_disk'] ? 'oui' : 'non'));

        if ($plan['dossiers_deleted'] !== []) {
            $this->line('Dossiers d\'indivision supprimés avec le compte (il en est l\'unique membre actif) : '
                .implode(', ', $plan['dossiers_deleted']));
        }

        if ($plan['dossiers_kept'] !== []) {
            $this->line('Dossiers d\'indivision partagés, conservés pour les autres membres : '
                .implode(', ', $plan['dossiers_kept']));
        }
    }
}
