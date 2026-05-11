<?php

namespace App\Mcp\Tools;

use App\Models\Furniture;
use App\Models\Property;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Supprime un élément de mobilier par son identifiant. Les documents justificatifs associés sont également supprimés. Cette action est irréversible.')]
#[IsDestructive]
class DeleteFurniture extends Tool
{
    protected string $name = 'delete_furniture';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'furniture_id' => 'required|integer',
        ]);

        $furniture = Furniture::findOrFail($validated['furniture_id']);
        Property::findOrFail($furniture->property_id);

        $id = $furniture->id;

        foreach ($furniture->documents as $doc) {
            \Illuminate\Support\Facades\Storage::delete($doc->file_path);
        }

        $furniture->delete();

        return Response::json(['success' => true, 'deleted_id' => $id]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'furniture_id' => $schema->integer('Identifiant du mobilier à supprimer')->required(),
        ];
    }
}
