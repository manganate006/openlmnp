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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Met à jour les champs d\'un emprunt existant. Seuls les champs fournis sont modifiés (mise à jour partielle). Les montants (amount, monthly_payment, insurance_monthly, fees_amount, early_repayment_amount) sont en euros. Si amount, annual_rate, duration_months, start_date ou les champs d\'assurance changent, le tableau d\'amortissement théorique est régénéré automatiquement — sauf si l\'emprunt porte un tableau importé depuis la banque, protégé (voir compute_loan_schedule pour l\'écraser volontairement).')]
#[IsDestructive]
#[IsIdempotent]
class UpdateLoan extends Tool
{
    protected string $name = 'update_loan';

    private const VALID_INSURANCE_TYPES = [Loan::INSURANCE_FIXED, Loan::INSURANCE_VARIABLE];

    private const VALID_FEES_METHODS = [Loan::FEES_METHOD_LINEAR, Loan::FEES_METHOD_INTEREST];

    /** Ces champs alimentent directement generateSchedule() : les toucher invalide le tableau. */
    private const AMORTIZATION_FIELDS = [
        'amount', 'annual_rate', 'duration_months', 'start_date',
        'monthly_payment', 'insurance_type', 'insurance_monthly', 'insurance_rate',
    ];

    public function __construct(private LoanService $loanService) {}

    public function handle(Request $request): Response
    {
        $insuranceTypeList = implode(',', self::VALID_INSURANCE_TYPES);
        $feesMethodList = implode(',', self::VALID_FEES_METHODS);

        $validated = $request->validate([
            'loan_id'                 => 'required|integer',
            'bank_name'               => 'nullable|string|max:255',
            'amount'                  => 'nullable|numeric|min:0',
            'annual_rate'             => 'nullable|numeric|min:0',
            'duration_months'         => 'nullable|integer|min:1',
            'start_date'              => 'nullable|date_format:Y-m-d',
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

        // BelongsToUserThroughPropertyScope limite déjà la requête aux emprunts d'un bien de
        // l'utilisateur authentifié : l'emprunt d'un autre compte est introuvable (404),
        // jamais modifiable par erreur.
        $loan = Loan::findOrFail($validated['loan_id']);

        $updates = [];

        foreach (['bank_name', 'start_date', 'insurance_type', 'fees_paid_on', 'fees_spread_method', 'early_repayment_date'] as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] !== null) {
                $updates[$field] = $validated[$field];
            }
        }

        foreach (['annual_rate', 'insurance_rate', 'duration_months'] as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] !== null) {
                $updates[$field] = $validated[$field];
            }
        }

        if (array_key_exists('is_dedicated', $validated) && $validated['is_dedicated'] !== null) {
            $updates['is_dedicated'] = $validated['is_dedicated'];
        }

        // Montants en euros → centimes. `monthly_payment` à 0 est une valeur légitime (calcul
        // automatique) : seule l'ABSENCE du champ ne doit rien toucher, pas `0`.
        foreach (['amount', 'monthly_payment', 'insurance_monthly', 'fees_amount', 'early_repayment_amount'] as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] !== null) {
                $updates[$field] = (int) bcmul((string) $validated[$field], '100', 0);
            }
        }

        if (empty($updates)) {
            return Response::error('Aucun champ à modifier fourni.');
        }

        $touchesAmortization = count(array_intersect(self::AMORTIZATION_FIELDS, array_keys($updates))) > 0;

        $loan->update($updates);

        $scheduleRegenerated = false;
        $scheduleSkippedReason = null;

        if ($touchesAmortization) {
            if ($loan->hasImportedSchedule()) {
                $scheduleSkippedReason = 'Tableau d\'amortissement importé depuis la banque : protégé, non régénéré. '
                    . 'Utilisez compute_loan_schedule avec force=true pour l\'écraser volontairement.';
            } else {
                $scheduleRegenerated = $this->loanService->generateSchedule($loan, force: true);
            }
        }

        $loan->refresh();

        return Response::json([
            'success'                 => true,
            'loan_id'                 => $loan->id,
            'updated_fields'          => array_keys($updates),
            'schedule_regenerated'    => $scheduleRegenerated,
            'schedule_skipped_reason' => $scheduleSkippedReason,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'loan_id'                => $schema->integer()->description('Identifiant de l\'emprunt à modifier')->required(),
            'bank_name'              => $schema->string()->description('Nom de la banque')->nullable(),
            'amount'                 => $schema->number()->description('Capital emprunté en euros')->nullable(),
            'annual_rate'            => $schema->number()->description('Taux nominal annuel en %')->nullable(),
            'duration_months'        => $schema->integer()->description('Durée totale en mois')->nullable(),
            'start_date'             => $schema->string()->description('Date de la 1ère échéance (Y-m-d)')->nullable(),
            'monthly_payment'        => $schema->number()->description('Mensualité hors assurance en euros (0 = calcul automatique)')->nullable(),
            'insurance_type'         => $schema->string()->description('fixed ou variable')->nullable(),
            'insurance_monthly'      => $schema->number()->description('Montant mensuel de l\'assurance en euros, si insurance_type=fixed')->nullable(),
            'insurance_rate'         => $schema->number()->description('Taux annuel de l\'assurance en %, si insurance_type=variable')->nullable(),
            'is_dedicated'           => $schema->boolean()->description('Emprunt dédié à 100 % au logement loué, sans quote-part')->nullable(),
            'fees_amount'            => $schema->number()->description('Frais d\'emprunt en euros')->nullable(),
            'fees_paid_on'           => $schema->string()->description('Date de paiement des frais (Y-m-d)')->nullable(),
            'fees_spread_method'     => $schema->string()->description('linear (fractions égales) ou interest (prorata des intérêts courus)')->nullable(),
            'early_repayment_date'   => $schema->string()->description('Date d\'un remboursement anticipé (Y-m-d)')->nullable(),
            'early_repayment_amount' => $schema->number()->description('Capital remboursé par anticipation à cette date, en euros')->nullable(),
        ];
    }
}
