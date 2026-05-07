<?php

namespace App\Mcp\Tools;

use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyWork;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

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
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'html'];

    /** Taille maximum du fichier décodé : 10 Mo */
    private const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;

    public function handle(Request $request): Response
    {
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

        // Resolve binary content and filename from the chosen source
        $resolved = match (true) {
            isset($sources['file_base64']) => $this->resolveBase64($sources['file_base64'], $validated['filename'] ?? null),
            isset($sources['file_path'])   => $this->resolveFilePath($sources['file_path'], $validated['filename'] ?? null),
            default                        => $this->resolveFileUrl($sources['file_url'], $validated['filename'] ?? null),
        };

        if ($resolved instanceof Response) {
            return $resolved;
        }

        [$decoded, $filename] = $resolved;

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

        // Build a safe, unique filename to avoid collisions
        $safeBasename = Str::slug(pathinfo($filename, PATHINFO_FILENAME)) ?: 'document';
        $safeFilename = now()->format('Y-m-d') . '_' . $safeBasename . '_' . Str::random(8) . '.' . $extension;

        // Storage path: documents/{user_id}/{type}/{filename}
        $userId      = Auth::id();
        $storagePath = "documents/{$userId}/{$validated['type']}/{$safeFilename}";

        // Persist file to the default filesystem disk (local / s3 / etc.)
        Storage::put($storagePath, $decoded);

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

    /** Décoder le contenu base64 fourni directement. */
    private function resolveBase64(string $base64, ?string $filename): array|Response
    {
        $decoded = base64_decode($base64, strict: true);

        if ($decoded === false) {
            return Response::error('Le contenu base64 fourni est invalide.');
        }

        return [$decoded, $filename ?? 'document'];
    }

    /** Lire un fichier depuis un chemin absolu autorisé sur le serveur. */
    private function resolveFilePath(string $filePath, ?string $filename): array|Response
    {
        $prefix = config('mcp.file_path_prefix');

        if (empty($prefix)) {
            return Response::error('file_path non disponible : MCP_FILE_PATH_PREFIX n\'est pas configuré sur ce serveur.');
        }

        // Normaliser et vérifier le préfixe pour éviter les traversals
        $realPrefix = realpath($prefix);
        $realPath   = realpath($filePath);

        if ($realPath === false || $realPrefix === false) {
            return Response::error('Le chemin de fichier fourni est invalide ou inaccessible.');
        }

        if (! str_starts_with($realPath, $realPrefix . DIRECTORY_SEPARATOR) && $realPath !== $realPrefix) {
            return Response::error("Accès refusé : le chemin doit être situé sous {$prefix}.");
        }

        if (! is_file($realPath) || ! is_readable($realPath)) {
            return Response::error('Le fichier est introuvable ou non lisible.');
        }

        $decoded = file_get_contents($realPath);

        if ($decoded === false) {
            return Response::error('Impossible de lire le fichier.');
        }

        return [$decoded, $filename ?? basename($realPath)];
    }

    /** Télécharger un fichier depuis une URL http/https. */
    private function resolveFileUrl(string $url, ?string $filename): array|Response
    {
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');

        if (! in_array($scheme, ['http', 'https'], strict: true)) {
            return Response::error('file_url doit utiliser le schéma http ou https.');
        }

        $response = Http::timeout(30)->get($url);

        if (! $response->successful()) {
            return Response::error("Impossible de télécharger le fichier (HTTP {$response->status()}).");
        }

        $decoded          = $response->body();
        $resolvedFilename = $filename ?? basename(parse_url($url, PHP_URL_PATH) ?? '') ?: 'document';

        return [$decoded, $resolvedFilename];
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
