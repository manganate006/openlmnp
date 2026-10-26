<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Fichiers déposés pour analyse par l'IA, le temps de la relecture.
 *
 * ⚠️ Ces fichiers transitent EN CLAIR, et c'est irréductible : le service d'analyse doit
 * bien lire quelque chose. Ce qui ne l'est pas, c'est qu'ils y restent. Trois littéraux
 * `'ai-staging'` vivaient dans trois écrans différents, sans propriétaire commun, sans
 * purge, et sans que personne ne sache qu'ils existaient — une VRAIE LIASSE FISCALE y a
 * séjourné du 06/09 au 21/09 sur la préproduction, orpheline de toute ligne en base.
 *
 * Même patron que {@see ImportUpload}, et pour la même raison : un répertoire sous le
 * propriétaire, une suppression dès consommation, une purge pour les abandons.
 *
 * ⚠️ Et une exclusion qui n'est pas facultative : {@see \App\Services\Vault\VaultMigrator}
 * balaie tout `documents/{user_id}/`. Sans exclure ce répertoire, activer son coffre pendant
 * qu'une analyse est en relecture CHIFFRE le fichier sous les pieds de l'utilisateur —
 * l'aperçu tombe, et « Enregistrer » range du chiffré déjà chiffré.
 */
class AiStagingUpload
{
    /** Sous-répertoire sous `documents/{user_id}/`. */
    public const SUBDIRECTORY = 'ai-staging';

    /** Répertoire d'un utilisateur donné, relatif à la racine du disque privé. */
    public static function directoryFor(int|string $userId): string
    {
        return 'documents/' . $userId . '/' . self::SUBDIRECTORY;
    }

    /**
     * Supprime le fichier de transit, l'analyse une fois consommée.
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
