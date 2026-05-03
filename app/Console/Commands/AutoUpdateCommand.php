<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\UpdateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoUpdateCommand extends Command
{
    protected $signature = 'app:auto-update';

    protected $description = 'Vérifie et applique automatiquement les mises à jour depuis GitHub';

    public function handle(): int
    {
        if (Setting::get('auto_update_enabled') !== '1') {
            $this->info('Mise à jour automatique désactivée.');

            return self::SUCCESS;
        }

        $service = new UpdateService();
        $branchInfo = $service->checkBranchUpdates();

        $aheadBy = $branchInfo['ahead_by'] ?? 0;
        Setting::set('update_behind_count', (string) $aheadBy);

        if (! ($branchInfo['available'] ?? false)) {
            $this->info('Déjà à jour.');

            return self::SUCCESS;
        }

        $count = $branchInfo['ahead_by'] ?? '?';
        $this->info("Mise à jour détectée ({$count} commits en retard). Déploiement...");
        Log::info("[AutoUpdate] {$count} commits en retard, déploiement automatique lancé.");

        $result = $service->applyBranchUpdate();

        if ($result['success'] ?? false) {
            Setting::set('update_behind_count', '0');
            $this->info('Mise à jour appliquée avec succès.');
            Log::info('[AutoUpdate] Déploiement réussi.', ['backup' => $result['backup_path'] ?? null]);

            return self::SUCCESS;
        }

        $error = $result['error'] ?? 'Erreur inconnue';
        $this->error("Échec : {$error}");
        Log::error("[AutoUpdate] Échec du déploiement : {$error}");

        return self::FAILURE;
    }
}
