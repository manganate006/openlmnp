<?php

namespace App\Console\Commands;

use App\Models\AccountingEntry;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\AccountingEntryService;
use Illuminate\Console\Command;

/**
 * Détecte les exercices dont les écritures comptables stockées (celles du FEC) ne sont plus
 * celles que l'application produirait aujourd'hui.
 *
 * Contexte : jusqu'au 30/10/2026, chaque recette créditait 706 du loyer NET des commissions,
 * puis une écriture séparée débitait encore 622 de la commission par 512. La commission
 * sortait deux fois : le résultat comptable du FEC et le solde de banque étaient minorés de
 * toutes les commissions de l'année. La liasse n'était pas touchée (elle ne lit pas ces
 * écritures), seul le FEC l'était.
 *
 * Les écritures sont stockées dans `accounting_entries` et ne sont régénérées qu'au calcul
 * d'un exercice (`FiscalYearService::calculate()`). Un brouillon se remet donc d'aplomb au
 * prochain calcul ; un exercice CLÔTURÉ garde ses écritures figées. Cette commande compare,
 * exercice par exercice, ce qui est stocké à ce que `AccountingEntryService::build()`
 * produirait, sans rien écrire. Avec `--fix`, elle régénère les écritures des brouillons ;
 * celles des exercices clôturés demandent en plus `--closed`, parce qu'elles sont refaites à
 * partir des données ACTUELLES, qui ont pu changer depuis la clôture. Les totaux de
 * l'exercice (résultat, déficits…) ne sont jamais modifiés ici.
 */
class RepairAccountingEntriesCommand extends Command
{
    protected $signature = 'openlmnp:repair-accounting-entries
                            {--fix : Régénère les écritures (sinon simple rapport)}
                            {--closed : Régénère AUSSI les écritures des exercices clôturés}
                            {--user= : Limite le traitement à un utilisateur (id ou email)}';

    protected $description = 'Détecte les exercices dont les écritures comptables (FEC) ne correspondent plus au calcul actuel';

    public function handle(AccountingEntryService $entries): int
    {
        $users = $this->targetUsers();

        if ($users->isEmpty()) {
            $this->error('Aucun utilisateur correspondant.');

            return self::FAILURE;
        }

        $stale = [];

        foreach ($users as $user) {
            foreach ($this->fiscalYearsOf($user) as $fiscalYear) {
                $drift = $this->drift($fiscalYear, $entries);

                if ($drift !== null) {
                    $stale[] = ['year' => $fiscalYear, 'drift' => $drift];
                }
            }
        }

        if ($stale === []) {
            $this->info('Toutes les écritures comptables correspondent au calcul actuel.');

            return self::SUCCESS;
        }

        $this->report($stale);

        $closed = array_filter($stale, fn (array $row) => $row['year']->status === FiscalYear::STATUS_CLOSED);

        if (! $this->option('fix')) {
            $this->newLine();
            $this->warn('Rapport seul. Relancer avec --fix pour régénérer les écritures.');

            if ($closed !== []) {
                $this->warn('Les exercices clôturés demandent --fix --closed : leurs écritures seraient refaites à partir des données actuelles.');
            }

            return self::SUCCESS;
        }

        $repaired = 0;
        $skipped = 0;

        foreach ($stale as $row) {
            $fiscalYear = $row['year'];

            if ($fiscalYear->status === FiscalYear::STATUS_CLOSED && ! $this->option('closed')) {
                $skipped++;

                continue;
            }

            $entries->generateForFiscalYear($fiscalYear);
            $repaired++;
        }

        $this->newLine();
        $this->info("{$repaired} exercice(s) dont les écritures ont été régénérées.");

        if ($skipped > 0) {
            $this->warn("{$skipped} exercice(s) clôturé(s) laissé(s) en l'état. Ajouter --closed pour les régénérer aussi.");
        }

        return self::SUCCESS;
    }

    /**
     * Écart entre les écritures stockées et celles que le calcul produirait, ou null.
     *
     * La comparaison porte sur l'ensemble des lignes (compte, montants, pièce, journal,
     * date, libellé), sans tenir compte de leur ordre ni de leur identifiant.
     *
     * @return array{stored: int, computed: int, result_stored: int, result_computed: int, bank_stored: int, bank_computed: int}|null
     */
    private function drift(FiscalYear $fiscalYear, AccountingEntryService $entries): ?array
    {
        $stored = AccountingEntry::withoutGlobalScopes()->where('fiscal_year_id', $fiscalYear->id)->get()
            ->map(fn (AccountingEntry $e) => $this->normalize($e->getAttributes() + ['entry_date' => $e->entry_date]))
            ->all();
        $computed = array_map(fn (array $e) => $this->normalize($e), $entries->build($fiscalYear));

        if ($stored === [] && $computed === []) {
            return null;
        }

        $sortedStored = $stored;
        $sortedComputed = $computed;
        sort($sortedStored);
        sort($sortedComputed);

        if ($sortedStored === $sortedComputed) {
            return null;
        }

        return [
            'stored'          => count($stored),
            'computed'        => count($computed),
            'result_stored'   => $this->result($stored),
            'result_computed' => $this->result($computed),
            'bank_stored'     => $this->balance($stored, '512'),
            'bank_computed'   => $this->balance($computed, '512'),
        ];
    }

    /** Une ligne d'écriture réduite à ce qui compte, sérialisée pour la comparaison. */
    private function normalize(array $entry): string
    {
        $date = $entry['entry_date'];
        $date = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr((string) $date, 0, 10);

        return implode("\x1f", [
            (int) $entry['property_id'],
            $date,
            (string) $entry['account_code'],
            (int) $entry['debit'],
            (int) $entry['credit'],
            (string) $entry['piece_ref'],
            (string) $entry['journal'],
            (string) $entry['label'],
        ]);
    }

    /** Résultat comptable : produits (classe 7) − charges (classe 6). */
    private function result(array $lines): int
    {
        return -$this->balance($lines, '7') - $this->balance($lines, '6');
    }

    /** Solde débit − crédit des comptes commençant par $prefix. */
    private function balance(array $lines, string $prefix): int
    {
        $balance = 0;

        foreach ($lines as $line) {
            [, , $account, $debit, $credit] = explode("\x1f", $line);

            if (str_starts_with($account, $prefix)) {
                $balance += (int) $debit - (int) $credit;
            }
        }

        return $balance;
    }

    /** @param  array<int, array{year: FiscalYear, drift: array}>  $stale */
    private function report(array $stale): void
    {
        $euro = fn (int $cents) => number_format($cents / 100, 2, ',', ' ') . ' €';

        $this->line(count($stale) . ' exercice(s) dont les écritures diffèrent du calcul actuel :');
        $this->newLine();

        foreach ($stale as $row) {
            $fiscalYear = $row['year'];
            $d = $row['drift'];
            $label = $fiscalYear->status === FiscalYear::STATUS_CLOSED ? 'clôturé' : 'brouillon';
            $this->line("  Utilisateur #{$fiscalYear->user_id} — exercice {$fiscalYear->year} ({$label})");
            $this->line("      lignes : {$d['stored']} → {$d['computed']}");
            $this->line('      résultat comptable : ' . $euro($d['result_stored']) . ' → ' . $euro($d['result_computed']));
            $this->line('      solde banque (512) : ' . $euro($d['bank_stored']) . ' → ' . $euro($d['bank_computed']));
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
