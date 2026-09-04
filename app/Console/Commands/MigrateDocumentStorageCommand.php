<?php

namespace App\Console\Commands;

use App\Support\DocumentStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Rapatrie les justificatifs restés à l'ancienne racine du disque `local`.
 *
 * Contexte : Laravel 11 a déplacé la racine du disque `local` de `storage/app` vers
 * `storage/app/private`. Les `file_path` en base sont relatifs à cette racine, et ne
 * comportent donc aucune trace de l'emplacement réel : les fichiers déposés avant la
 * montée de version sont restés sur place et ne sont plus servis. Rien n'échoue, aucune
 * erreur n'est journalisée — les justificatifs disparaissent simplement de l'interface.
 *
 * Toute instance auto-hébergée ayant franchi cette montée de version est concernée, ce
 * qui en fait un correctif produit et non le rattrapage d'une instance particulière.
 *
 * La commande ne touche PAS à la base : les chemins y sont déjà corrects, c'est le
 * fichier qui est au mauvais endroit. Elle est donc idempotente — une seconde exécution
 * ne trouve plus rien à faire.
 */
class MigrateDocumentStorageCommand extends Command
{
    protected $signature = 'openlmnp:migrate-document-storage
                            {--fix : Applique le déplacement (sinon simple rapport)}';

    protected $description = 'Déplace les justificatifs restés sous storage/app vers storage/app/private';

    /**
     * Sous-dossiers de l'ancienne racine à rapatrier.
     *
     * ⚠️ L'ancienne racine (`storage/app`) CONTIENT la nouvelle (`storage/app/private`) :
     * balayer `storage/app` en entier reviendrait à se déplacer sur soi-même, et
     * emporterait au passage `public/`, `framework/` et la clé `.app_key`. On énumère
     * donc les dossiers connus plutôt que de faire confiance à un parcours récursif.
     */
    private const MIGRATED_DIRECTORIES = ['documents', 'fec', 'tax-returns'];

    public function handle(): int
    {
        $legacy = DocumentStorage::legacyDisk();
        $current = Storage::disk('local');

        $pending = [];
        $shadowed = [];

        foreach (self::MIGRATED_DIRECTORIES as $directory) {
            foreach ($legacy->allFiles($directory) as $path) {
                if ($current->exists($path)) {
                    // Le même chemin existe des deux côtés. On ne tranche pas : écraser
                    // la version servie par une version dont on ne sait rien serait le
                    // seul geste réellement destructeur de cette commande.
                    $shadowed[] = $path;

                    continue;
                }

                $pending[] = $path;
            }
        }

        if ($shadowed !== []) {
            $this->warn(count($shadowed).' fichier(s) présent(s) des DEUX côtés, laissés en place :');

            foreach ($shadowed as $path) {
                $this->line("  {$path}");
            }

            $this->line('  → la version servie est celle de storage/app/private. Comparer à la main avant de supprimer l\'ancienne.');
            $this->newLine();
        }

        if ($pending === []) {
            $this->info('Aucun justificatif à l\'ancienne racine : rien à faire.');

            return self::SUCCESS;
        }

        $this->line(count($pending).' fichier(s) à rapatrier depuis storage/app :');

        foreach ($pending as $path) {
            $this->line('  '.$path);
        }

        if (! $this->option('fix')) {
            $this->newLine();
            $this->warn('Rapport seul. Relancer avec --fix pour déplacer.');

            return self::SUCCESS;
        }

        $moved = 0;
        $failed = [];

        foreach ($pending as $path) {
            // Lecture en flux : un justificatif peut peser plusieurs Mo, et l'instance de
            // référence tourne sur un conteneur à 1 Go.
            $stream = $legacy->readStream($path);

            if ($stream === false || $stream === null) {
                $failed[] = $path;

                continue;
            }

            $written = $current->writeStream($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            if (! $written) {
                $failed[] = $path;

                continue;
            }

            // Suppression seulement une fois l'écriture confirmée : en cas d'échec, le
            // fichier reste lisible par le repli de DocumentController.
            $legacy->delete($path);
            $moved++;
        }

        $this->newLine();
        $this->info("{$moved} fichier(s) déplacé(s).");

        if ($failed !== []) {
            $this->error(count($failed).' échec(s), fichiers laissés en place :');

            foreach ($failed as $path) {
                $this->line('  '.$path);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
