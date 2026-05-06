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

#[Description('Liste les charges déductibles de l\'utilisateur avec filtres optionnels par bien, année et catégorie. Catégories disponibles : property_tax, insurance, energy, maintenance, supplies, platform_fees, accounting, telecom, travel, cleaning, other.')]
#[IsReadOnly]
class ListExpenses extends Tool
{
    protected string $name = 'list_expenses';

    public function handle(Request $request): Response
    {
        $propertyId = $request->get('property_id');
        $year       = $request->get('year');
        $category   = $request->get('category');

        // Restreindre aux biens de l'utilisateur (BelongsToUserScope actif)
        if ($propertyId !== null) {
            $property    = Property::findOrFail($propertyId);
            $propertyIds = collect([$property->id]);
        } else {
            $propertyIds = Property::pluck('id');
        }

        $query = Expense::whereIn('property_id', $propertyIds)
            ->orderBy('expense_date', 'desc');

        if ($year !== null) {
            $query->whereYear('expense_date', $year);
        }

        if ($category !== null) {
            $query->where('category', $category);
        }

        $expenses = $query->with('property')->get();

        $totalAmount = $expenses->sum('amount');
        $totalHt     = $expenses->sum('amount_ht');

        // Totaux par catégorie
        $byCategory = $expenses->groupBy('category')->map(function ($group) {
            return [
                'count'      => $group->count(),
                'total_eur'  => bcdiv((string) $group->sum('amount'), '100', 2),
            ];
        });

        $categoryShortLabels = Expense::categoryShortLabels();

        $data = $expenses->map(function (Expense $expense) use ($categoryShortLabels) {
            return [
                'id'               => $expense->id,
                'property_id'      => $expense->property_id,
                'property_name'    => $expense->property?->name,
                'expense_date'     => $expense->expense_date?->toDateString(),
                'category'         => $expense->category,
                'category_label'   => ($categoryShortLabels[$expense->category] ?? $expense->category),
                'description'      => $expense->description,
                'amount_eur'       => $expense->amount_euros,
                'amount_ht_eur'    => $expense->amount_ht_euros,
                'tva_rate'         => $expense->tva_rate,
                'amount_tva_eur'   => $expense->amount_tva_euros,
                'is_dedicated'     => $expense->is_dedicated,
                'recurring_type'   => $expense->recurring_type,
            ];
        });

        return Response::json([
            'count'              => $expenses->count(),
            'total_amount_eur'   => bcdiv((string) $totalAmount, '100', 2),
            'total_ht_eur'       => bcdiv((string) $totalHt, '100', 2),
            'by_category'        => $byCategory,
            'expenses'           => $data,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id' => $schema->integer('Filtrer par identifiant de bien (optionnel)'),
            'year'        => $schema->integer('Filtrer par année fiscale, ex: 2024 (optionnel)'),
            'category'    => $schema->string('Filtrer par catégorie : property_tax, insurance, energy, maintenance, supplies, platform_fees, accounting, telecom, travel, cleaning, other (optionnel)'),
        ];
    }
}
