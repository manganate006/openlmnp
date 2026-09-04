<?php

namespace App\Console\Commands;

use App\Models\FiscalYear;
use App\Models\User;
use App\Services\FiscalYearService;
use Illuminate\Console\Command;

/**
 * Reconstitue le suivi des déficits reportables des exercices déjà tenus.
 *
 * Jusqu'à la v1.3.2, le déficit n'existait pas comme notion : les cases 982/983/984 du 2033-D
 * étaient alimentées par l'amortissement réputé différé. Les colonnes `previous_deficit`,
 * `deficit_imputed`, `deficit_carryforward` et `deficit_detail` sont donc à 0 sur tous les
 * dossiers existants, y compris ceux qui ont réellement connu des exercices déficitaires.
 *
 * Les totaux d'un exercice sont des données DÉRIVÉES FIGÉES en base : un correctif de règle ne
 * se propage pas seul, d'où cette commande. Elle est délibérément CHIRURGICALE — elle ne
 * réécrit QUE les quatre colonnes de déficit, jamais `fiscal_result` ni les autres totaux. Un
 * exercice clôturé porte une déclaration déposée : son résultat déclaré n'a aucune raison de
 * bouger, seul son suivi de déficits, qui n'existait pas, apparaît.
 *
 * Convention maison : rapport par défaut, `--fix` pour agir.
 */
class RepairDeficitsCommand extends Command
{
    protected $signature = 'openlmnp:repair-deficits
                            {--fix : Applique les corrections (sinon simple rapport)}
                            {--user= : Limite le traitement à un utilisateur (id ou email)}';

    protected $description = 'Reconstitue le suivi des déficits reportables (2033-D cases 982/983/984)';

    /** Les seules colonnes que cette commande a le droit de réécrire. */
    private const DEFICIT_COLUMNS = [
        'previous_deficit',
        'deficit_imputed',
        'deficit_carryforward',
        'deficit_detail',
    ];

    public function handle(FiscalYearService $fiscalYears): int
    {
        $users = $this->targetUsers();

        if ($users->isEmpty()) {
            $this->error('Aucun utilisateur correspondant.');

            return self::FAILURE;
        }

        $fix = (bool) $this->option('fix');
        $drifted = 0;
        $repaired = 0;

        foreach ($users as $user) {
            // Ordre chronologique OBLIGATOIRE : le stock de déficits d'un exercice se lit dans
            // le détail du précédent. Réparer 2025 avant 2024 reconstituerait un stock faux.
            $chain = FiscalYear::withoutGlobalScopes()
                ->where('user_id', $user->id)
                ->orderBy('year')
                ->get();

            foreach ($chain as $fiscalYear) {
                $computed = $this->deficitColumns($fiscalYears->computeTotals($fiscalYear));
                $stored = $this->deficitColumns($fiscalYear->only(self::DEFICIT_COLUMNS));

                if ($this->comparable($stored) === $this->comparable($computed)) {
                    continue;
                }

                $drifted++;
                $this->reportDrift($fiscalYear, $stored, $computed);

                if ($fix) {
                    $fiscalYear->update($computed);
                    $repaired++;
                }
            }
        }

        if ($drifted === 0) {
            $this->info('Le suivi des déficits est à jour sur tous les exercices.');

            return self::SUCCESS;
        }

        $this->newLine();

        if (! $fix) {
            $this->warn($drifted . ' exercice(s) à reconstituer. Relancer avec --fix pour écrire.');
            $this->line('Seules les colonnes de déficit sont réécrites : le résultat fiscal déclaré ne bouge pas.');

            return self::SUCCESS;
        }

        $this->info($repaired . ' exercice(s) reconstitué(s) — cases 982/983/984 du 2033-D.');
        $this->line('Les liasses déjà téléchargées gardent l\'ancienne valeur : les régénérer si besoin.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $totals
     * @return array<string, mixed>
     */
    private function deficitColumns(array $totals): array
    {
        return [
            'previous_deficit'     => (int) ($totals['previous_deficit'] ?? 0),
            'deficit_imputed'      => (int) ($totals['deficit_imputed'] ?? 0),
            'deficit_carryforward' => (int) ($totals['deficit_carryforward'] ?? 0),
            'deficit_detail'       => $totals['deficit_detail'] ?? [],
        ];
    }

    /**
     * Forme comparable d'un jeu de colonnes : le détail par millésime est ramené au stock
     * restant. Comparer les tableaux bruts ferait passer pour un écart une simple différence
     * d'ordre des clés relue depuis le JSON de la base.
     *
     * @param  array<string, mixed>  $columns
     * @return array<string, mixed>
     */
    private function comparable(array $columns): array
    {
        return [
            'previous_deficit'     => $columns['previous_deficit'],
            'deficit_imputed'      => $columns['deficit_imputed'],
            'deficit_carryforward' => $columns['deficit_carryforward'],
            'deficit_detail'       => FiscalYearService::normalizeDeficitVintages($columns['deficit_detail']),
        ];
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $computed
     */
    private function reportDrift(FiscalYear $fiscalYear, array $stored, array $computed): void
    {
        $label = $fiscalYear->status === FiscalYear::STATUS_CLOSED ? 'clôturé' : 'brouillon';
        $this->line("Utilisateur #{$fiscalYear->user_id} — exercice {$fiscalYear->year} ({$label})");

        $boxes = [
            'previous_deficit'     => '982 — déficits antérieurs',
            'deficit_imputed'      => '983 — déficits imputés',
            'deficit_carryforward' => '984 — déficits restants',
        ];

        foreach ($boxes as $column => $box) {
            if ($stored[$column] === $computed[$column]) {
                continue;
            }

            $this->line(sprintf(
                '      %s : %s € → %s €',
                $box,
                number_format($stored[$column] / 100, 2, ',', ' '),
                number_format($computed[$column] / 100, 2, ',', ' '),
            ));
        }

        foreach (FiscalYearService::normalizeDeficitVintages($computed['deficit_detail']) as $vintage) {
            $this->line(sprintf(
                '      millésime %d : %s € restants',
                $vintage['origin_year'],
                number_format($vintage['remaining'] / 100, 2, ',', ' '),
            ));
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
}
