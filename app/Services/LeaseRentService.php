<?php

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Lease;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Génération des loyers d'un bail (issue #24).
 *
 * Même parti que `RecurringExpenseService` : chaque loyer devient une VRAIE ligne
 * `incomes`, captée par tous les calculs sans en toucher un seul, et qui peut porter son
 * justificatif.
 *
 * Les règles, dans l'ordre où elles décident :
 *   - un exercice est une année civile (`fiscal_years` ne porte qu'un `year`) : on génère
 *     une année à la fois, jamais au-delà ;
 *   - un mois ne produit un loyer que si le bail le couvre EN ENTIER. Un mois d'entrée ou
 *     de sortie est partiel, et son montant dépend du bail (jours réels, base 30, clause
 *     particulière) : il est signalé, jamais deviné. Une recette au mauvais montant
 *     fausserait la déclaration sans que rien ne le montre, un mois manquant se voit ;
 *   - le loyer est daté de l'échéance (`due_day`), et c'est cette date qui le rattache à
 *     l'exercice. Une échéance au 29, 30 ou 31 tombe le dernier jour d'un mois plus court ;
 *   - un exercice clôturé porte une déclaration déposée : on refuse d'y écrire.
 *
 * Le montant est loyer + charges, au forfait comme en provisions : c'est ce que verse le
 * locataire, donc la recette BIC. La part des charges est gardée à part, pour l'affichage.
 */
class LeaseRentService
{
    public function __construct(private readonly FiscalYearService $fiscalYears)
    {
    }

    /**
     * Date d'échéance du loyer d'un mois.
     *
     * Un `due_day` de 31 tombe le 30 avril, le 28 février ou le 29 en année bissextile :
     * `setDay(31)` sur février déborderait sur mars.
     */
    public function dueDate(Lease $lease, int $year, int $month): CarbonImmutable
    {
        $first = CarbonImmutable::create($year, $month, 1)->startOfDay();

        return $first->setDay(min((int) $lease->due_day, $first->daysInMonth));
    }

    /**
     * Années pour lesquelles le bail peut générer ses loyers, dans l'ordre.
     *
     * Un bail sans fin s'arrête à l'année en cours : générer l'an prochain d'avance
     * créerait un exercice entier sur une simple hypothèse. Un bail qui commence l'an
     * prochain (signé en décembre pour janvier) garde son année de début.
     *
     * @return list<int>
     */
    public function years(Lease $lease): array
    {
        $first = $this->startDay($lease)->year;
        $last = $this->endDay($lease)?->year ?? max($first, CarbonImmutable::now()->year);

        return range($first, $last);
    }

    /** L'année proposée par défaut : l'année en cours, ramenée dans celles du bail. */
    public function defaultYear(Lease $lease): int
    {
        $years = $this->years($lease);

        return max($years[0], min(CarbonImmutable::now()->year, $years[count($years) - 1]));
    }

    /**
     * Ce que la génération ferait sur une année. Sert l'aperçu de la modale autant que
     * `generate()`.
     *
     * @return array{
     *     rents: list<array{date: CarbonImmutable, exists: bool}>,
     *     partial: list<array{from: CarbonImmutable, to: CarbonImmutable}>,
     *     to_create: int,
     *     existing: int,
     *     total_cents: int
     * }
     */
    public function plan(Lease $lease, int $year): array
    {
        $start = $this->startDay($lease);
        $end = $this->endDay($lease);

        $rents = [];
        $partial = [];

        for ($month = 1; $month <= 12; $month++) {
            $first = CarbonImmutable::create($year, $month, 1)->startOfDay();
            $last = $first->endOfMonth()->startOfDay();

            if ($start->greaterThan($last) || ($end !== null && $end->lessThan($first))) {
                continue;
            }

            if ($start->greaterThan($first) || ($end !== null && $end->lessThan($last))) {
                $partial[] = [
                    'from' => $start->greaterThan($first) ? $start : $first,
                    'to'   => ($end !== null && $end->lessThan($last)) ? $end : $last,
                ];

                continue;
            }

            $rents[] = $this->dueDate($lease, $year, $month);
        }

        $taken = $this->takenMonths($lease, $year);
        $entries = [];
        $toCreate = 0;

        foreach ($rents as $date) {
            $exists = $this->isTaken($taken, $date);
            $entries[] = ['date' => $date, 'exists' => $exists];
            $exists || $toCreate++;
        }

        return [
            'rents'       => $entries,
            'partial'     => $partial,
            'to_create'   => $toCreate,
            'existing'    => count($entries) - $toCreate,
            'total_cents' => (int) bcmul((string) $lease->monthly_total, (string) $toCreate, 0),
        ];
    }

    /**
     * Crée les loyers manquants de l'année, puis recalcule les exercices en brouillon.
     *
     * Le recalcul est fait ICI et non par l'écran : les totaux d'exercice sont stockés en
     * base et rien n'observe `Income`. Un autre appelant (MCP, commande) qui l'oublierait
     * laisserait le tableau de bord et les exercices en désaccord, sans erreur.
     *
     * @return array{created: int, skipped: int}
     *
     * @throws \RuntimeException année hors du bail ou exercice clôturé
     */
    public function generate(Lease $lease, int $year): array
    {
        $this->assertGeneratable($lease, $year);

        $plan = $this->plan($lease, $year);
        $created = 0;

        // Le taux vient du bien, comme pour toute recette locative : un 0 écrit en dur
        // sur un bien assujetti poserait une TVA collectée nulle, sans rien d'observable
        // avant la déclaration (voir `Property::defaultIncomeTvaRate()`).
        $tvaRate = Property::withoutGlobalScopes()->find($lease->property_id)?->defaultIncomeTvaRate() ?? 0;

        DB::transaction(function () use ($plan, $lease, $tvaRate, &$created) {
            foreach ($plan['rents'] as $entry) {
                if ($entry['exists']) {
                    continue;
                }

                Income::create([
                    'property_id'    => $lease->property_id,
                    'lease_id'       => $lease->getKey(),
                    'income_date'    => $entry['date']->toDateString(),
                    'amount'         => $lease->monthly_total,
                    'charges_amount' => (int) $lease->charges_amount,
                    'tva_rate'       => $tvaRate,
                    'platform_fee'   => 0,
                    'tourist_tax'    => 0,
                    'source'         => Income::SOURCE_DIRECT,
                    'guest_name'     => $lease->tenant_name,
                ]);

                $created++;
            }
        });

        if ($created > 0 && ($ownerId = $this->ownerId($lease)) !== null) {
            $this->fiscalYears->recalculateDrafts($ownerId);
        }

        return ['created' => $created, 'skipped' => $plan['existing']];
    }

    /**
     * @throws \RuntimeException
     */
    public function assertGeneratable(Lease $lease, int $year): void
    {
        $years = $this->years($lease);

        if (! in_array($year, $years, true)) {
            throw new \RuntimeException(sprintf(
                'Le bail de %s ne couvre pas %d : les loyers se génèrent de %d à %d.',
                $lease->tenant_name,
                $year,
                $years[0],
                $years[count($years) - 1],
            ));
        }

        if ($this->fiscalYearIsClosed($lease, $year)) {
            throw new \RuntimeException(
                'L\'exercice ' . $year . ' est clôturé : il porte une déclaration déposée. '
                . 'Rouvrez-le avant de générer des loyers.'
            );
        }
    }

    /**
     * Propriétaire du bail, via son bien : l'appelant peut être une console sans session.
     */
    public function ownerId(Lease $lease): ?int
    {
        $userId = Property::withoutGlobalScopes()
            ->whereKey($lease->property_id)
            ->value('user_id');

        return $userId === null ? null : (int) $userId;
    }

    /**
     * Ce qui occupe déjà un mois, sur le bien et l'année.
     *
     * Deux façons d'être déjà là, et ce n'est pas la même clé :
     *   - un loyer RELIÉ à ce bail occupe tout son mois, quelle que soit sa date. Relancer
     *     après avoir changé le jour d'échéance ne doit pas créer un second loyer à côté
     *     du premier ;
     *   - une recette directe saisie à la main, sans bail, n'occupe que sa date, comme une
     *     charge pour `RecurringExpenseService`. Une recette directe un autre jour du mois
     *     peut être autre chose qu'un loyer : on ne la prend pas pour lui.
     *
     * ⚠️ Comparaison en PHP : le cast `date` persiste `Y-m-d H:i:s`, un `whereIn` sur des
     * dates nues ne remonterait rien (voir `RecurringExpenseService::existingDates()`).
     *
     * @return array{months: list<int>, days: list<string>}
     */
    private function takenMonths(Lease $lease, int $year): array
    {
        $incomes = Income::withoutGlobalScopes()
            ->where('property_id', $lease->property_id)
            ->whereBetween('income_date', [
                CarbonImmutable::create($year, 1, 1)->startOfDay()->toDateTimeString(),
                CarbonImmutable::create($year, 12, 31)->endOfDay()->toDateTimeString(),
            ])
            ->where(fn ($query) => $query
                ->when($lease->exists, fn ($query) => $query->where('lease_id', $lease->getKey()))
                ->orWhere(fn ($query) => $query->whereNull('lease_id')->where('source', Income::SOURCE_DIRECT)))
            ->get(['lease_id', 'income_date']);

        $months = [];
        $days = [];

        foreach ($incomes as $income) {
            $date = CarbonImmutable::parse($income->income_date);

            if ($income->lease_id !== null) {
                $months[] = $date->month;
            } else {
                $days[] = $date->toDateString();
            }
        }

        return ['months' => array_values(array_unique($months)), 'days' => array_values(array_unique($days))];
    }

    /** @param array{months: list<int>, days: list<string>} $taken */
    private function isTaken(array $taken, CarbonImmutable $date): bool
    {
        return in_array($date->month, $taken['months'], true)
            || in_array($date->toDateString(), $taken['days'], true);
    }

    private function fiscalYearIsClosed(Lease $lease, int $year): bool
    {
        $userId = $this->ownerId($lease);

        if ($userId === null) {
            return false;
        }

        return FiscalYear::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('year', $year)
            ->where('status', FiscalYear::STATUS_CLOSED)
            ->exists();
    }

    private function startDay(Lease $lease): CarbonImmutable
    {
        return CarbonImmutable::parse($lease->start_date)->startOfDay();
    }

    private function endDay(Lease $lease): ?CarbonImmutable
    {
        return $lease->end_date === null ? null : CarbonImmutable::parse($lease->end_date)->startOfDay();
    }
}
