<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\User;
use App\Services\Csv\DossierArchive;
use App\Support\DocumentStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Exporte le dossier complet d'un utilisateur : JSON versionné + justificatifs.
 *
 * Répond à la migration auto-hébergé ↔ cloud, et à une promesse plus large : sur un
 * logiciel AGPL, pouvoir partir avec ses données n'est pas un argument commercial, c'est
 * la condition de la confiance. Encore faut-il que ce soit outillé plutôt que documenté.
 */
class ExportDossierCommand extends Command
{
    protected $signature = 'openlmnp:export-dossier
                            {email : Adresse e-mail du compte à exporter}
                            {--output= : Chemin du fichier JSON (défaut : storage/app/private/exports/)}
                            {--documents : Copie aussi les justificatifs à côté du JSON}';

    protected $description = 'Exporte le dossier complet d\'un utilisateur (JSON versionné + justificatifs)';

    public function handle(DossierArchive $archive): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("Aucun compte pour {$this->argument('email')}.");

            return self::FAILURE;
        }

        $payload = $archive->export($user);

        $path = $this->option('output')
            ?: storage_path('app/private/exports/dossier-' . $user->id . '-' . now()->format('Ymd-His') . '.json');

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error("Impossible de créer le dossier {$directory}.");

            return self::FAILURE;
        }

        // JSON_UNESCAPED_UNICODE : sans lui les accents partent en é et l'archive
        // devient illisible pour un humain, ce qui est la moitié de son intérêt.
        file_put_contents($path, json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        $counts = [];
        foreach ($payload['properties'] as $property) {
            foreach (['components', 'works', 'furniture', 'incomes', 'expenses', 'loans'] as $key) {
                $counts[$key] = ($counts[$key] ?? 0) + count($property[$key]);
            }
        }

        $this->info("Dossier exporté : {$path}");
        $this->table(
            ['Biens', 'Composants', 'Travaux', 'Mobilier', 'Recettes', 'Charges', 'Emprunts'],
            [[
                count($payload['properties']),
                $counts['components'] ?? 0,
                $counts['works'] ?? 0,
                $counts['furniture'] ?? 0,
                $counts['incomes'] ?? 0,
                $counts['expenses'] ?? 0,
                $counts['loans'] ?? 0,
            ]],
        );

        if ($this->option('documents')) {
            $copied = $this->copyDocuments($user, $directory);
            $this->info("{$copied} justificatif(s) copié(s) dans {$directory}/documents/.");
        }

        $this->newLine();
        $this->line('  Ne sont PAS exportés : exercices fiscaux (totaux figés, à recalculer après');
        $this->line('  import), écritures comptables (dérivées), mot de passe et jetons.');

        return self::SUCCESS;
    }

    /**
     * Recopie les justificatifs à côté du JSON.
     *
     * ⚠️ `documents` ne porte pas de `user_id` : l'appartenance se lit par la relation
     * polymorphe vers la charge, le meuble ou les travaux — eux-mêmes rattachés au bien.
     * Un balayage naïf du disque exporterait les fichiers de tous les comptes.
     */
    private function copyDocuments(User $user, string $directory): int
    {
        $target = $directory . '/documents';

        if (! is_dir($target) && ! mkdir($target, 0775, true) && ! is_dir($target)) {
            return 0;
        }

        $propertyIds = $user->properties()->withoutGlobalScopes()->pluck('id');
        $copied = 0;

        $owners = [
            \App\Models\Expense::class      => \App\Models\Expense::withoutGlobalScopes()->whereIn('property_id', $propertyIds)->pluck('id'),
            \App\Models\Furniture::class    => \App\Models\Furniture::withoutGlobalScopes()->whereIn('property_id', $propertyIds)->pluck('id'),
            \App\Models\PropertyWork::class => \App\Models\PropertyWork::withoutGlobalScopes()->whereIn('property_id', $propertyIds)->pluck('id'),
        ];

        foreach ($owners as $type => $ids) {
            $documents = Document::where('documentable_type', $type)
                ->whereIn('documentable_id', $ids)
                ->get();

            foreach ($documents as $document) {
                if (! $document->file_path) {
                    continue;
                }

                // ⚠️ Repli sur l'ancienne racine `storage/app`, comme le contrôleur de
                // documents depuis la v1.4.1. Laravel 11 a déplacé le disque `local` vers
                // `storage/app/private` : un justificatif déposé avant cette montée de
                // version est INVISIBLE au disque courant. Sans ce repli, l'archive du
                // dossier les sautait un par un — en silence, et en annonçant fièrement le
                // nombre de fichiers copiés. Exporter son dossier pour changer d'instance
                // et y perdre ses pièces les plus anciennes est le pire moment pour
                // découvrir cette dette.
                $disk = DocumentStorage::isLegacyOnly($document->file_path)
                    ? DocumentStorage::legacyDisk()
                    : Storage::disk();

                if (! $disk->exists($document->file_path)) {
                    $this->warn("  justificatif introuvable, ignoré : {$document->file_path}");

                    continue;
                }

                $destination = $target . '/' . basename($document->file_path);

                if (@copy($disk->path($document->file_path), $destination)) {
                    $copied++;
                }
            }
        }

        return $copied;
    }
}
