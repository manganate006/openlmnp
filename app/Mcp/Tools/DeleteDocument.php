<?php

namespace App\Mcp\Tools;

use App\Models\Document;
use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyWork;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Supprime un document justificatif par son identifiant. Le fichier physique est également supprimé du stockage. Cette action est irréversible.')]
#[IsDestructive]
class DeleteDocument extends Tool
{
    protected string $name = 'delete_document';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'document_id' => 'required|integer',
        ]);

        $document = Document::findOrFail($validated['document_id']);

        // Verify ownership: the parent entity's property must belong to the authenticated user
        $parent = $document->documentable;
        if ($parent instanceof Expense || $parent instanceof Furniture || $parent instanceof PropertyWork) {
            Property::findOrFail($parent->property_id);
        }

        $id = $document->id;

        Storage::delete($document->file_path);
        $document->delete();

        return Response::json(['success' => true, 'deleted_id' => $id]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->integer('Identifiant du document à supprimer')->required(),
        ];
    }
}
