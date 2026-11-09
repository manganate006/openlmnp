<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Console\Command;

/**
 * Purge les comptes de démonstration éphémères expirés.
 *
 * Supprime uniquement les utilisateurs is_demo=true dont demo_expires_at
 * est dans le passé, AINSI QUE toutes leurs données (biens, revenus, charges,
 * amortissements, travaux, mobilier, emprunts). Les comptes réels
 * (is_demo=false) ne sont JAMAIS touchés.
 *
 * La purge des données d'un compte (biens, exercices fiscaux, justificatifs sur
 * disque — racine courante ET ancienne racine Laravel 10 —, jetons MCP) est
 * partagée avec `openlmnp:account-delete` via `AccountDeletionService::purgeUserData()` :
 * un seul endroit à tenir à jour, pas deux versions qui pourraient diverger.
 */
class DemoCleanupCommand extends Command
{
    protected $signature = 'openlmnp:demo-cleanup {--all : Supprime tous les comptes démo, même non expirés (assainissement)}';

    protected $description = 'Supprime les comptes de démonstration expirés (ou tous avec --all) et leurs données';

    public function handle(AccountDeletionService $accountDeletion): int
    {
        $expired = User::query()
            ->where('is_demo', true)
            ->when(! $this->option('all'), fn ($q) => $q->where('demo_expires_at', '<', now()))
            ->get();

        $count = 0;

        foreach ($expired as $user) {
            // Sécurité : ne jamais supprimer un compte réel.
            if (! $user->is_demo) {
                continue;
            }

            $accountDeletion->purgeUserData($user);
            $user->delete();
            $count++;
        }

        $this->info("Comptes démo expirés supprimés : {$count}");

        return self::SUCCESS;
    }
}
