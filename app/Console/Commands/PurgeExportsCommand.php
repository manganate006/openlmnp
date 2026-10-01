<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Supprime les archives de justificatifs restées sur le disque.
 *
 * L'export par l'interface est téléchargé puis effacé aussitôt ; celui de l'outil MCP
 * `export_documents` reste disponible le temps de son lien signé (une heure). Avant cette
 * commande, rien ne les supprimait : chaque appel laissait un ZIP dans `temp/`
 * (GHSA-j4gm-g8m8-93x2, point 14).
 */
class PurgeExportsCommand extends Command
{
    protected $signature = 'openlmnp:purge-exports {--hours=24 : Âge minimal des archives supprimées}';

    protected $description = 'Supprime les archives de justificatifs (ZIP) de plus de 24 heures';

    public function handle(): int
    {
        $limit = now()->subHours(max(1, (int) $this->option('hours')))->getTimestamp();
        $disk = Storage::disk('local');
        $deleted = 0;

        $candidates = $disk->files('temp');

        foreach ($disk->directories('documents') as $userDirectory) {
            array_push($candidates, ...$disk->files($userDirectory . '/exports'));
        }

        foreach ($candidates as $file) {
            if (str_ends_with($file, '.zip') && $disk->lastModified($file) < $limit) {
                $disk->delete($file);
                $deleted++;
            }
        }

        $this->info("{$deleted} archive(s) supprimée(s).");

        return self::SUCCESS;
    }
}
