<?php

namespace App\Mcp\Tools;

use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\Property;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Retourne le tableau d\'amortissement complet d\'un emprunt avec filtre optionnel par année. Chaque ligne contient : numéro d\'échéance, date, capital remboursé, intérêts, assurance, total et capital restant dû. Inclut les totaux de la période.')]
#[IsReadOnly]
class GetLoanSchedule extends Tool
{
    protected string $name = 'get_loan_schedule';

    public function handle(Request $request): Response
    {
        $loan = Loan::findOrFail($request->get('loan_id'));

        // Vérifie l'appartenance via BelongsToUserScope sur Property
        Property::findOrFail($loan->property_id);

        $year = $request->get('year');

        $query = $loan->payments()->orderBy('month_number');

        if ($year !== null) {
            $query->whereYear('payment_date', $year);
        }

        $payments = $query->get();

        $totalCapital   = $payments->sum('capital_amount');
        $totalInterests = $payments->sum('interest_amount');
        $totalInsurance = $payments->sum('insurance_amount');
        $totalPaid      = $payments->sum(fn (LoanPayment $p) => $p->total_amount);

        $schedule = $payments->map(function (LoanPayment $payment) {
            return [
                'month_number'      => $payment->month_number,
                'payment_date'      => $payment->payment_date?->toDateString(),
                'capital_eur'       => $payment->capital_amount_euros,
                'interest_eur'      => $payment->interest_amount_euros,
                'insurance_eur'     => $payment->insurance_amount_euros,
                'total_eur'         => $payment->total_amount_euros,
                'remaining_capital_eur' => $payment->remaining_capital_euros,
            ];
        });

        return Response::json([
            'loan_id'             => $loan->id,
            'bank_name'           => $loan->bank_name,
            'filter_year'         => $year,
            'payments_count'      => $payments->count(),
            'totals'              => [
                'capital_eur'   => bcdiv((string) $totalCapital, '100', 2),
                'interests_eur' => bcdiv((string) $totalInterests, '100', 2),
                'insurance_eur' => bcdiv((string) $totalInsurance, '100', 2),
                'total_paid_eur'=> bcdiv((string) $totalPaid, '100', 2),
            ],
            'schedule' => $schedule,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'loan_id' => $schema->integer('Identifiant de l\'emprunt')->required(),
            'year'    => $schema->integer('Filtrer par année, ex: 2024 (optionnel — retourne tout le tableau si absent)'),
        ];
    }
}
