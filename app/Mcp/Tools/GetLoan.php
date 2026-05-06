<?php

namespace App\Mcp\Tools;

use App\Models\Loan;
use App\Models\Property;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Retourne le détail complet d\'un emprunt immobilier avec un résumé financier : capital, taux, durée, mensualité, assurance, coût total, intérêts et assurances de l\'année courante.')]
#[IsReadOnly]
class GetLoan extends Tool
{
    protected string $name = 'get_loan';

    public function handle(Request $request): Response
    {
        $loan = Loan::findOrFail($request->get('loan_id'));

        // Vérifie l'appartenance via BelongsToUserScope sur Property
        $property = Property::findOrFail($loan->property_id);

        $currentYear      = (int) now()->format('Y');
        $interestsYear    = $loan->getInterestsForYear($currentYear);
        $insuranceYear    = $loan->getInsuranceForYear($currentYear);
        $remainingCapital = $loan->getRemainingCapitalAtEndOfYear($currentYear);

        // Totaux sur toute la durée depuis les paiements si disponibles
        $totalInterests  = $loan->payments()->sum('interest_amount');
        $totalInsurances = $loan->payments()->sum('insurance_amount');
        $totalCapital    = $loan->payments()->sum('capital_amount');

        return Response::json([
            'id'                        => $loan->id,
            'property_id'               => $loan->property_id,
            'property_name'             => $property->name,
            'bank_name'                 => $loan->bank_name,
            'amount_eur'                => $loan->amount_euros,
            'annual_rate'               => $loan->annual_rate,
            'duration_months'           => $loan->duration_months,
            'duration_years'            => $loan->duration_years,
            'start_date'                => $loan->start_date?->toDateString(),
            'end_date'                  => $loan->end_date?->toDateString(),
            'monthly_payment_eur'       => $loan->monthly_payment_euros,
            'insurance_monthly_eur'     => $loan->insurance_monthly_euros,
            'insurance_type'            => $loan->insurance_type ?? null,
            'insurance_rate'            => $loan->insurance_rate ?? null,
            'total_cost_eur'            => bcdiv((string) $loan->total_cost, '100', 2),
            'has_schedule'              => $loan->payments()->exists(),
            'payments_count'            => $loan->payments()->count(),
            'summary_current_year'      => [
                'year'                      => $currentYear,
                'interests_eur'             => bcdiv((string) $interestsYear, '100', 2),
                'insurance_eur'             => bcdiv((string) $insuranceYear, '100', 2),
                'remaining_capital_eur'     => bcdiv((string) $remainingCapital, '100', 2),
            ],
            'summary_all_time'          => [
                'total_interests_eur'       => bcdiv((string) $totalInterests, '100', 2),
                'total_insurances_eur'      => bcdiv((string) $totalInsurances, '100', 2),
                'total_capital_repaid_eur'  => bcdiv((string) $totalCapital, '100', 2),
            ],
            'created_at' => $loan->created_at?->toDateTimeString(),
            'updated_at' => $loan->updated_at?->toDateTimeString(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'loan_id' => $schema->integer('Identifiant de l\'emprunt')->required(),
        ];
    }
}
