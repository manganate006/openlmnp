<?php

namespace App\Mcp\Tools;

use App\Models\Loan;
use App\Models\Property;
use App\Services\LoanService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Crée un emprunt immobilier pour un bien LMNP et génère son tableau d\'amortissement théorique. Les montants (amount, monthly_payment, insurance_monthly, fees_amount, early_repayment_amount) sont en euros. fees_spread_method ne joue que si l\'étalement des frais est activé depuis le profil : linear (fractions égales, défaut) ou interest (prorata des intérêts courus, BOI-BIC-CHG-20-30-40 § 100-110).')]
#[IsDestructive]
class CreateLoan extends Tool
{
    protected string $name = 'create_loan';

    private const VALID_INSURANCE_TYPES = [Loan::INSURANCE_FIXED, Loan::INSURANCE_VARIABLE];

    private const VALID_FEES_METHODS = [Loan::FEES_METHOD_LINEAR, Loan::FEES_METHOD_INTEREST];

    public function __construct(private LoanService $loanService) {}

    public function handle(Request $request): Response
    {
        $insuranceTypeList = implode(',', self::VALID_INSURANCE_TYPES);
        $feesMethodList = implode(',', self::VALID_FEES_METHODS);

        $validated = $request->validate([
            'property_id'             => 'required|integer',
            'bank_name'               => 'nullable|string|max:255',
            'amount'                  => 'required|numeric|min:0',
            'annual_rate'             => 'required|numeric|min:0',
            'duration_months'         => 'required|integer|min:1',
            'start_date'              => 'required|date_format:Y-m-d',
            'monthly_payment'         => 'nullable|numeric|min:0',
            'insurance_type'          => "nullable|in:{$insuranceTypeList}",
            'insurance_monthly'       => 'nullable|numeric|min:0',
            'insurance_rate'          => 'nullable|numeric|min:0',
            'is_dedicated'            => 'nullable|boolean',
            'fees_amount'             => 'nullable|numeric|min:0',
            'fees_paid_on'            => 'nullable|date_format:Y-m-d',
            'fees_spread_method'      => "nullable|in:{$feesMethodList}",
            'early_repayment_date'    => 'nullable|date_format:Y-m-d',
            'early_repayment_amount'  => 'nullable|numeric|min:0',
        ]);

        // Vérification de propriété : findOrFail échoue (BelongsToUserScope) si le bien
        // n'appartient pas à l'utilisateur authentifié.
        $property = Property::findOrFail($validated['property_id']);

        $toCents = fn (?float $euros) => $euros === null ? null : (int) bcmul((string) $euros, '100', 0);

        $loan = Loan::create([
            'property_id'             => $property->id,
            'bank_name'               => $validated['bank_name'] ?? null,
            'amount'                  => $toCents((float) $validated['amount']),
            'annual_rate'             => $validated['annual_rate'],
            'duration_months'         => $validated['duration_months'],
            'start_date'              => $validated['start_date'],
            'monthly_payment'         => $toCents((float) ($validated['monthly_payment'] ?? 0)),
            'insurance_type'          => $validated['insurance_type'] ?? Loan::INSURANCE_FIXED,
            'insurance_monthly'       => $toCents((float) ($validated['insurance_monthly'] ?? 0)),
            'insurance_rate'          => $validated['insurance_rate'] ?? 0,
            'is_dedicated'            => $validated['is_dedicated'] ?? false,
            'fees_amount'             => $toCents((float) ($validated['fees_amount'] ?? 0)),
            'fees_paid_on'            => $validated['fees_paid_on'] ?? null,
            'fees_spread_method'      => $validated['fees_spread_method'] ?? Loan::FEES_METHOD_LINEAR,
            'early_repayment_date'    => $validated['early_repayment_date'] ?? null,
            'early_repayment_amount'  => isset($validated['early_repayment_amount']) ? $toCents((float) $validated['early_repayment_amount']) : null,
        ]);

        // Génère le tableau d'amortissement théorique, comme CreateLoan::afterCreate() côté
        // panel — un emprunt créé sans échéancier ne porterait ni intérêts ni assurance.
        $this->loanService->generateSchedule($loan);
        $loan->refresh();

        return Response::json([
            'success'                  => true,
            'loan_id'                  => $loan->id,
            'property_id'              => $loan->property_id,
            'bank_name'                => $loan->bank_name,
            'amount_eur'               => $loan->amount_euros,
            'annual_rate'              => $loan->annual_rate,
            'duration_months'          => $loan->duration_months,
            'start_date'               => $loan->start_date->toDateString(),
            'end_date'                 => $loan->end_date->toDateString(),
            'monthly_payment_eur'      => $loan->monthly_payment_euros,
            'insurance_type'           => $loan->insurance_type,
            'insurance_monthly_eur'    => $loan->insurance_monthly_euros,
            'insurance_rate'           => $loan->insurance_rate,
            'is_dedicated'             => $loan->is_dedicated,
            'fees_amount_eur'          => $loan->fees_amount_euros,
            'fees_paid_on'             => $loan->fees_paid_on?->toDateString(),
            'fees_spread_method'       => $loan->fees_spread_method,
            'early_repayment_date'     => $loan->early_repayment_date?->toDateString(),
            'early_repayment_amount_eur' => $loan->early_repayment_amount_euros,
            'schedule_generated'       => $loan->payments()->exists(),
            'payments_count'           => $loan->payments()->count(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id'            => $schema->integer()->description('Identifiant du bien immobilier')->required(),
            'bank_name'              => $schema->string()->description('Nom de la banque'),
            'amount'                 => $schema->number()->description('Capital emprunté en euros')->required(),
            'annual_rate'            => $schema->number()->description('Taux nominal annuel en % (ex : 1.5 pour 1,5 %)')->required(),
            'duration_months'        => $schema->integer()->description('Durée totale en mois (ex : 240 pour 20 ans)')->required(),
            'start_date'             => $schema->string()->description('Date de la 1ère échéance (Y-m-d)')->required(),
            'monthly_payment'        => $schema->number()->description('Mensualité hors assurance en euros. Absent ou 0 = calcul automatique')->nullable(),
            'insurance_type'         => $schema->string()->description('fixed (montant fixe mensuel, défaut) ou variable (taux sur capital restant dû)')->nullable(),
            'insurance_monthly'      => $schema->number()->description('Montant mensuel de l\'assurance en euros, si insurance_type=fixed')->nullable(),
            'insurance_rate'         => $schema->number()->description('Taux annuel de l\'assurance en %, si insurance_type=variable')->nullable(),
            'is_dedicated'           => $schema->boolean()->description('Emprunt dédié à 100 % au logement loué, sans quote-part (défaut : false)')->nullable(),
            'fees_amount'            => $schema->number()->description('Frais d\'emprunt en euros : dossier, courtage, part non restituable d\'une caution (art. 39-1-1° quater CGI)')->nullable(),
            'fees_paid_on'           => $schema->string()->description('Date de paiement des frais (Y-m-d). Absent = start_date')->nullable(),
            'fees_spread_method'     => $schema->string()->description('Méthode d\'étalement SI l\'option est activée dans le profil : linear (fractions égales, défaut) ou interest (prorata des intérêts courus, nécessite un tableau d\'amortissement)')->nullable(),
            'early_repayment_date'   => $schema->string()->description('Date d\'un remboursement anticipé déjà survenu (Y-m-d), le cas échéant')->nullable(),
            'early_repayment_amount' => $schema->number()->description('Capital remboursé par anticipation à cette date, en euros (total du capital restant dû pour un remboursement intégral)')->nullable(),
        ];
    }
}
