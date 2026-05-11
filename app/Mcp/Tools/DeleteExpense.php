<?php

namespace App\Mcp\Tools;

use App\Models\Expense;
use App\Models\Property;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Supprime une charge d\'exploitation par son identifiant. Les documents justificatifs associés sont également supprimés. Cette action est irréversible.')]
#[IsDestructive]
class DeleteExpense extends Tool
{
    protected string $name = 'delete_expense';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'expense_id' => 'required|integer',
        ]);

        $expense = Expense::findOrFail($validated['expense_id']);
        Property::findOrFail($expense->property_id);

        $id = $expense->id;

        // Delete associated documents' files
        foreach ($expense->documents as $doc) {
            \Illuminate\Support\Facades\Storage::delete($doc->file_path);
        }

        $expense->delete();

        return Response::json(['success' => true, 'deleted_id' => $id]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'expense_id' => $schema->integer('Identifiant de la charge à supprimer')->required(),
        ];
    }
}
