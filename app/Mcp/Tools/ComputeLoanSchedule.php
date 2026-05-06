<?php

namespace App\Mcp\Tools;

use App\Models\Loan;
use App\Services\LoanService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Génère (ou régénère) le tableau d\'amortissement d\'un emprunt immobilier. Supprime les échéances existantes et recalcule toutes les mensualités (capital, intérêts, assurance). Retourne un résumé financier ainsi que la première et la dernière échéance. Tous les montants sont en euros.')]
#[IsDestructive]
class ComputeLoanSchedule extends Tool
{
    protected string $name = 'compute_loan_schedule';

    public function __construct(private LoanService $loanService) {}

    public function handle(Request $request): Response
    {
        $loanId = (int) $request->get('loan_id');

        // Vérification de propriété : le prêt doit appartenir à un bien de l'utilisateur
        $loan = Loan::with('property')->findOrFail($loanId);

        // BelongsToUserScope sur Property — findOrFail lève une 404 si le bien
        // n'appartient pas à l'utilisateur courant
        \App\Models\Property::findOrFail($loan->property_id);

        // (Re)génère toutes les échéances
        $this->loanService->generateSchedule($loan);

        // Rafraîchir pour avoir la mensualité éventuellement mise à jour
        $loan->refresh();

        $payments = $loan->payments()->orderBy('month_number')->get();
        $count    = $payments->count();

        $first = $payments->first();
        $last  = $payments->last();

        $totalInterests  = $payments->sum('interest_amount');
        $totalInsurances = $payments->sum('insurance_amount');
        $totalCost       = bcadd((string) $totalInterests, (string) $totalInsurances, 0);

        return Response::json([
            'loan_id'     => $loan->id,
            'bank_name'   => $loan->bank_name,
            'property_id' => $loan->property_id,

            'summary' => [
                'capital_eur'           => bcdiv((string) $loan->amount, '100', 2),
                'annual_rate_pct'       => (float) $loan->annual_rate,
                'duration_months'       => $loan->duration_months,
                'start_date'            => $loan->start_date->toDateString(),
                'end_date'              => $loan->end_date->toDateString(),
                'monthly_payment_eur'   => bcdiv((string) $loan->monthly_payment, '100', 2),
                'insurance_monthly_eur' => bcdiv((string) $loan->insurance_monthly, '100', 2),
                'insurance_type'        => $loan->insurance_type,
                'total_interests_eur'   => bcdiv((string) $totalInterests, '100', 2),
                'total_insurances_eur'  => bcdiv((string) $totalInsurances, '100', 2),
                'total_cost_eur'        => bcdiv($totalCost, '100', 2),
                'payments_generated'    => $count,
            ],

            'first_payment' => $first ? [
                'month'            => $first->month_number,
                'date'             => $first->payment_date instanceof \Carbon\Carbon
                    ? $first->payment_date->toDateString()
                    : (string) $first->payment_date,
                'capital_eur'      => bcdiv((string) $first->capital_amount, '100', 2),
                'interest_eur'     => bcdiv((string) $first->interest_amount, '100', 2),
                'insurance_eur'    => bcdiv((string) $first->insurance_amount, '100', 2),
                'remaining_eur'    => bcdiv((string) $first->remaining_capital, '100', 2),
            ] : null,

            'last_payment' => $last ? [
                'month'            => $last->month_number,
                'date'             => $last->payment_date instanceof \Carbon\Carbon
                    ? $last->payment_date->toDateString()
                    : (string) $last->payment_date,
                'capital_eur'      => bcdiv((string) $last->capital_amount, '100', 2),
                'interest_eur'     => bcdiv((string) $last->interest_amount, '100', 2),
                'insurance_eur'    => bcdiv((string) $last->insurance_amount, '100', 2),
                'remaining_eur'    => bcdiv((string) $last->remaining_capital, '100', 2),
            ] : null,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'loan_id' => $schema->integer('Identifiant de l\'emprunt à recalculer')->required(),
        ];
    }
}
