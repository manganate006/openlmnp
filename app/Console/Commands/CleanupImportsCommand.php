<?php

namespace App\Console\Commands;

use App\Support\ImportUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Purge les fichiers déposés pour import et jamais consommés.
 *
 * Les pages d'import suppriment le CSV dès l'import terminé. Reste l'aperçu abandonné :
 * l'utilisateur dépose son export Airbnb, regarde les dix premières lignes, ferme l'onglet.
 * Le fichier — noms des voyageurs, dates, montants — n'a alors plus aucun chemin qui y mène,
 * et rien ne le supprimerait jamais.
 *
 * Même règle que les fichiers temporaires de Livewire : 24 heures. Planifiée
 * quotidiennement dans `routes/console.php`, donc indépendante de l'activité de l'utilisateur.
 *
 * ⚠️ Ne balaie QUE `documents/{user_id}/imports`. Les fichiers restés à l'ancienne racine du
 * disque (avant que ces pages ne rangent leurs dépôts) n'ont pas de propriétaire identifiable :
 * ils relèvent de `openlmnp:purge-legacy-files`, qui rapporte avant d'agir.
 */
class CleanupImportsCommand extends Command
{
    protected $signature = 'openlmnp:cleanup-imports
                            {--hours=24 : Âge minimum des fichiers supprimés}';

    protected $description = 'Supprime les fichiers déposés pour import et laissés sans suite';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $threshold = now()->subHours(max(1, (int) $this->option('hours')))->getTimestamp();
        $deleted = 0;

        foreach ($disk->directories('documents') as $userDirectory) {
            $directory = $userDirectory . '/' . ImportUpload::SUBDIRECTORY;

            foreach ($disk->files($directory) as $file) {
                if ($disk->lastModified($file) < $threshold) {
                    $disk->delete($file);
                    $deleted++;
                }
            }
        }

        $this->info("Fichiers d'import supprimés : {$deleted}");

        return self::SUCCESS;
    }
}
