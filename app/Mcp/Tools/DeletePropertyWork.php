<?php

namespace App\Mcp\Tools;

use App\Models\Property;
use App\Models\PropertyWork;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Supprime un poste de travaux immobiliers par son identifiant. Les documents justificatifs associés sont également supprimés. Cette action est irréversible.')]
#[IsDestructive]
class DeletePropertyWork extends Tool
{
    protected string $name = 'delete_property_work';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'work_id' => 'required|integer',
        ]);

        $work = PropertyWork::findOrFail($validated['work_id']);
        Property::findOrFail($work->property_id);

        $id = $work->id;

        foreach ($work->documents as $doc) {
            \Illuminate\Support\Facades\Storage::delete($doc->file_path);
        }

        $work->delete();

        return Response::json(['success' => true, 'deleted_id' => $id]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'work_id' => $schema->integer('Identifiant des travaux à supprimer')->required(),
        ];
    }
}
