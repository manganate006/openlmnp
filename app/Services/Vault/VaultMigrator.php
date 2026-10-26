<?php

namespace App\Services\Vault;

use App\Models\Document;
use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyWork;
use App\Models\User;
use App\Support\AiStagingUpload;
use App\Support\ImportUpload;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Chiffre (ou déchiffre) l'ensemble des fichiers d'un utilisateur, par lots.
 *
 * Idempotent par construction : l'état d'un fichier est lu dans ses premiers
 * octets (le magic du format), jamais en base. Relancer l'opération après une
 * interruption reprend donc exactement là où elle s'était arrêtée, et un arbre
 * à moitié migré reste cohérent.
 *
 * ⚠️ Deux balayages qui ne se recouvrent pas, parce que la table `documents` n'a
 * pas de colonne `user_id` — la propriété se déduit par le parent :
 *
 *  1. le DISQUE, sous `documents/{user_id}/` : c'est lui qui fait foi. Il attrape
 *     les photos de biens, et les fichiers dont la ligne en base a disparu ;
 *  2. la BASE (`documents.file_path`, `properties.photo_path`) : elle sert à
 *     repérer ce que le disque ne montre pas — les chemins qui ne résolvent plus.
 *     Une instance montée depuis Laravel 11 en a : la racine du disque `local`
 *     est passée de `storage/app` à `storage/app/private`, laissant des lignes
 *     qui pointent dans le vide. On les COMPTE et on continue : les réparer est
 *     un autre chantier.
 */
class VaultMigrator
{
    public const DIRECTION_ENCRYPT = 'encrypt';

    public const DIRECTION_DECRYPT = 'decrypt';

    public function __construct(private DocumentCipher $cipher) {}

    /**
     * Traite au plus $batch fichiers. $batch = 0 traite tout.
     */
    public function process(
        User $user,
        string $key,
        string $direction,
        int $batch = 0,
        bool $dryRun = false,
        int $keyVersion = 1,
        ?string $sealedPair = null,
    ): VaultMigrationReport {
        $report = new VaultMigrationReport;
        $disk = Storage::disk('local');

        $pending = [];

        foreach ($this->candidates($user, $report) as $path) {
            $absolute = $disk->path($path);
            $isEncrypted = $this->cipher->isEncryptedFile($absolute);

            $needsWork = $direction === self::DIRECTION_ENCRYPT ? ! $isEncrypted : $isEncrypted;

            if ($needsWork) {
                $pending[] = $absolute;
            } else {
                $report->skipped++;
            }
        }

        $report->remaining = count($pending);

        if ($dryRun) {
            return $report;
        }

        $slice = $batch > 0 ? array_slice($pending, 0, $batch) : $pending;

        foreach ($slice as $absolute) {
            try {
                if ($direction === self::DIRECTION_ENCRYPT) {
                    $this->cipher->encryptFileInPlace($absolute, $key, $keyVersion);
                } else {
                    $this->cipher->decryptFileInPlace($absolute, $key, $sealedPair);
                }

                $report->processed++;
                $report->remaining--;
            } catch (Throwable) {
                // Le fichier est laissé intact : encryptFileInPlace() n'écrase
                // l'original qu'après avoir écrit la totalité du résultat.
                $report->failed++;
                $report->remaining--;
                $report->failedPaths[] = basename($absolute);
            }
        }

        return $report;
    }

    /** Nombre de fichiers restant à traiter, sans rien modifier. */
    public function pendingCount(User $user, string $direction): int
    {
        return $this->process($user, '', $direction, 0, dryRun: true)->remaining;
    }

    /**
     * Union des fichiers à considérer, sans doublon.
     *
     * @return list<string>
     */
    private function candidates(User $user, VaultMigrationReport $report): array
    {
        $disk = Storage::disk('local');
        $root = 'documents/' . $user->id;

        $paths = $disk->exists($root) ? $disk->allFiles($root) : [];

        // Les fichiers temporaires laissés par une transformation interrompue ne
        // sont ni du clair exploitable ni du chiffré valide : on les ignore.
        //
        // ⚠️ Et les DEUX RÉPERTOIRES DE TRANSIT, pour une raison différente : leur
        // contenu est en clair à dessein, le temps d'un import ou d'une analyse, et
        // il sera supprimé dès la consommation. Les chiffrer ici reviendrait à le
        // faire sous les pieds de l'utilisateur pendant qu'il relit une proposition :
        // l'aperçu tombe, et « Enregistrer » range du chiffré déjà chiffré. Ils
        // n'ont pas à survivre assez longtemps pour mériter un coffre.
        $transit = [
            '/' . ImportUpload::SUBDIRECTORY . '/',
            '/' . AiStagingUpload::SUBDIRECTORY . '/',
        ];

        $paths = array_values(array_filter(
            $paths,
            fn (string $path) => ! str_ends_with($path, '.vault-tmp')
                && ! Str::contains($path, $transit),
        ));

        $known = array_flip($paths);

        foreach ($this->databasePaths($user) as $path) {
            if (isset($known[$path])) {
                continue;
            }

            if (! $disk->exists($path)) {
                $report->missing++;
                $report->missingPaths[] = $path;

                continue;
            }

            // Chemin hors de l'arborescence attendue mais bien présent : on le
            // traite quand même, sinon un justificatif resterait en clair.
            $paths[] = $path;
            $known[$path] = true;
        }

        return $paths;
    }

    /**
     * Chemins de fichiers référencés en base pour cet utilisateur.
     *
     * @return list<string>
     */
    private function databasePaths(User $user): array
    {
        $propertyIds = Property::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->pluck('id');

        if ($propertyIds->isEmpty()) {
            return [];
        }

        $paths = Property::withoutGlobalScopes()
            ->whereIn('id', $propertyIds)
            ->whereNotNull('photo_path')
            ->pluck('photo_path')
            ->all();

        foreach ([Expense::class, Furniture::class, PropertyWork::class] as $modelClass) {
            $ownedIds = $modelClass::withoutGlobalScopes()
                ->whereIn('property_id', $propertyIds)
                ->pluck('id');

            if ($ownedIds->isEmpty()) {
                continue;
            }

            $paths = array_merge($paths, Document::query()
                ->where('documentable_type', $modelClass)
                ->whereIn('documentable_id', $ownedIds)
                ->pluck('file_path')
                ->all());
        }

        return array_values(array_unique(array_filter($paths)));
    }
}
