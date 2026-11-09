<?php

namespace App\Support;

use App\Services\Vault\DocumentCipher;
use App\Services\Vault\VaultException;
use Closure;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class DocumentStorage
{
    /**
     * Types servis en aperçu dans le navigateur. Tout le reste part en pièce
     * jointe : un fichier non affichable rendu « inline » s'exécuterait dans
     * notre origine.
     */
    public const INLINE_TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    public const ATTACHMENT_TYPES = [
        'heic' => 'image/heic',
        'zip' => 'application/zip',
        'csv' => 'text/csv',
        'txt' => 'text/plain',
    ];

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
     * Disque sur lequel LIRE un chemin : la racine courante, ou l'ancienne si le
     * fichier n'existe que là.
     *
     * ⚠️ Point de résolution UNIQUE, et c'est tout l'intérêt. Le repli hérité et le
     * coffre-fort se croisent ici : `VaultMigrator` ne balaie que la racine courante
     * et se contente de COMPTER les fichiers restés à l'ancienne, mais rien ne
     * garantit qu'ils y resteront en clair (restauration d'une sauvegarde,
     * rapatriement partiel, chiffrement hors application). Un chemin de lecture qui
     * connaîtrait le repli sans passer par le déchiffrement livrerait du binaire
     * chiffré sous un `Content-Type: application/pdf` ; l'inverse rendrait 404 un
     * justificatif d'avant Laravel 11. Les deux lectures — le contrôleur de service
     * et l'aperçu du champ d'upload — résolvent donc leur disque ici, puis
     * appliquent le coffre-fort au fichier trouvé, où qu'il soit.
     */
    public static function readDisk(string $path): FilesystemAdapter
    {
        return self::isLegacyOnly($path)
            ? self::legacyDisk()
            : Storage::disk('local');
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
        return fn (string $file): bool => auth()->check() && self::isOwnedBy($file, (int) auth()->id());
    }

    /**
     * Le chemin est-il dans le dossier de cet utilisateur ? À vérifier avant toute
     * lecture ou suppression d'un `file_path` lu en base : une ligne enregistrée avant
     * le correctif de GHSA-j4gm-g8m8-93x2 peut encore pointer chez un autre compte.
     */
    public static function isOwnedBy(mixed $path, int $userId): bool
    {
        return is_string($path)
            && str_starts_with($path, 'documents/' . $userId . '/')
            && ! str_contains($path, '..')
            && ! str_contains($path, "\0");
    }

    /**
     * Le chemin d'un CSV à importer vient de l'état Livewire, donc du navigateur.
     * Sans ce contrôle, le justificatif ou le FEC d'un autre compte serait lu et
     * renvoyé dans l'aperçu. On n'accepte qu'un fichier posé dans le dossier
     * d'import de l'utilisateur connecté (`ImportUpload::directoryFor()`).
     */
    public static function isImportUpload(mixed $path): bool
    {
        return is_string($path)
            && auth()->check()
            && preg_match('#^' . preg_quote(ImportUpload::directoryFor((int) auth()->id()), '#') . '/[^/\\\\]+$#', $path) === 1
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

    /**
     * Type MIME déduit de l'EXTENSION, jamais du contenu.
     *
     * Source unique partagée par le champ d'upload et le contrôleur de service :
     * déduire le type des octets ferait servir un HTML nommé « facture.pdf » en
     * text/html, exécuté dans notre origine. Et sur un fichier chiffré, la
     * déduction par le contenu ne renverrait de toute façon plus rien d'utile.
     */
    public static function mimeForExtension(?string $path): string
    {
        $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));

        return self::INLINE_TYPES[$extension]
            ?? self::ATTACHMENT_TYPES[$extension]
            ?? 'application/octet-stream';
    }

    public static function isInlineExtension(?string $path): bool
    {
        $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));

        return isset(self::INLINE_TYPES[$extension]);
    }

    /**
     * Branche un champ d'upload sur le coffre-fort.
     *
     * Un seul point d'écriture pour toute l'application : tant que les champs
     * passent par ici, aucun fichier ne peut atterrir en clair dans un coffre
     * activé.
     */
    public static function protect(BaseFileUpload $upload): BaseFileUpload
    {
        return $upload
            ->saveUploadedFileUsing(self::encryptOnSave())
            ->getUploadedFileUsing(self::describeStored())
            // ⚠️ Sans ces deux lignes, Filament retombe sur `describeStored()`
            // (« url » => null pour un fichier chiffré) : l'icône « ouvrir »/
            // « télécharger » de FilePond n'a alors rien à mettre dans son
            // `href`, et le clic échoue — c'était le bug « /expenses/1/[object
            // Object] ». `vaultAwareFileUrl()` répare les deux icônes d'un coup.
            ->getOpenableFileUrlUsing(self::vaultAwareFileUrl())
            ->getDownloadableFileUrlUsing(self::vaultAwareFileUrl())
            // ⚠️ `canWriteFor()` et non `filesAvailableFor()` : depuis la paire de
            // clés, ÉCRIRE ne demande que la clé publique. Griser le champ coffre
            // verrouillé revenait à interdire un dépôt parfaitement possible —
            // c'est le défaut d'ergonomie qui a motivé tout ce chantier.
            ->disabled(fn () => ! Vault::canWriteFor(auth()->user()));
    }

    /**
     * Lien d'ouverture/téléchargement d'un justificatif, chiffré ou non.
     *
     * Un fichier en CLAIR rend `null` : Filament retombe alors sur son URL
     * d'aperçu habituelle (celle que `describeStored()` calcule déjà) —
     * comportement strictement inchangé pour tout ce qui n'est pas scellé.
     *
     * Un fichier CHIFFRÉ ne peut plus être ouvert d'un clic direct : depuis que
     * le déchiffrement vit dans le navigateur, le serveur ne peut pas servir le
     * clair sans que l'utilisateur ait déverrouillé le coffre — et même
     * déverrouillé, `describeStored()` ne le tente plus (voir son commentaire).
     * Naviguer directement vers la route signée ne rendrait donc que du binaire
     * chiffré. Le lien pointe malgré tout vers cette route (c'est elle que
     * `vault.js#chiffre()` interroge en `?raw=1`), mais porte le nom du document
     * dans son FRAGMENT (`#vault=…`, jamais envoyé au serveur — un fragment ne
     * fait jamais partie d'une requête HTTP) : c'est ce marqueur que
     * `vault-unlock-hook` repère pour intercepter le clic et prendre le relais,
     * au lieu de laisser le navigateur naviguer vers du chiffré brut.
     */
    public static function vaultAwareFileUrl(): Closure
    {
        return static function (string $file, string | array | null $storedFileNames): ?string {
            $disk = self::readDisk($file);

            if (! $disk->exists($file)) {
                return null;
            }

            if (! app(DocumentCipher::class)->isEncryptedFile($disk->path($file))) {
                return null;
            }

            $name = (is_array($storedFileNames) ? ($storedFileNames[$file] ?? null) : $storedFileNames)
                ?? basename($file);

            return self::temporaryUrl($file) . '#vault=' . rawurlencode($name);
        };
    }

    /**
     * Écriture : chiffre le fichier déposé avant de le poser sur le disque.
     */
    /**
     * Écrit $source (chaîne d'octets ou flux) à $path en appliquant le coffre.
     *
     * ⚠️ POINT D'ÉCRITURE UNIQUE sous `documents/`. Avant lui, la logique de
     * chiffrement ne vivait que dans la closure Filament `encryptOnSave()` :
     * inaccessible depuis un service, elle était DUPLIQUÉE par `AttachDocument`
     * (outil MCP) et purement ignorée par `DocumentAnalysisService`, qui posait
     * donc un fichier EN CLAIR au milieu de fichiers scellés. Rien ne cassait,
     * aucun test ne rougissait, et la promesse du coffre était fausse pour le
     * seul fichier que l'utilisateur n'a pas déposé lui-même.
     *
     * Rend le chemin écrit. Ne notifie pas et ne lève pas de Halt : la garde
     * `Vault::blockedReasonFor()` appartient à l'appelant, qui seul sait
     * comment prévenir son utilisateur.
     *
     * @param  resource|string  $source
     */
    public static function putProtected(mixed $source, string $path, mixed $user = null, ?int $size = null): string
    {
        $user ??= auth()->user();
        $disk = Storage::disk('local');
        $publicKey = Vault::publicKeyFor($user);
        $key = null;

        if ($publicKey !== null) {
            // Paire de clés : une clé neuve par fichier, scellée à la clé publique.
            // Aucune phrase de passe, donc une écriture possible coffre VERROUILLÉ.
            $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        } else {
            $key = Vault::keyFor($user);
        }

        if ($key === null) {
            // Coffre non activé, ou coffre d'avant la paire et verrouillé :
            // fichier en clair, comportement d'origine.
            if (is_resource($source)) {
                $disk->writeStream($path, $source);
            } else {
                $disk->put($path, $source);
            }

            return $path;
        }

        if (! is_resource($source)) {
            $plain = $source;
            $source = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');
            fwrite($source, $plain);
            rewind($source);
            $size ??= strlen($plain);
            $closeSource = true;
        } else {
            $closeSource = false;
        }

        // Le chiffré transite par un flux temporaire (mémoire jusqu'à 2 Mo,
        // fichier au-delà) : on ne charge jamais un justificatif entier en
        // mémoire, et ce qui déborde sur le disque est déjà chiffré.
        $cipherStream = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');

        try {
            app(DocumentCipher::class)->encrypt(
                $source,
                $cipherStream,
                $key,
                Vault::keyVersionFor($user),
                $size,
                $publicKey,
            );

            rewind($cipherStream);
            $disk->writeStream($path, $cipherStream);
        } finally {
            $closeSource && is_resource($source) && fclose($source);
            is_resource($cipherStream) && fclose($cipherStream);
        }

        return $path;
    }

    public static function encryptOnSave(): Closure
    {
        return static function (BaseFileUpload $component, TemporaryUploadedFile $file): ?string {
            $user = auth()->user();

            if ($reason = Vault::blockedReasonFor($user)) {
                // ⚠️ Notifier AVANT de lever : une exception nue remonterait en
                // écran d'erreur Livewire, et un Halt silencieux laisserait
                // croire à l'utilisateur que son dépôt a été enregistré.
                Notification::make()
                    ->title('Dépôt impossible')
                    ->body($reason)
                    ->danger()
                    ->persistent()
                    ->send();

                throw new Halt;
            }

            $publicKey = Vault::publicKeyFor($user);

            if ($publicKey !== null) {
                // Paire de clés : une clé neuve par fichier, scellée à la clé
                // publique. Aucune phrase de passe, donc un dépôt possible coffre
                // verrouillé — et l'instance ne peut rien relire de ce qu'elle
                // vient d'écrire.
                $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
            } else {
                $key = Vault::keyFor($user);

                if ($key === null) {
                    // Coffre non activé, ou coffre d'avant la paire et verrouillé :
                    // comportement d'origine, fichier en clair.
                    return $component->saveUploadedFile($file);
                }
            }

            $path = trim(
                $component->getDirectory() . '/' . $component->getUploadedFileNameForStorage($file),
                '/',
            );

            $source = $file->readStream();

            if ($source === false || $source === null) {
                throw VaultException::unreadable($file->getClientOriginalName());
            }

            // Le chiffré transite par un flux temporaire (mémoire jusqu'à 2 Mo,
            // fichier au-delà) : on ne charge jamais un justificatif entier en
            // mémoire, et ce qui déborde sur le disque est déjà chiffré.
            $cipherStream = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');

            try {
                app(DocumentCipher::class)->encrypt(
                    $source,
                    $cipherStream,
                    $key,
                    Vault::keyVersionFor($user),
                    $file->getSize(),
                    $publicKey,
                );

                rewind($cipherStream);

                $component->getDisk()->writeStream($path, $cipherStream);
            } finally {
                is_resource($source) && fclose($source);
                is_resource($cipherStream) && fclose($cipherStream);
            }

            return $path;
        };
    }

    /**
     * Lecture : décrit un fichier déjà stocké pour l'aperçu du champ.
     *
     * Sans ça, `mimeType()` renvoie « application/octet-stream » sur un fichier
     * chiffré (le détecteur local inspecte les octets et ne se rabat pas sur
     * l'extension) et l'aperçu des photos de biens disparaît. Coffre verrouillé,
     * on renvoie un intitulé neutre plutôt que `null` : `null` ferait disparaître
     * le fichier du formulaire et l'utilisateur le redéposerait, croyant l'avoir
     * perdu.
     */
    public static function describeStored(): Closure
    {
        return static function (BaseFileUpload $component, string $file, string | array | null $storedFileNames): ?array {
            $disk = $component->getDisk();

            if (! $disk->exists($file)) {
                // Même repli que le contrôleur de service : un justificatif resté à
                // l'ancienne racine doit rester visible dans le formulaire, sans quoi
                // le champ paraît vide et l'utilisateur redépose sa pièce.
                if (! self::isLegacyOnly($file)) {
                    return null;
                }

                $disk = self::legacyDisk();
            }

            $name = (is_array($storedFileNames) ? ($storedFileNames[$file] ?? null) : $storedFileNames)
                ?? basename($file);

            $cipher = app(DocumentCipher::class);
            $absolute = $disk->path($file);
            $encrypted = $cipher->isEncryptedFile($absolute);

            if (! $encrypted) {
                return [
                    'name' => $name,
                    'size' => $disk->size($file),
                    'type' => $disk->mimeType($file) ?: self::mimeForExtension($file),
                    'url' => Str::sanitizeUrl(self::previewUrl($file)),
                ];
            }

            // ⚠️ Depuis que le déchiffrement se fait dans le NAVIGATEUR, le serveur
            // ne déverrouille plus jamais : `Vault::isUnlockedFor()` ne dit plus rien
            // de la capacité à lire. On rend donc toujours la même forme pour un
            // fichier chiffré, et c'est le navigateur qui décide.
            //
            // La taille du clair reste lisible sans aucune clé — elle voyage dans
            // l'en-tête — donc l'entrée garde une taille juste plutôt qu'un zéro
            // qui laisserait croire à un fichier vide.
            return [
                'name' => $name,
                'size' => $cipher->plainSize($absolute) ?? 0,
                'type' => self::mimeForExtension($file),
                // Pas d'URL d'aperçu : le serveur ne peut plus servir le clair. Le
                // cadenas posé par `vault-unlock-hook` prend le relais, va chercher
                // le chiffré et l'ouvre localement.
                'url' => null,
                'vaultEncrypted' => true,
                'vaultPath' => $file,
            ];
        };
    }

    /**
     * URL d'aperçu du champ : même durée que celle de Filament, pour ne pas
     * raccourcir la validité d'un formulaire resté ouvert.
     */
    private static function previewUrl(string $path): string
    {
        return (string) self::temporaryUrl(
            $path,
            (int) config('filament.temporary_file_url_expiry_minutes', 30),
        );
    }
}
