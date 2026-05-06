<?php

namespace App\Mcp\Tools;

use App\Models\Expense;
use App\Models\Property;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Retourne le détail complet d\'une charge par son identifiant : montant TTC/HT, TVA, catégorie, type de récurrence, flag dédié et montant effectif après application de la quote-part si non dédié.')]
#[IsReadOnly]
class GetExpense extends Tool
{
    protected string $name = 'get_expense';

    public function handle(Request $request): Response
    {
        $expense = Expense::findOrFail($request->get('expense_id'));

        // Vérifie l'appartenance via BelongsToUserScope sur Property
        $property = Property::findOrFail($expense->property_id);

        $categoryLabels      = Expense::categoryLabels();
        $categoryShortLabels = Expense::categoryShortLabels();
        $recurringLabels     = Expense::recurringLabels();

        return Response::json([
            'id'                    => $expense->id,
            'property_id'           => $expense->property_id,
            'property_name'         => $property->name,
            'expense_date'          => $expense->expense_date?->toDateString(),
            'category'              => $expense->category,
            'category_label'        => ($categoryLabels[$expense->category] ?? $expense->category),
            'category_short_label'  => ($categoryShortLabels[$expense->category] ?? $expense->category),
            'description'           => $expense->description,
            'amount_eur'            => $expense->amount_euros,
            'amount_ht_eur'         => $expense->amount_ht_euros,
            'tva_rate'              => $expense->tva_rate,
            'amount_tva_eur'        => $expense->amount_tva_euros,
            'is_dedicated'          => $expense->is_dedicated,
            'effective_amount_eur'  => bcdiv((string) $expense->effective_amount, '100', 2),
            'quota_share_applied'   => ! $expense->is_dedicated,
            'recurring_type'        => $expense->recurring_type,
            'recurring_label'       => ($recurringLabels[$expense->recurring_type] ?? $expense->recurring_type),
            'notes'                 => $expense->notes ?? null,
            'created_at'            => $expense->created_at?->toDateTimeString(),
            'updated_at'            => $expense->updated_at?->toDateTimeString(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'expense_id' => $schema->integer('Identifiant de la charge')->required(),
        ];
    }
}
