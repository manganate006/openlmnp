<?php

namespace App\Mcp\Tools;

use App\Models\Income;
use App\Models\Property;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Supprime un revenu locatif par son identifiant. Cette action est irréversible.')]
#[IsDestructive]
class DeleteIncome extends Tool
{
    protected string $name = 'delete_income';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'income_id' => 'required|integer',
        ]);

        $income = Income::findOrFail($validated['income_id']);
        Property::findOrFail($income->property_id);

        $id = $income->id;
        $income->delete();

        return Response::json(['success' => true, 'deleted_id' => $id]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'income_id' => $schema->integer('Identifiant du revenu à supprimer')->required(),
        ];
    }
}
