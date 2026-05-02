<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class DocumentStorage
{
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
