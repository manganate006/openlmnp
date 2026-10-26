<?php

namespace App\Console\Commands;

use App\Support\DocumentStorage;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Supprime les fichiers en clair que l'application ne produit plus.
 *
 * Trois familles, toutes héritées d'un état antérieur du code, toutes sur le volume que le
 * coffre-fort des justificatifs est censé mettre à l'abri :
 *
 * 1. **Liasses fiscales** (`tax-returns/`) et **FEC** (`fec/`) — écrits en clair et laissés là.
 *    Ils portent davantage qu'un justificatif : adresses, SIREN, résultats, écritures. Ils sont
 *    désormais reconstruits à chaque téléchargement et ne touchent plus le disque.
 * 2. **CSV d'import restés à la racine du disque privé** (`<ulid>.csv`) — un `FileUpload` sans
 *    `directory()` les y déposait, sans lien en base, sans propriétaire identifiable. Ils
 *    contiennent les noms des voyageurs et les montants versés.
 * 3. Les colonnes `fiscal_years.pdf_path` / `fec_path`, qui pointaient vers (1).
 *
 * ⚠️ **Suppression, pas rapatriement, pour les CSV de la racine** : un fichier nommé
 * `01K4…J7.csv` posé à la racine n'a AUCUN lien vers un utilisateur — ni en base, ni dans son
 * chemin. Impossible de le ranger sous son propriétaire sans l'inventer. Comme rien ne le lit
 * et que son contenu est précisément la donnée à protéger, la seule issue honnête est de le
 * détruire — d'où le rapport par défaut, pour que la décision soit vue avant d'être prise.
 *
 * ⚠️ Balaie les DEUX racines : `storage/app/private` (Laravel 11+) et `storage/app` (avant),
 * en énumérant des dossiers connus plutôt qu'en descendant récursivement — l'ancienne racine
 * contient la nouvelle.
 *
 * Idempotente : une seconde exécution ne trouve plus rien.
 */
class PurgeLegacyFilesCommand extends Command
{
    protected $signature = 'openlmnp:purge-legacy-files
                            {--fix : Applique la suppression (sinon simple rapport)}';

    protected $description = 'Supprime les liasses, FEC et CSV d\'import laissés en clair par les versions antérieures';

    /** Dossiers de documents générés, relatifs à chaque racine. */
    private const GENERATED_DIRECTORIES = ['fec', 'tax-returns'];

    public function handle(): int
    {
        /** @var array<int, array{disk: Filesystem, root: string, path: string, size: int}> $targets */
        $targets = [];

        foreach ($this->roots() as $root => $disk) {
            foreach (self::GENERATED_DIRECTORIES as $directory) {
                foreach ($disk->allFiles($directory) as $path) {
                    $targets[] = ['disk' => $disk, 'root' => $root, 'path' => $path, 'size' => $disk->size($path)];
                }
            }

            // Uniquement les fichiers `.csv` posés À LA RACINE, jamais un parcours récursif :
            // les sous-dossiers de cette racine sont la nouvelle racine, les justificatifs,
            // le cache DVF et la clé de l'instance.
            foreach ($disk->files() as $path) {
                if (str_ends_with(strtolower($path), '.csv')) {
                    $targets[] = ['disk' => $disk, 'root' => $root, 'path' => $path, 'size' => $disk->size($path)];
                }
            }
        }

        $stalePaths = DB::table('fiscal_years')
            ->where(fn ($query) => $query->whereNotNull('pdf_path')->orWhereNotNull('fec_path'))
            ->count();

        if ($targets === [] && $stalePaths === 0) {
            $this->info('Aucun fichier en clair hérité : rien à faire.');

            return self::SUCCESS;
        }

        if ($targets !== []) {
            $total = array_sum(array_column($targets, 'size'));

            $this->line(count($targets) . ' fichier(s) à supprimer (' . $this->humanSize($total) . ') :');

            foreach ($targets as $target) {
                $this->line("  {$target['root']}/{$target['path']} (" . $this->humanSize($target['size']) . ')');
            }
        }

        if ($stalePaths > 0) {
            $this->newLine();
            $this->line("{$stalePaths} exercice(s) portent encore un chemin `pdf_path`/`fec_path` à vider.");
            $this->line('  → la date de génération est conservée à part, les badges et la checklist ne changent pas.');
        }

        if (! $this->option('fix')) {
            $this->newLine();
            $this->warn('Rapport seul. Relancer avec --fix pour supprimer.');

            return self::SUCCESS;
        }

        $deleted = 0;
        $failed = [];

        foreach ($targets as $target) {
            if ($target['disk']->delete($target['path'])) {
                $deleted++;
            } else {
                $failed[] = "{$target['root']}/{$target['path']}";
            }
        }

        DB::table('fiscal_years')
            ->where(fn ($query) => $query->whereNotNull('pdf_path')->orWhereNotNull('fec_path'))
            ->update(['pdf_path' => null, 'fec_path' => null]);

        $this->newLine();
        $this->info("{$deleted} fichier(s) supprimé(s), {$stalePaths} chemin(s) vidé(s) en base.");

        if ($failed !== []) {
            $this->error(count($failed) . ' échec(s), fichiers laissés en place :');

            foreach ($failed as $path) {
                $this->line('  ' . $path);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return array<string, Filesystem> Racine lisible => disque. */
    private function roots(): array
    {
        return [
            'storage/app/private' => Storage::disk('local'),
            'storage/app'         => DocumentStorage::legacyDisk(),
        ];
    }

    private function humanSize(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1) . ' Mo'
            : max(1, (int) round($bytes / 1024)) . ' Ko';
    }
}
