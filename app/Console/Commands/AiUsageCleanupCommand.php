<?php

namespace App\Console\Commands;

use App\Models\AiUsageLog;
use Illuminate\Console\Command;

/**
 * Purge le journal d'usage des fonctions d'IA au-delà de la rétention
 * configurée (AI_USAGE_RETENTION, 90 jours par défaut).
 *
 * S'exécute en console, donc hors session authentifiée : le global scope
 * BelongsToUserScope ne s'applique pas et la purge couvre bien tous les
 * comptes de l'instance.
 */
class AiUsageCleanupCommand extends Command
{
    protected $signature = 'openlmnp:ai-usage-cleanup';

    protected $description = "Purge les entrées du journal d'usage IA plus anciennes que AI_USAGE_RETENTION jours";

    public function handle(): int
    {
        $days = (int) config('ai.usage_retention_days', 90);

        if ($days <= 0) {
            $this->info('Rétention illimitée (AI_USAGE_RETENTION <= 0) — aucune purge.');

            return self::SUCCESS;
        }

        $deleted = AiUsageLog::withoutGlobalScopes()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Journal d'usage IA : {$deleted} entrée(s) de plus de {$days} jours supprimée(s).");

        return self::SUCCESS;
    }
}
