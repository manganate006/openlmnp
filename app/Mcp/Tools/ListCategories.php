<?php

namespace App\Mcp\Tools;

use App\Models\Expense;
use App\Models\Income;
use App\Models\Property;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Retourne toutes les valeurs valides pour les champs de type enum : catégories de charges, sources de revenus, types de bien, types de location, régimes TVA et types de récurrence. Indispensable avant de créer un revenu ou une charge pour connaître les valeurs acceptées.')]
#[IsReadOnly]
class ListCategories extends Tool
{
    protected string $name = 'list_categories';

    public function handle(Request $request): Response
    {
        return Response::json([
            'expense_categories' => Expense::categoryLabels(),
            'income_sources' => Income::sourceLabels(),
            'property_types' => Property::typeLabels(),
            'rental_types' => [
                Property::RENTAL_SEASONAL  => 'Location saisonnière',
                Property::RENTAL_LONG_TERM => 'Location longue durée',
                Property::RENTAL_MIXED     => 'Mixte',
            ],
            'tva_regimes' => [
                Property::TVA_EXEMPT => 'Exonéré de TVA',
                Property::TVA_LIABLE => 'Assujetti à la TVA',
            ],
            'recurring_types' => [
                'once'      => 'Ponctuel',
                'monthly'   => 'Mensuel',
                'quarterly' => 'Trimestriel',
                'yearly'    => 'Annuel',
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
