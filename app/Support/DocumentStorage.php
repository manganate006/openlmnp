<?php

namespace App\Support;

use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class DocumentStorage
{
    /**
     * Disque pointant sur l'ANCIENNE racine des fichiers privés, `storage/app`.
     *
     * Laravel 11 a déplacé la racine du disque `local` de `storage/app` vers
     * `storage/app/private`. Les `file_path` en base sont relatifs à cette racine :
     * les fichiers déposés avant la montée de version sont restés en place et ne sont
     * plus servis. Rien ne casse — les justificatifs disparaissent simplement de
     * l'interface, ce qui est la façon la plus discrète de perdre une pièce comptable.
     *
     * Un disque Flysystem plutôt qu'un `storage_path()` concaténé : on hérite ainsi de
     * ses garde-fous de traversée de chemin, qu'un `file_exists()` sur une chaîne
     * construite à la main n'aurait pas.
     *
     * ⚠️ L'ancienne racine CONTIENT la nouvelle. Ce disque ne vaut que pour des chemins
     * commençant par `documents/`, jamais pour balayer `storage/app` en entier.
     */
    public static function legacyDisk(): FilesystemAdapter
    {
        return Storage::build([
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
            'report' => false,
        ]);
    }

    /**
     * Le fichier est-il absent de la racine courante mais présent à l'ancienne ?
     *
     * Sert de repli de lecture pour les instances où la commande de migration
     * `openlmnp:migrate-document-storage` n'aura jamais été lancée.
     */
    public static function isLegacyOnly(string $path): bool
    {
        return ! Storage::disk('local')->exists($path)
            && self::legacyDisk()->exists($path);
    }

    /**
     * Retourne une closure pour le directory d'upload Filament.
     * Arborescence : documents/{user_id}/{type}
     */
    public static function directory(string $type): Closure
    {
        return fn () => 'documents/' . auth()->id() . '/' . $type;
    }

    /**
     * Retourne une closure pour nommer le fichier uploadé.
     * Format : {YYYY-MM-DD}_{description-slugifiée}.{ext}
     */
    public static function filename(string $dateField, string $nameField): Closure
    {
        return function (TemporaryUploadedFile $file, callable $get) use ($dateField, $nameField): string {
            $date = $get($dateField);
            if ($date instanceof \Carbon\Carbon || $date instanceof \DateTimeInterface) {
                $date = $date->format('Y-m-d');
            }
            $date = $date ?: now()->format('Y-m-d');

            $name = Str::slug($get($nameField) ?: 'document');
            $ext = $file->getClientOriginalExtension() ?: 'pdf';

            return "{$date}_{$name}.{$ext}";
        };
    }

    /**
     * Génère une URL signée temporaire pour un fichier privé.
     * Auth requise + vérification propriété (user_id dans le path).
     */
    public static function temporaryUrl(?string $path, int $minutes = 5): ?string
    {
        if (! $path) {
            return null;
        }

        return URL::temporarySignedRoute('documents.show', now()->addMinutes($minutes), ['path' => $path]);
    }
}
