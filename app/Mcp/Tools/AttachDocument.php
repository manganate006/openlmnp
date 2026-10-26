<?php

namespace App\Mcp\Tools;

use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyWork;
use App\Support\DocumentStorage;
use App\Support\Vault;
use App\Services\Ai\Analysis\DocumentFile;
use App\Support\RemoteFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use RuntimeException;

#[Description('Attache un document justificatif (facture, reçu, devis) à une charge, un mobilier ou un poste de travaux. Trois modes d\'entrée mutuellement exclusifs : file_base64 (contenu encodé), file_path (chemin absolu sur le serveur, nécessite MCP_FILE_PATH_PREFIX), file_url (URL http/https téléchargée par le serveur). Le chemin de stockage est : documents/{user_id}/{type}/{filename}.')]
#[IsDestructive]
class AttachDocument extends Tool
{
    protected string $name = 'attach_document';

    /** Types de modèles supportés → classe Eloquent correspondante */
    private const SUPPORTED_TYPES = [
        'expense'   => Expense::class,
        'furniture' => Furniture::class,
        'work'      => PropertyWork::class,
    ];

    /** Extensions de fichiers autorisées */
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic'];

    /** Types réels acceptés pour chaque extension (détectés par finfo). */
    private const MIME_TYPES = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'heic' => ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'],
    ];

    /** Taille maximum du fichier décodé : 10 Mo */
    private const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;

    public function handle(Request $request): Response
    {
        // Un jeton d'API n'a pas de session interactive, et le transport stdio n'a
        // même pas d'utilisateur qui pourrait saisir quoi que ce soit : il n'existe
        // aucun moyen d'obtenir la phrase de passe du coffre-fort. On refuse
        // explicitement plutôt que d'écrire un fichier en clair au milieu de
        // fichiers chiffrés.
        // Depuis la paire de clés, l'API PEUT déposer : chiffrer ne demande que la
        // clé publique. L'agent écrit donc un justificatif qu'il ne pourra jamais
        // relire — c'est exactement la propriété recherchée. Seul un coffre
        // d'avant la paire, qui exige la DEK dans les deux sens, refuse encore.
        if (Vault::isEnabledFor(Auth::user()) && ! Vault::canWriteFor(Auth::user())) {
            return Response::error(
                'Coffre-fort activé sur un schéma antérieur à la paire de clés : le dépôt par '
                . 'l\'API n\'est pas possible sans votre phrase de passe. Déposez ce document '
                . 'depuis l\'interface web, ou terminez la migration du coffre.'
            );
        }

        $typeList = implode(',', array_keys(self::SUPPORTED_TYPES));

        $validated = $request->validate([
            'type'          => "required|in:{$typeList}",
            'record_id'     => 'required|integer',
            'label'         => 'required|string|max:255',
            'file_base64'   => 'nullable|string',
            'file_path'     => 'nullable|string|max:1024',
            'file_url'      => 'nullable|url|max:2048',
            'filename'      => 'nullable|string|max:255',
            'amount'        => 'nullable|numeric|min:0',
            'document_date' => 'nullable|date_format:Y-m-d',
        ]);

        // Exactly one file source must be provided
        $sources = array_filter([
            'file_base64' => $validated['file_base64'] ?? null,
            'file_path'   => $validated['file_path'] ?? null,
            'file_url'    => $validated['file_url'] ?? null,
        ]);

        if (count($sources) === 0) {
            return Response::error('Un des paramètres file_base64, file_path ou file_url est requis.');
        }

        if (count($sources) > 1) {
            return Response::error('Les paramètres file_base64, file_path et file_url sont mutuellement exclusifs.');
        }

        // Resolve the polymorphic model class
        $modelClass = self::SUPPORTED_TYPES[$validated['type']];

        /** @var Expense|Furniture|PropertyWork $record */
        $record = $modelClass::findOrFail($validated['record_id']);

        // Verify ownership: the record's property must belong to the authenticated user
        Property::findOrFail($record->property_id);

        // Resolve binary content and filename from the chosen source.
        // Les contrôles (préfixe local, anti-SSRF) vivent dans App\Support\RemoteFile,
        // partagé avec analyze_document : une seule implémentation à auditer.
        try {
            [$decoded, $filename] = RemoteFile::resolve($sources, $validated['filename'] ?? null);
        } catch (RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        if (strlen($decoded) > self::MAX_FILE_SIZE_BYTES) {
            return Response::error('Le fichier dépasse la taille maximum autorisée (10 Mo).');
        }

        // Sanitize filename and validate extension
        $filename  = basename($filename);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, strict: true)) {
            $allowed = implode(', ', self::ALLOWED_EXTENSIONS);
            return Response::error("Extension de fichier non autorisée. Extensions acceptées : {$allowed}.");
        }

        // Une extension est DÉCLARATIVE : n'importe quel contenu peut s'appeler
        // « facture.pdf ». On vérifie donc le type réel, sur les octets — sans
        // quoi un document HTML piégé finirait servi depuis notre propre
        // origine par DocumentController.
        if (! $this->hasAllowedContent($decoded, $extension)) {
            return Response::error(
                'Le contenu du fichier ne correspond pas à son extension. '
                . 'Formats acceptés : PDF, JPEG, PNG, WebP, HEIC.'
            );
        }

        // Build a safe, unique filename to avoid collisions
        $safeBasename = Str::slug(pathinfo($filename, PATHINFO_FILENAME)) ?: 'document';
        $safeFilename = now()->format('Y-m-d') . '_' . $safeBasename . '_' . Str::random(8) . '.' . $extension;

        // Storage path: documents/{user_id}/{type}/{filename}
        $userId      = Auth::id();
        $storagePath = "documents/{$userId}/{$validated['type']}/{$safeFilename}";

        // Persist file to the default filesystem disk (local / s3 / etc.)
        //
        // ⚠️ Coffre activé : chiffrer AVANT d'écrire. Lever le refus de l'API sans
        // cette ligne posait un fichier en clair au milieu de fichiers chiffrés —
        // c'est-à-dire pire que le refus qu'on venait de retirer. Une clé neuve par
        // fichier, scellée à la clé publique : aucune phrase de passe n'est requise,
        // et l'API ne peut rien relire de ce qu'elle vient d'écrire.
        //
        // ⚠️ Cette logique était RECOPIÉE ici (encryptString + Storage::put), en
        // double de la closure Filament. Deux copies d'une règle de chiffrement
        // dérivent : celle qu'on oublie de suivre pose un fichier en clair, et
        // rien ne le signale. `DocumentStorage::putProtected()` est désormais le
        // point d'écriture unique — Filament, MCP et l'analyse IA y passent tous.
        DocumentStorage::putProtected($decoded, $storagePath, Auth::user());

        // Determine the next sort_order for this documentable
        $maxSortOrder = $record->documents()->max('sort_order') ?? -1;

        // Convert optional amount from euros to centimes
        $amountCents = isset($validated['amount'])
            ? (int) bcmul((string) $validated['amount'], '100', 0)
            : null;

        // Create the polymorphic Document record
        $document = $record->documents()->create([
            'label'         => $validated['label'],
            'amount'        => $amountCents,
            'document_date' => $validated['document_date'] ?? null,
            'file_path'     => $storagePath,
            'sort_order'    => $maxSortOrder + 1,
        ]);

        return Response::json([
            'success'       => true,
            'document_id'   => $document->id,
            'label'         => $document->label,
            'file_path'     => $document->file_path,
            'amount_eur'    => $document->amount_euros,
            'document_date' => $document->document_date?->toDateString(),
            'sort_order'    => $document->sort_order,
            'attached_to'   => [
                'type'      => $validated['type'],
                'record_id' => $record->id,
            ],
        ]);
    }

    /**
     * Le contenu correspond-il vraiment à un format d'image ou de PDF accepté ?
     *
     * La liste de référence est celle de l'analyse IA
     * (App\Services\Ai\Analysis\DocumentFile::ACCEPTED_MIME), augmentée du HEIC,
     * historiquement accepté ici comme pièce jointe même si aucun modèle de
     * vision ne sait le lire.
     */
    private function hasAllowedContent(string $contents, string $extension): bool
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        $accepted = array_keys(DocumentFile::ACCEPTED_MIME);

        // Les photos d'iPhone : refusées à l'analyse, tolérées en pièce jointe.
        $accepted[] = 'image/heic';
        $accepted[] = 'image/heif';

        if (! in_array($mime, $accepted, strict: true)) {
            return false;
        }

        // Cohérence extension / contenu : un PDF ne doit pas s'appeler « .png ».
        $famille = str_starts_with($mime, 'image/') ? 'image' : 'pdf';

        return $famille === ($extension === 'pdf' ? 'pdf' : 'image');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type'          => $schema->string('Type d\'enregistrement cible : expense, furniture, work')->required(),
            'record_id'     => $schema->integer('Identifiant de la charge, du mobilier ou du poste de travaux')->required(),
            'label'         => $schema->string('Libellé du document (ex: Facture plombier, Reçu IKEA)')->required(),
            'file_base64'   => $schema->string('Contenu du fichier encodé en base64 (PDF, JPG, PNG, WebP, HEIC — max 10 Mo). Mutuellement exclusif avec file_path et file_url.')->nullable(),
            'file_path'     => $schema->string('Chemin absolu du fichier sur le serveur (nécessite MCP_FILE_PATH_PREFIX configuré). Mutuellement exclusif avec file_base64 et file_url.')->nullable(),
            'file_url'      => $schema->string('URL http/https du fichier à télécharger par le serveur. Mutuellement exclusif avec file_base64 et file_path.')->nullable(),
            'filename'      => $schema->string('Nom du fichier avec extension (ex: facture.pdf). Déduit automatiquement depuis file_path ou file_url si absent.')->nullable(),
            'amount'        => $schema->number('Montant indiqué sur le document en euros (optionnel)')->nullable(),
            'document_date' => $schema->string('Date du document au format Y-m-d (optionnel)')->nullable(),
        ];
    }
}
