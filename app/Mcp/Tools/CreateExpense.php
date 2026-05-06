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

#[Description('Crée une charge d\'exploitation pour un bien LMNP. Le montant est en euros. Catégories disponibles : property_tax, insurance, energy, maintenance, supplies, platform_fees, accounting, telecom, travel, cleaning, other.')]
#[IsDestructive]
class CreateExpense extends Tool
{
    protected string $name = 'create_expense';

    /** Catégories valides extraites du modèle Expense */
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

    /** Types de récurrence valides */
    private const VALID_RECURRING_TYPES = ['once', 'monthly', 'quarterly', 'yearly'];

    public function handle(Request $request): Response
    {
        $categoryList   = implode(',', self::VALID_CATEGORIES);
        $recurringList  = implode(',', self::VALID_RECURRING_TYPES);

        $validated = $request->validate([
            'property_id'    => 'required|integer',
            'expense_date'   => 'required|date_format:Y-m-d',
            'amount'         => 'required|numeric|min:0',
            'category'       => "required|in:{$categoryList}",
            'description'    => 'required|string|max:500',
            'is_dedicated'   => 'nullable|boolean',
            'recurring_type' => "nullable|in:{$recurringList}",
            'tva_rate'       => 'nullable|numeric|min:0|max:100',
            'notes'          => 'nullable|string',
        ]);

        // Verify property belongs to the authenticated user (BelongsToUserScope active)
        $property = Property::findOrFail($validated['property_id']);

        $amountCents = (int) bcmul((string) $validated['amount'], '100', 0);

        // Convert percentage to basis points (20% → 2000)
        $tvaRate = isset($validated['tva_rate'])
            ? (int) bcmul((string) $validated['tva_rate'], '100', 0)
            : 0;

        $expense = Expense::create([
            'property_id'    => $property->id,
            'expense_date'   => $validated['expense_date'],
            'amount'         => $amountCents,
            'tva_rate'       => $tvaRate,
            'category'       => $validated['category'],
            'description'    => $validated['description'],
            'is_dedicated'   => $validated['is_dedicated'] ?? false,
            'recurring_type' => $validated['recurring_type'] ?? 'once',
            'notes'          => $validated['notes'] ?? null,
        ]);

        return Response::json([
            'success'          => true,
            'expense_id'       => $expense->id,
            'property_id'      => $expense->property_id,
            'expense_date'     => $expense->expense_date->toDateString(),
            'amount_eur'       => $expense->amount_euros,
            'amount_ht_eur'    => $expense->amount_ht_euros,
            'amount_tva_eur'   => $expense->amount_tva_euros,
            'category'         => $expense->category,
            'description'      => $expense->description,
            'is_dedicated'     => $expense->is_dedicated,
            'recurring_type'   => $expense->recurring_type,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id'    => $schema->integer('Identifiant du bien immobilier')->required(),
            'expense_date'   => $schema->string('Date de la charge au format Y-m-d (ex: 2025-03-15)')->required(),
            'amount'         => $schema->number('Montant TTC en euros (ex: 250.00)')->required(),
            'category'       => $schema->string('Catégorie : property_tax, insurance, energy, maintenance, supplies, platform_fees, accounting, telecom, travel, cleaning, other')->required(),
            'description'    => $schema->string('Description de la charge')->required(),
            'is_dedicated'   => $schema->boolean('Charge 100% dédiée à la location (true) ou au prorata de la quote-part (false, défaut)')->nullable(),
            'recurring_type' => $schema->string('Type de récurrence : once (défaut), monthly, quarterly, yearly')->nullable(),
            'tva_rate'       => $schema->number('Taux de TVA en pourcentage (ex: 20 pour 20%, 0 pour exonéré)')->nullable(),
            'notes'          => $schema->string('Notes libres')->nullable(),
        ];
    }
}
