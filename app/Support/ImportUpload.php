<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Fichiers déposés pour import (CSV Airbnb, tableurs de cabinet).
 *
 * ⚠️ Ces fichiers sont des données personnelles à part entière : un export Airbnb porte les
 * NOMS DES VOYAGEURS, leurs dates de séjour et les montants versés. Ils tombaient pourtant à la
 * RACINE du disque privé (`storage/app/private/<ulid>.csv`), sans lien en base, sans propriétaire
 * identifiable, et rien ne les supprimait jamais.
 *
 * Deux corrections, indissociables :
 *
 * 1. **Un répertoire, sous le propriétaire** — `documents/{user_id}/imports`. C'est l'arborescence
 *    que balaie le coffre-fort des justificatifs : rangés là, ces fichiers entrent dans son
 *    périmètre au lieu d'être la pièce en clair posée juste à côté.
 * 2. **Une durée de vie** — supprimés dès l'import terminé (`discard()`), et la commande
 *    `openlmnp:cleanup-imports` ramasse quotidiennement les aperçus abandonnés en route.
 */
class ImportUpload
{
    /** Sous-répertoire sous `documents/{user_id}/`. */
    public const SUBDIRECTORY = 'imports';

    /** Répertoire d'un utilisateur donné, relatif à la racine du disque privé. */
    public static function directoryFor(int|string $userId): string
    {
        return 'documents/' . $userId . '/' . self::SUBDIRECTORY;
    }

    /**
     * Supprime le fichier déposé, l'import une fois consommé.
     *
     * ⚠️ Le chemin vient de l'état Livewire, donc du navigateur : il est vérifié contre le
     * répertoire de l'utilisateur CONNECTÉ avant toute suppression. Sans cette garde, une
     * valeur forgée ferait de ce nettoyage une suppression de fichier arbitraire.
     */
    public static function discard(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $prefix = self::directoryFor((string) auth()->id()) . '/';

        if (! str_starts_with($path, $prefix) || str_contains($path, '..')) {
            return;
        }

        Storage::disk('local')->delete($path);
    }
}
