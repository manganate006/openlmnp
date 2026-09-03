<?php

namespace App\Console\Commands;

use App\Models\FiscalYear;
use App\Models\User;
use App\Services\FiscalYearService;
use Illuminate\Console\Command;

/**
 * Détecte les exercices dont les totaux ne correspondent plus aux données saisies.
 *
 * Contexte : jusqu'au correctif de `Property::booted()`, supprimer un bien laissait
 * intacts les exercices déjà calculés. `fiscal_years` ne porte aucun lien vers
 * `properties` — un exercice agrège tous les biens d'une année — donc aucune contrainte
 * de clé étrangère ne pouvait s'en charger, et rien ne signalait l'écart : l'exercice
 * continuait d'alimenter la liste et le tableau de bord avec des montants calculés sur
 * des données disparues, et son amortissement différé se propageait aux années suivantes.
 *
 * Les totaux d'un exercice sont des données DÉRIVÉES : les recalculer est sans risque et
 * idempotent. Ce qui ne l'est pas, c'est de le faire sur un exercice CLÔTURÉ, qui porte
 * ce qui a été déclaré à l'administration. Ceux-là ne sont jamais réécrits sans `--fix`,
 * et le rapport dit ce qui changerait.
 */
class RepairOrphanFiscalYearsCommand extends Command
{
    protected $signature = 'openlmnp:repair-orphan-fiscal-years
                            {--fix : Applique les corrections (sinon simple rapport)}
                            {--closed : Recalcule AUSSI les exercices clôturés, qui portent une déclaration déposée}
                            {--user= : Limite le traitement à un utilisateur (id ou email)}';

    protected $description = 'Détecte les exercices dont les totaux ne reflètent plus les données saisies';

    /** Colonnes dérivées : elles doivent toutes se retrouver à l'identique après recalcul. */
    private const DERIVED_COLUMNS = [
        'total_income',
        'total_expenses',
        'total_depreciation',
        'capped_depreciation',
        'deferred_depreciation',
        'previous_deferred',
        'fiscal_result',
    ];

    public function handle(FiscalYearService $fiscalYears): int
    {
        $users = $this->targetUsers();

        if ($users->isEmpty()) {
            $this->error('Aucun utilisateur correspondant.');

            return self::FAILURE;
        }

        $stale = [];

        foreach ($users as $user) {
            foreach ($this->fiscalYearsOf($user) as $fiscalYear) {
                $drift = $this->drift($fiscalYear, $fiscalYears);

                if ($drift !== []) {
                    $stale[] = ['year' => $fiscalYear, 'drift' => $drift];
                }
            }
        }

        if ($stale === []) {
            $this->info('Tous les exercices reflètent les données saisies.');

            return self::SUCCESS;
        }

        $this->report($stale);

        $closed = array_filter($stale, fn (array $row) => $row['year']->status === FiscalYear::STATUS_CLOSED);

        if (! $this->option('fix')) {
            $this->newLine();
            $this->warn('Rapport seul. Relancer avec --fix pour recalculer.');

            if ($closed !== []) {
                $this->warn('Les exercices clôturés demandent --fix --closed : ils portent une déclaration déposée.');
            }

            return self::SUCCESS;
        }

        $repaired = 0;
        $skipped = 0;

        foreach ($stale as $row) {
            $fiscalYear = $row['year'];
            $isClosed = $fiscalYear->status === FiscalYear::STATUS_CLOSED;

            if ($isClosed && ! $this->option('closed')) {
                $skipped++;

                continue;
            }

            $fiscalYears->calculate($fiscalYear, force: $isClosed);
            $repaired++;
        }

        $this->newLine();
        $this->info("{$repaired} exercice(s) recalculé(s).");

        if ($skipped > 0) {
            $this->warn("{$skipped} exercice(s) clôturé(s) laissé(s) en l'état. Ajouter --closed pour les recalculer aussi.");
        }

        return self::SUCCESS;
    }

    /**
     * Écart entre les totaux stockés et ce qu'un recalcul produirait.
     *
     * `computeTotals()` n'écrit rien : détecter un écart ne doit rien changer, sans quoi
     * le mode rapport corrigerait ce qu'il prétend seulement décrire.
     *
     * @return array<string, array{stored: int, computed: int}>
     */
    private function drift(FiscalYear $fiscalYear, FiscalYearService $fiscalYears): array
    {
        $stored = $fiscalYear->only(self::DERIVED_COLUMNS);
        $computed = $fiscalYears->computeTotals($fiscalYear);

        $drift = [];

        foreach (self::DERIVED_COLUMNS as $column) {
            if ((int) $stored[$column] !== (int) $computed[$column]) {
                $drift[$column] = ['stored' => (int) $stored[$column], 'computed' => (int) $computed[$column]];
            }
        }

        return $drift;
    }

    /** @param  array<int, array{year: FiscalYear, drift: array}>  $stale */
    private function report(array $stale): void
    {
        $this->line(count($stale).' exercice(s) désynchronisé(s) :');
        $this->newLine();

        foreach ($stale as $row) {
            $fiscalYear = $row['year'];
            $label = $fiscalYear->status === FiscalYear::STATUS_CLOSED ? 'clôturé' : 'brouillon';
            $this->line("  Utilisateur #{$fiscalYear->user_id} — exercice {$fiscalYear->year} ({$label})");

            foreach ($row['drift'] as $column => $values) {
                $stored = number_format($values['stored'] / 100, 2, ',', ' ');
                $computed = number_format($values['computed'] / 100, 2, ',', ' ');
                $this->line("      {$column} : {$stored} € → {$computed} €");
            }
        }
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function targetUsers()
    {
        $query = User::query();

        if ($target = $this->option('user')) {
            $query->when(
                is_numeric($target),
                fn ($q) => $q->whereKey($target),
                fn ($q) => $q->where('email', $target),
            );
        }

        return $query->get();
    }

    /** @return \Illuminate\Support\Collection<int, FiscalYear> */
    private function fiscalYearsOf(User $user)
    {
        return FiscalYear::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->orderBy('year')
            ->get();
    }
}
