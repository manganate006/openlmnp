<?php

namespace App\Services\Ai\Analysis;

use App\Services\Ai\AiClient;
use App\Services\Vault\DocumentCipher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Fichier à analyser : contenu, nom, type réel, et fabrication de la partie de
 * message multimodale.
 *
 * Le type est déterminé par `finfo` sur les OCTETS, jamais par l'extension
 * (CDC § 4.9) : une extension est déclarative, un utilisateur peut renommer
 * n'importe quoi en `.pdf`.
 */
final class DocumentFile
{
    /** 10 Mo, comme DocumentsSection, AirbnbImportService et AttachDocument. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Types réellement analysables par un modèle de vision. */
    public const ACCEPTED_MIME = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private function __construct(
        public readonly string $contents,
        public readonly string $filename,
        public readonly string $mimeType,
    ) {}

    /**
     * Construit depuis des octets déjà en mémoire.
     *
     * @throws RuntimeException Message français, destiné à l'utilisateur.
     */
    public static function fromContents(string $contents, string $filename): self
    {
        if ($contents === '') {
            throw new RuntimeException('Le fichier est vide.');
        }

        if (strlen($contents) > self::MAX_BYTES) {
            throw new RuntimeException('Le fichier dépasse la taille maximale autorisée (10 Mo).');
        }

        // ⚠️ AVANT le finfo, et c'est tout l'intérêt. Un justificatif scellé par le
        // coffre-fort n'est pas un fichier « d'un format non pris en charge » : son
        // format est parfait, c'est le serveur qui ne peut PLUS le lire — et c'est
        // exactement ce que le coffre promet, pas une panne. Laisser le finfo
        // trancher renvoyait l'utilisateur chercher un problème de fichier qui
        // n'existe pas, sur une application qui venait de tenir parole.
        if (app(DocumentCipher::class)->isEncryptedContent($contents)) {
            throw new RuntimeException(
                'Ce justificatif est scellé dans votre coffre-fort : le serveur ne peut plus le lire, '
                . 'et c\'est précisément ce que le coffre garantit. Pour le faire analyser, '
                . 'redéposez le fichier d\'origine depuis votre ordinateur.'
            );
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        if (! array_key_exists($mime, self::ACCEPTED_MIME)) {
            throw new RuntimeException(self::unsupportedMessage($mime, $filename));
        }

        return new self($contents, basename($filename), $mime);
    }

    /** Fichier déposé par Filament (chemin sur le disque par défaut) ou UploadedFile. */
    public static function fromUpload(UploadedFile|string $file): self
    {
        if ($file instanceof UploadedFile) {
            $contents = (string) file_get_contents($file->getRealPath());

            return self::fromContents($contents, $file->getClientOriginalName() ?: 'document');
        }

        $disk = Storage::disk();

        if (! $disk->exists($file)) {
            throw new RuntimeException('Fichier introuvable — veuillez le déposer à nouveau.');
        }

        return self::fromContents((string) $disk->get($file), basename($file));
    }

    /** Message d'aide explicite : le HEIC est le refus le plus fréquent. */
    private static function unsupportedMessage(string $mime, string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($mime === 'image/heic' || $mime === 'image/heif' || in_array($extension, ['heic', 'heif'], strict: true)) {
            // Normalement converti en JPEG par le navigateur avant l'envoi ; ce
            // message ne s'affiche que si cette conversion n'a pas eu lieu
            // (JavaScript désactivé, dépôt par l'API MCP, navigateur très ancien).
            return 'Les photos au format HEIC (iPhone) ne sont pas lisibles par le modèle et votre '
                . 'navigateur n\'a pas pu la convertir. Convertissez la photo en JPEG avant de la déposer.';
        }

        return 'Format non pris en charge : déposez un PDF, un JPEG, un PNG ou un WebP.';
    }

    public function isPdf(): bool
    {
        return $this->mimeType === 'application/pdf';
    }

    public function extension(): string
    {
        return self::ACCEPTED_MIME[$this->mimeType] ?? 'bin';
    }

    public function base64(): string
    {
        return base64_encode($this->contents);
    }

    /**
     * Partie de message multimodale : `file` pour un PDF (plugin OpenRouter
     * `file-parser`, moteur explicite ajouté par AiClient), `image_url` sinon.
     *
     * @return array<string, mixed>
     */
    public function messagePart(): array
    {
        return $this->isPdf()
            ? AiClient::filePart($this->filename, $this->base64())
            : AiClient::imagePart($this->mimeType, $this->base64());
    }
}
