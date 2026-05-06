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

#[Description('Met à jour une charge d\'exploitation existante. Seuls les champs fournis sont modifiés (mise à jour partielle). Le montant est en euros.')]
#[IsDestructive]
class UpdateExpense extends Tool
{
    protected string $name = 'update_expense';

    private const VALID_CATEGORIES = [
        'property_tax',
        'insurance',
        'energy',
        'maintenance',
        'supplies',
        'platform_fees',
        'accounting',
        'telecom',
        'travel',
        'cleaning',
        'other',
    ];

    private const VALID_RECURRING_TYPES = ['once', 'monthly', 'quarterly', 'yearly'];

    public function handle(Request $request): Response
    {
        $categoryList  = implode(',', self::VALID_CATEGORIES);
        $recurringList = implode(',', self::VALID_RECURRING_TYPES);

        $validated = $request->validate([
            'expense_id'     => 'required|integer',
            'expense_date'   => 'nullable|date_format:Y-m-d',
            'amount'         => 'nullable|numeric|min:0',
            'category'       => "nullable|in:{$categoryList}",
            'description'    => 'nullable|string|max:500',
            'is_dedicated'   => 'nullable|boolean',
            'recurring_type' => "nullable|in:{$recurringList}",
            'tva_rate'       => 'nullable|numeric|min:0|max:100',
            'notes'          => 'nullable|string',
        ]);

        $expense = Expense::findOrFail($validated['expense_id']);

        // Verify ownership: the expense's property must belong to the authenticated user
        Property::findOrFail($expense->property_id);

        $updates = [];

        if (array_key_exists('expense_date', $validated) && $validated['expense_date'] !== null) {
            $updates['expense_date'] = $validated['expense_date'];
        }

        if (array_key_exists('amount', $validated) && $validated['amount'] !== null) {
            $updates['amount'] = (int) bcmul((string) $validated['amount'], '100', 0);
        }

        if (array_key_exists('category', $validated) && $validated['category'] !== null) {
            $updates['category'] = $validated['category'];
        }

        if (array_key_exists('description', $validated) && $validated['description'] !== null) {
            $updates['description'] = $validated['description'];
        }

        if (array_key_exists('is_dedicated', $validated) && $validated['is_dedicated'] !== null) {
            $updates['is_dedicated'] = $validated['is_dedicated'];
        }

        if (array_key_exists('recurring_type', $validated) && $validated['recurring_type'] !== null) {
            $updates['recurring_type'] = $validated['recurring_type'];
        }

        if (array_key_exists('tva_rate', $validated) && $validated['tva_rate'] !== null) {
            // Convert percentage to basis points (20% → 2000)
            $updates['tva_rate'] = (int) bcmul((string) $validated['tva_rate'], '100', 0);
        }

        if (array_key_exists('notes', $validated)) {
            $updates['notes'] = $validated['notes'];
        }

        $expense->update($updates);
        $expense->refresh();

        return Response::json([
            'success'        => true,
            'expense_id'     => $expense->id,
            'property_id'    => $expense->property_id,
            'expense_date'   => $expense->expense_date->toDateString(),
            'amount_eur'     => $expense->amount_euros,
            'amount_ht_eur'  => $expense->amount_ht_euros,
            'amount_tva_eur' => $expense->amount_tva_euros,
            'category'       => $expense->category,
            'description'    => $expense->description,
            'is_dedicated'   => $expense->is_dedicated,
            'recurring_type' => $expense->recurring_type,
            'notes'          => $expense->notes,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'expense_id'     => $schema->integer('Identifiant de la charge à mettre à jour')->required(),
            'expense_date'   => $schema->string('Date de la charge au format Y-m-d')->nullable(),
            'amount'         => $schema->number('Montant TTC en euros')->nullable(),
            'category'       => $schema->string('Catégorie : property_tax, insurance, energy, maintenance, supplies, platform_fees, accounting, telecom, travel, cleaning, other')->nullable(),
            'description'    => $schema->string('Description de la charge')->nullable(),
            'is_dedicated'   => $schema->boolean('Charge 100% dédiée à la location ou au prorata')->nullable(),
            'recurring_type' => $schema->string('Type de récurrence : once, monthly, quarterly, yearly')->nullable(),
            'tva_rate'       => $schema->number('Taux de TVA en pourcentage (ex: 20 pour 20%)')->nullable(),
            'notes'          => $schema->string('Notes libres')->nullable(),
        ];
    }
}
