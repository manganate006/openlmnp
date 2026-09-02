<?php

namespace App\Console\Commands;

use App\Models\FiscalYear;
use App\Models\Loan;
use App\Services\FiscalYearService;
use App\Services\LoanService;
use Illuminate\Console\Command;

/**
 * Régénère les tableaux d'amortissement des emprunts à assurance variable.
 *
 * Contexte : LoanService traitait insurance_rate comme un coefficient au lieu
 * d'un pourcentage (division par 100 manquante, contrairement à annual_rate).
 * L'assurance était donc 100 fois trop chère, et comme le tableau est STOCKÉ,
 * corriger le calcul ne suffit pas : il faut le régénérer. Les exercices
 * fiscaux, qui figent leurs totaux, doivent ensuite être recalculés — sans quoi
 * l'utilisateur continue de voir des charges délirantes après le correctif.
 */
class RepairLoanInsuranceCommand extends Command
{
    protected $signature = 'openlmnp:repair-loan-insurance
                            {--fix : Applique les corrections (sinon simple rapport)}
                            {--loan= : Limite le traitement à un emprunt}';

    protected $description = 'Régénère les tableaux d\'amortissement des emprunts à assurance variable';

    public function handle(LoanService $loans, FiscalYearService $fiscalYears): int
    {
        $apply = (bool) $this->option('fix');

        $query = Loan::withoutGlobalScopes()
            ->where('insurance_type', Loan::INSURANCE_VARIABLE)
            ->where('insurance_rate', '>', 0);

        if ($id = $this->option('loan')) {
            $query->whereKey($id);
        }

        $affected = $query->get();

        if ($affected->isEmpty()) {
            $this->info('Aucun emprunt à assurance variable : rien à faire.');

            return self::SUCCESS;
        }

        $rows = [];
        $userIds = [];

        foreach ($affected as $loan) {
            $before = (int) $loan->payments()->withoutGlobalScopes()->sum('insurance_amount');

            // Assurance totale attendue, à titre indicatif : le taux s'applique au
            // capital restant dû, qui décroît — l'ordre de grandeur suffit ici.
            $rows[] = [
                $loan->id,
                $loan->bank_name ?: '—',
                number_format($loan->amount / 100, 0, ',', ' ') . ' €',
                $loan->insurance_rate . ' %',
                number_format($before / 100, 0, ',', ' ') . ' €',
            ];

            if ($apply) {
                $loan->payments()->withoutGlobalScopes()->delete();
                $loans->generateSchedule($loan);

                $after = (int) $loan->payments()->withoutGlobalScopes()->sum('insurance_amount');
                $this->line("  emprunt {$loan->id} : assurance totale "
                    . number_format($before / 100, 0, ',', ' ') . ' € → '
                    . number_format($after / 100, 0, ',', ' ') . ' €');

                if ($userId = $loan->property?->user_id) {
                    $userIds[$userId] = true;
                }
            }
        }

        $this->newLine();
        $this->table(
            ['Emprunt', 'Banque', 'Montant', 'Taux assurance', 'Assurance totale (avant)'],
            $rows
        );

        if (! $apply) {
            $this->warn(count($rows) . ' emprunt(s) concerné(s). Relancer avec --fix pour régénérer.');

            return self::SUCCESS;
        }

        // Les exercices figent leurs totaux : sans recalcul, l'utilisateur voit
        // toujours les anciennes charges.
        $recalculated = 0;
        $closed = [];

        foreach (array_keys($userIds) as $userId) {
            $years = FiscalYear::withoutGlobalScopes()->where('user_id', $userId)->orderBy('year')->get();

            foreach ($years as $year) {
                if ($year->status === FiscalYear::STATUS_CLOSED) {
                    $closed[] = [$userId, $year->year, number_format($year->total_expenses / 100, 0, ',', ' ') . ' €'];

                    continue;
                }

                $fiscalYears->calculate($year, force: true);
                $recalculated++;
            }
        }

        $this->info(count($rows) . " emprunt(s) régénéré(s), {$recalculated} exercice(s) recalculé(s).");

        if ($closed) {
            $this->newLine();
            $this->warn('Exercices CLÔTURÉS non recalculés (une déclaration déposée ne se réécrit pas en silence) :');
            $this->table(['Utilisateur', 'Année', 'Charges figées'], $closed);
        }

        return self::SUCCESS;
    }
}
