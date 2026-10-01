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
     * Pour `preventFilePathTampering()` des FileUpload rangés par `directory()`.
     *
     * Le chemin d'un fichier déjà enregistré vient de l'état Livewire, donc du
     * navigateur. Sans ce contrôle, un chemin vers le dossier d'un autre
     * utilisateur est enregistré tel quel, puis signé et servi. La règle par
     * défaut de Filament ne suffit pas : elle refuse aussi les fichiers qu'on
     * vient de déposer dans un Repeater lié à une relation.
     */
    public static function belongsToCurrentUser(): Closure
    {
        return fn (string $file): bool => str_starts_with($file, 'documents/' . auth()->id() . '/')
            && ! str_contains($file, '..');
    }

    /** Dossier où les écrans d'import rangent le CSV déposé. */
    public const IMPORT_DIRECTORY = 'imports';

    /**
     * Le chemin d'un CSV à importer vient de l'état Livewire, donc du navigateur.
     * Sans ce contrôle, `documents/7/...` ou le FEC d'un autre compte serait lu
     * et renvoyé dans l'aperçu. On n'accepte qu'un fichier posé dans `imports/`.
     */
    public static function isImportUpload(mixed $path): bool
    {
        return is_string($path)
            && preg_match('#^' . self::IMPORT_DIRECTORY . '/[^/\\\\]+$#', $path) === 1
            && ! str_contains($path, '..');
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
