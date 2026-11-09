<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Purge les fichiers temporaires d'upload laissés par Livewire.
 *
 * ⚠️ Livewire nettoie bien `livewire-tmp`, mais UNIQUEMENT au démarrage d'un
 * upload suivant : un utilisateur qui dépose un justificatif puis n'en dépose
 * plus jamais laisse sa copie EN CLAIR sur le disque, indéfiniment — juste à
 * côté d'un coffre-fort censé le protéger. Le nettoyage est donc planifié ici,
 * indépendamment de l'activité de l'utilisateur.
 *
 * Même règle que Livewire : 24 heures, à l'échelle de l'instance.
 */
class CleanupTemporaryUploadsCommand extends Command
{
    protected $signature = 'openlmnp:cleanup-uploads {--hours=24 : Âge minimum des fichiers supprimés}';

    protected $description = 'Supprime les fichiers temporaires d\'upload (livewire-tmp) de plus de 24 heures';

    public function handle(): int
    {
        $disk = Storage::disk(config('livewire.temporary_file_upload.disk') ?: 'local');
        $directory = 'livewire-tmp';

        if (! $disk->exists($directory)) {
            $this->info('Aucun fichier temporaire à supprimer.');

            return self::SUCCESS;
        }

        $threshold = now()->subHours(max(1, (int) $this->option('hours')))->getTimestamp();
        $deleted = 0;

        foreach ($disk->files($directory) as $file) {
            // Livewire dépose un .gitignore dans le dossier : ne pas y toucher.
            if (str_ends_with($file, '.gitignore')) {
                continue;
            }

            if ($disk->lastModified($file) < $threshold) {
                $disk->delete($file);
                $deleted++;
            }
        }

        $this->info("Fichiers temporaires supprimés : {$deleted}");

        return self::SUCCESS;
    }
}
