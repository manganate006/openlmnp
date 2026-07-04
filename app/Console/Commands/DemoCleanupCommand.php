<?php

namespace App\Console\Commands;

use App\Models\FiscalYear;
use App\Models\Property;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Purge les comptes de démonstration éphémères expirés.
 *
 * Supprime uniquement les utilisateurs is_demo=true dont demo_expires_at
 * est dans le passé, AINSI QUE toutes leurs données (biens, revenus, charges,
 * amortissements, travaux, mobilier, emprunts). Les comptes réels
 * (is_demo=false) ne sont JAMAIS touchés.
 */
class DemoCleanupCommand extends Command
{
    protected $signature = 'openlmnp:demo-cleanup';

    protected $description = 'Supprime les comptes de démonstration expirés et leurs données';

    public function handle(): int
    {
        $expired = User::query()
            ->where('is_demo', true)
            ->where('demo_expires_at', '<', now())
            ->get();

        $count = 0;

        foreach ($expired as $user) {
            // Sécurité : ne jamais supprimer un compte réel.
            if (! $user->is_demo) {
                continue;
            }

            // Suppression explicite des biens et exercices fiscaux du user.
            // Les données rattachées (incomes, expenses, components, works,
            // furniture, loans, payments) partent via le cascade FK property_id.
            Property::withoutGlobalScopes()->where('user_id', $user->id)->get()
                ->each(fn (Property $property) => $property->delete());

            FiscalYear::withoutGlobalScopes()->where('user_id', $user->id)->delete();

            $user->delete();
            $count++;
        }

        $this->info("Comptes démo expirés supprimés : {$count}");

        return self::SUCCESS;
    }
}
