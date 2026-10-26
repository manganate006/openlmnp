<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyWork;
use App\Models\User;
use App\Services\Vault\DocumentCipher;
use App\Services\Vault\VaultException;
use App\Support\DocumentStorage;
use App\Support\Vault;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class DocumentExportService
{
    private const TYPE_MAP = [
        Expense::class      => 'charges',
        Furniture::class    => 'mobilier',
        PropertyWork::class => 'travaux',
    ];

    /**
     * Manifeste d'export : la liste des justificatifs et leur place dans l'archive,
     * SANS aucune clé.
     *
     * ⚠️ Depuis que le déchiffrement vit dans le navigateur, le serveur ne peut plus
     * assembler l'archive : une archive lisible contient du clair, et il n'en a
     * plus. Il rend donc de quoi la construire, et c'est le client qui la bâtit —
     * c'est le seul découpage qui tienne la promesse « la clé ne quitte pas le
     * poste ».
     *
     * @return array<int, array{url: string, chemin: string}>
     */
    public function manifest(User $user, ?int $year = null, ?string $type = null): array
    {
        $documents = $this->getDocuments($user, $year, $type);
        $usedNames = [];
        $entrees = [];

        foreach ($documents as $document) {
            // Un file_path enregistré avant GHSA-j4gm-g8m8-93x2 peut viser le dossier d'un
            // autre compte : il n'entre pas dans l'archive.
            if (! DocumentStorage::isOwnedBy($document->file_path, $user->id)) {
                continue;
            }

            $docYear = $this->getDocumentYear($document);
            $typeFolder = self::TYPE_MAP[$document->documentable_type] ?? 'autre';
            $ext = pathinfo($document->file_path, PATHINFO_EXTENSION);
            $date = $document->document_date?->format('Y-m-d') ?? 'sans-date';
            $base = $date . '_' . Str::slug($document->label ?: 'justificatif');

            $nom = $base . ($ext ? '.' . $ext : '');
            $i = 1;

            // Deux pièces peuvent porter le même libellé le même jour : sans ce
            // compteur, la seconde écraserait la première dans l'archive.
            while (isset($usedNames[$docYear . '/' . $typeFolder . '/' . $nom])) {
                $nom = $base . '-' . (++$i) . ($ext ? '.' . $ext : '');
            }

            $chemin = $docYear . '/' . $typeFolder . '/' . $nom;
            $usedNames[$chemin] = true;

            $entrees[] = [
                // ⚠️ URL SIGNÉE : la route porte le middleware `signed`. Un
                // `route()` nu rendrait un lien que le navigateur recevrait en 403,
                // et l'export échouerait entrée par entrée sans dire pourquoi.
                // Une heure : le temps d'assembler une archive, pas plus.
                'url' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                    'documents.show',
                    now()->addHour(),
                    ['path' => $document->file_path],
                ),
                'chemin' => $chemin,
            ];
        }

        return $entrees;
    }

    /**
     * Export all documents as a ZIP file, organized by year and type.
     *
     * @return array{path: string, count: int}
     */
    public function exportZip(User $user, ?int $year = null, ?string $type = null): array
    {
        $cipher = app(DocumentCipher::class);
        $vaultKey = Vault::keyFor($user);

        // Coffre-fort activé mais verrouillé : mieux vaut refuser que produire une
        // archive silencieusement amputée des justificatifs chiffrés.
        if (Vault::isEnabledFor($user) && $vaultKey === null) {
            throw VaultException::locked();
        }

        $documents = $this->getDocuments($user, $year, $type);

        if ($documents->isEmpty()) {
            return ['path' => null, 'count' => 0];
        }

        $zipPath = 'temp/justificatifs-' . $user->id . '-' . now()->format('Ymd-His') . '.zip';
        $zipFullPath = Storage::path($zipPath);

        // Ensure temp directory exists
        Storage::makeDirectory('temp');

        $zip = new ZipArchive();
        if ($zip->open($zipFullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Impossible de créer le fichier ZIP.');
        }

        $usedNames = [];
        $count = 0;

        // Fichiers déchiffrés le temps de l'archivage. ZipArchive ne lit ses
        // sources qu'au close() : on ne peut les supprimer qu'après — mais il
        // faut le faire dans TOUS les cas, d'où le finally plus bas.
        $temporaries = [];
        $completed = false;

        try {
            foreach ($documents as $document) {
                // Un file_path enregistré avant GHSA-j4gm-g8m8-93x2 peut viser le dossier
                // d'un autre compte : il n'entre pas dans l'archive.
                if (! DocumentStorage::isOwnedBy($document->file_path, $user->id)) {
                    continue;
                }

                // ⚠️ La résolution du disque précède le déchiffrement, et ce n'est pas
                // un détail d'ordre : un justificatif déposé avant que Laravel 11 ne
                // déplace le disque `local` vers `storage/app/private` vit encore sous
                // l'ancienne racine, et il peut par ailleurs être chiffré. Résoudre
                // après déchiffrement — ou à côté — ferait tomber ce cas entre les deux.
                // Sans le repli, `file_exists()` rend false et le `continue` écarte le
                // fichier **en silence** : l'archive est incomplète sans que rien ne le
                // dise, le pire cas pour un export d'archivage.
                $filePath = DocumentStorage::isLegacyOnly($document->file_path)
                    ? DocumentStorage::legacyDisk()->path($document->file_path)
                    : Storage::path($document->file_path);

                if (! file_exists($filePath)) {
                    continue;
                }

                if ($vaultKey !== null && $cipher->isEncryptedFile($filePath)) {
                    try {
                        $filePath = $cipher->decryptToTemporaryFile($filePath, $vaultKey);
                        $temporaries[] = $filePath;
                    } catch (VaultException) {
                        // Fichier altéré : on l'omet plutôt que d'écrire du chiffré
                        // illisible dans une archive présentée comme exploitable.
                        continue;
                    }
                }

                $docYear = $this->getDocumentYear($document);
                $typeFolder = self::TYPE_MAP[$document->documentable_type] ?? 'autre';
                $ext = pathinfo($document->file_path, PATHINFO_EXTENSION);
                $date = $document->document_date?->format('Y-m-d')
                    ?? $this->getParentDate($document)?->format('Y-m-d')
                    ?? 'sans-date';
                $slug = Str::slug($document->label);
                $baseName = "{$date}_{$slug}.{$ext}";

                $zipEntryPath = "justificatifs/{$docYear}/{$typeFolder}/{$baseName}";

                // Deduplicate
                if (isset($usedNames[$zipEntryPath])) {
                    $usedNames[$zipEntryPath]++;
                    $zipEntryPath = "justificatifs/{$docYear}/{$typeFolder}/{$date}_{$slug}-" . $usedNames[$zipEntryPath] . ".{$ext}";
                } else {
                    $usedNames[$zipEntryPath] = 1;
                }

                $zip->addFile($filePath, $zipEntryPath);
                $count++;
            }

            $zip->close();
            $completed = true;
        } finally {
            if (! $completed) {
                // Interruption en cours de route : l'archive partielle contient des
                // justificatifs DÉCHIFFRÉS et vit dans storage/, qui est répliqué
                // toutes les 30 min vers la réplique de secours. Elle ne doit pas
                // survivre à l'échec.
                @$zip->close();
                @unlink($zipFullPath);
            }

            // Les clairs temporaires, eux, doivent disparaître dans tous les cas —
            // sans ce finally, une exception les laissait dans /tmp jusqu'au
            // redémarrage du conteneur, sur le serveur même dont le coffre protège.
            foreach ($temporaries as $temporary) {
                @unlink($temporary);
            }
        }

        if ($count === 0) {
            @unlink($zipFullPath);
            return ['path' => null, 'count' => 0];
        }

        return ['path' => $zipPath, 'count' => $count];
    }

    private function getDocuments(User $user, ?int $year, ?string $type): \Illuminate\Support\Collection
    {
        // Get all property IDs belonging to the user
        $propertyIds = Property::where('user_id', $user->id)->pluck('id');

        if ($propertyIds->isEmpty()) {
            return collect();
        }

        // Build a list of documentable type/id pairs
        $query = Document::query();

        $typeFilter = match ($type) {
            'expense'   => [Expense::class],
            'furniture' => [Furniture::class],
            'work'      => [PropertyWork::class],
            default     => [Expense::class, Furniture::class, PropertyWork::class],
        };

        $query->whereIn('documentable_type', $typeFilter);

        // Filter by entities belonging to user's properties
        $query->where(function ($q) use ($propertyIds, $typeFilter) {
            foreach ($typeFilter as $modelClass) {
                $table = (new $modelClass)->getTable();
                $ids = \Illuminate\Support\Facades\DB::table($table)
                    ->whereIn('property_id', $propertyIds)
                    ->pluck('id');

                $q->orWhere(function ($sub) use ($modelClass, $ids) {
                    $sub->where('documentable_type', $modelClass)
                        ->whereIn('documentable_id', $ids);
                });
            }
        });

        if ($year) {
            $query->where(function ($q) use ($year) {
                $q->whereYear('document_date', $year)
                    ->orWhereNull('document_date');
            });
        }

        return $query->with('documentable')->get();
    }

    private function getDocumentYear(Document $document): string
    {
        if ($document->document_date) {
            return (string) $document->document_date->year;
        }

        $parentDate = $this->getParentDate($document);
        return $parentDate ? (string) $parentDate->year : 'sans-annee';
    }

    private function getParentDate(Document $document): ?\Carbon\Carbon
    {
        $parent = $document->documentable;

        if ($parent instanceof Expense) {
            return $parent->expense_date;
        }
        if ($parent instanceof Furniture) {
            return $parent->purchase_date;
        }
        if ($parent instanceof PropertyWork) {
            return $parent->work_date;
        }

        return null;
    }
}
