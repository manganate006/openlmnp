<?php

namespace App\Services\Vault;

use RuntimeException;

/**
 * Erreur du coffre-fort.
 *
 * Le message est destiné à être affiché tel quel à l'utilisateur : il est donc
 * rédigé en français et ne révèle jamais rien du contenu protégé (ni taille, ni
 * type, ni fragment de clair).
 */
class VaultException extends RuntimeException
{
    public static function wrongPassphrase(): self
    {
        return new self('Phrase de passe incorrecte.');
    }

    public static function locked(): self
    {
        return new self('Coffre-fort verrouillé : saisissez votre phrase de passe pour accéder à vos justificatifs.');
    }

    public static function corrupted(): self
    {
        return new self("Ce fichier chiffré est altéré ou incomplet : il n'a pas pu être déchiffré.");
    }

    public static function notEncrypted(): self
    {
        return new self("Ce fichier n'est pas chiffré par le coffre-fort.");
    }

    public static function unsupportedFormat(int $version): self
    {
        return new self("Ce fichier a été chiffré par une version plus récente du coffre-fort (format {$version}).");
    }

    public static function unreadable(string $path): self
    {
        return new self("Fichier illisible : {$path}");
    }
}
