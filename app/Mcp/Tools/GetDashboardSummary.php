<?php

namespace App\Mcp\Tools;

use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Loan;
use App\Models\Property;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Retourne un tableau de bord agrégé pour l\'utilisateur : nombre de biens et emprunts, recettes et charges totales pour l\'année demandée (défaut : année en cours), résultat fiscal si l\'exercice existe, et répartition des recettes par plateforme et des charges par catégorie.')]
#[IsReadOnly]
class GetDashboardSummary extends Tool
{
    protected string $name = 'get_dashboard_summary';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'year' => 'nullable|integer|min:2000|max:2099',
        ]);

        $year = (int) ($validated['year'] ?? now()->format('Y'));

        // Tous les biens de l'utilisateur (BelongsToUserScope actif)
        $properties  = Property::orderBy('name')->get();
        $propertyIds = $properties->pluck('id');

        $propertyCount = $properties->count();
        $loanCount     = Loan::whereIn('property_id', $propertyIds)->count();

        // --- Recettes de l'année ---
        $incomes = Income::whereIn('property_id', $propertyIds)
            ->whereYear('income_date', $year)
            ->get();

        $totalIncome      = $incomes->sum('amount');
        $totalIncomeHt    = $incomes->sum('amount_ht');
        $totalPlatformFee = $incomes->sum('platform_fee');
        $totalTouristTax  = $incomes->sum('tourist_tax');
        $totalNetIncome   = $incomes->sum(fn ($i) => (int) $i->net_amount);

        // Répartition recettes par plateforme
        $incomeBySource = $incomes->groupBy('source')->map(function ($group) {
            return [
                'count'     => $group->count(),
                'total_eur' => bcdiv((string) $group->sum('amount'), '100', 2),
            ];
        });

        // --- Charges de l'année ---
        $expenses = Expense::whereIn('property_id', $propertyIds)
            ->whereYear('expense_date', $year)
            ->get();

        $totalExpenses   = $expenses->sum('amount');
        $totalExpensesHt = $expenses->sum('amount_ht');

        // Répartition charges par catégorie
        $categoryShortLabels = Expense::categoryShortLabels();
        $expenseByCategory   = $expenses->groupBy('category')->map(function ($group) {
            return [
                'count'     => $group->count(),
                'total_eur' => bcdiv((string) $group->sum('amount'), '100', 2),
            ];
        });

        // --- Exercice fiscal de l'année ---
        $fiscalYear     = FiscalYear::where('year', $year)->first();
        $fiscalYearData = null;

        if ($fiscalYear !== null) {
            $statusLabels   = FiscalYear::statusLabels();
            $fiscalYearData = [
                'id'                        => $fiscalYear->id,
                'status'                    => $fiscalYear->status,
                'status_label'              => ($statusLabels[$fiscalYear->status] ?? $fiscalYear->status),
                'fiscal_result_eur'         => $fiscalYear->fiscal_result_euros,
                'total_income_eur'          => bcdiv((string) $fiscalYear->total_income, '100', 2),
                'total_expenses_eur'        => bcdiv((string) $fiscalYear->total_expenses, '100', 2),
                'total_depreciation_eur'    => bcdiv((string) $fiscalYear->total_depreciation, '100', 2),
                'capped_depreciation_eur'   => bcdiv((string) $fiscalYear->capped_depreciation, '100', 2),
                'deferred_depreciation_eur' => bcdiv((string) $fiscalYear->deferred_depreciation, '100', 2),
                'has_pdf'                   => $fiscalYear->pdf_path !== null,
                'has_fec'                   => $fiscalYear->fec_path !== null,
            ];
        }

        // --- Résumé par bien (calculé depuis les collections déjà chargées) ---
        $incomesByProperty  = $incomes->groupBy('property_id');
        $expensesByProperty = $expenses->groupBy('property_id');

        $propertySummary = $properties->map(function (Property $property) use ($incomesByProperty, $expensesByProperty) {
            $propIncomeTotal  = $incomesByProperty->get($property->id)?->sum('amount') ?? 0;
            $propExpenseTotal = $expensesByProperty->get($property->id)?->sum('amount') ?? 0;

            return [
                'id'            => $property->id,
                'name'          => $property->name,
                'income_eur'    => bcdiv((string) $propIncomeTotal, '100', 2),
                'expenses_eur'  => bcdiv((string) $propExpenseTotal, '100', 2),
            ];
        });

        return Response::json([
            'year'                   => $year,
            'property_count'         => $propertyCount,
            'loan_count'             => $loanCount,
            'incomes'                => [
                'count'               => $incomes->count(),
                'total_eur'           => bcdiv((string) $totalIncome, '100', 2),
                'total_ht_eur'        => bcdiv((string) $totalIncomeHt, '100', 2),
                'total_platform_fees_eur' => bcdiv((string) $totalPlatformFee, '100', 2),
                'total_tourist_tax_eur'   => bcdiv((string) $totalTouristTax, '100', 2),
                'net_income_eur'      => bcdiv((string) $totalNetIncome, '100', 2),
                'by_source'           => $incomeBySource,
            ],
            'expenses'               => [
                'count'         => $expenses->count(),
                'total_eur'     => bcdiv((string) $totalExpenses, '100', 2),
                'total_ht_eur'  => bcdiv((string) $totalExpensesHt, '100', 2),
                'by_category'   => $expenseByCategory,
            ],
            'fiscal_year'            => $fiscalYearData,
            'properties_summary'     => $propertySummary,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'year' => $schema->integer('Année à analyser, ex: 2024 (optionnel — défaut : année en cours)'),
        ];
    }
}
