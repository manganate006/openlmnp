<?php

namespace App\Mcp\Tools;

use App\Models\Document;
use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyWork;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Attache un document justificatif (facture, reçu, devis) à une charge, un mobilier ou un poste de travaux. Le fichier doit être envoyé encodé en base64. Le chemin de stockage est : documents/{user_id}/{type}/{filename}.')]
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

    /** Taille maximum du fichier décodé : 10 Mo */
    private const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;

    public function handle(Request $request): Response
    {
        $typeList = implode(',', array_keys(self::SUPPORTED_TYPES));

        $validated = $request->validate([
            'type'          => "required|in:{$typeList}",
            'record_id'     => 'required|integer',
            'label'         => 'required|string|max:255',
            'file_base64'   => 'required|string',
            'filename'      => 'required|string|max:255',
            'amount'        => 'nullable|numeric|min:0',
            'document_date' => 'nullable|date_format:Y-m-d',
        ]);

        // Resolve the polymorphic model class
        $modelClass = self::SUPPORTED_TYPES[$validated['type']];

        /** @var Expense|Furniture|PropertyWork $record */
        $record = $modelClass::findOrFail($validated['record_id']);

        // Verify ownership: the record's property must belong to the authenticated user
        Property::findOrFail($record->property_id);

        // Validate and decode the base64 file content
        $decoded = base64_decode($validated['file_base64'], strict: true);

        if ($decoded === false) {
            return Response::error('Le contenu base64 fourni est invalide.');
        }

        if (strlen($decoded) > self::MAX_FILE_SIZE_BYTES) {
            return Response::error('Le fichier dépasse la taille maximum autorisée (10 Mo).');
        }

        // Sanitize filename and validate extension
        $filename  = basename($validated['filename']);
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

    public function schema(JsonSchema $schema): array
    {
        return [
            'type'          => $schema->string('Type d\'enregistrement cible : expense, furniture, work')->required(),
            'record_id'     => $schema->integer('Identifiant de la charge, du mobilier ou du poste de travaux')->required(),
            'label'         => $schema->string('Libellé du document (ex: Facture plombier, Reçu IKEA)')->required(),
            'file_base64'   => $schema->string('Contenu du fichier encodé en base64 (PDF, JPG, PNG, WebP, HEIC — max 10 Mo)')->required(),
            'filename'      => $schema->string('Nom original du fichier avec son extension (ex: facture.pdf)')->required(),
            'amount'        => $schema->number('Montant indiqué sur le document en euros (optionnel)')->nullable(),
            'document_date' => $schema->string('Date du document au format Y-m-d (optionnel)')->nullable(),
        ];
    }
}
