<?php

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\Property;
use App\Models\User;

/**
 * Applique le sort réservé aux données d'exemple après une promotion de bac à sable.
 *
 * Trois issues seulement, et le choix ne se fait qu'une fois : c'est une suppression, rien
 * n'est récupérable ensuite.
 */
class DemoSeedChoiceService
{
    public const KEEP_ALL = 'keep_all';

    public const MINE_ONLY = 'mine_only';

    public const RESET = 'reset';

    public const CHOICES = [self::KEEP_ALL, self::MINE_ONLY, self::RESET];

    public function __construct(
        private readonly DemoDataService $demoData,
        private readonly FiscalYearService $fiscalYears,
    ) {
    }

    public function apply(User $user, string $choice): bool
    {
        if (! in_array($choice, self::CHOICES, true) || filled($user->demo_seed_choice)) {
            return false;
        }

        match ($choice) {
            self::KEEP_ALL => null,
            self::MINE_ONLY => $this->dropSample($user),
            self::RESET => $this->demoData->purgeForUser($user),
        };

        $user->forceFill(['demo_seed_choice' => $choice])->save();

        return true;
    }

    /**
     * Supprime le bien d'exemple et les exercices seedés, puis RECALCULE ce qui reste.
     *
     * ⚠️ Les exercices sont au niveau utilisateur, pas du bien, et leurs totaux sont FIGÉS en
     * base. Supprimer le bien d'exemple sans recalculer laisserait donc tout exercice restant
     * sur des montants qui incluent encore ses recettes et ses charges — des chiffres faux,
     * dans une comptabilité, présentés comme justes.
     *
     * `FiscalYearService::computeTotals()` rend les totaux SANS les persister : c'est ce qui
     * permet de recalculer proprement, et c'est aussi le moteur de
     * `openlmnp:repair-orphan-fiscal-years` en cas de rattrapage.
     */
    private function dropSample(User $user): void
    {
        $seed = $user->demo_seed ?? [];

        if (filled($seed['property_id'] ?? null)) {
            // La suppression cascade sur composants, travaux, mobilier, recettes, charges,
            // emprunt et échéances : le bien est le point d'ancrage de tout cela.
            Property::withoutGlobalScopes()
                ->whereKey($seed['property_id'])
                ->where('user_id', $user->id)
                ->delete();
        }

        if (filled($seed['fiscal_year_ids'] ?? null)) {
            FiscalYear::withoutGlobalScopes()
                ->whereKey($seed['fiscal_year_ids'])
                ->where('user_id', $user->id)
                ->delete();
        }

        FiscalYear::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->get()
            ->each(function (FiscalYear $year) {
                $year->update($this->fiscalYears->computeTotals($year));
            });
    }
}
