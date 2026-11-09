<?php

namespace App\Support;

/**
 * Règles micro-BIC (CGI art. 50-0) : taux d'abattement et plafond de chiffre
 * d'affaires, selon le classement du meublé et l'année de perception des revenus.
 *
 * SEULE source de ces taux et plafonds dans l'application — les valeurs vivent dans
 * `config/micro_bic.php`, jamais recopiées en dur ailleurs (`FiscalOverview`,
 * `FiscalYearWizard`, `Projection`, `GetProjection`, `CompareMicroBic`,
 * `GetSimulation`, `Simulator`).
 */
final class MicroBicRules
{
    public const UNCLASSIFIED = 'furnished_tourism_unclassified';
    public const CLASSIFIED = 'furnished_tourism_classified';
    public const LONG_TERM = 'long_term';

    /**
     * @return list<string>
     */
    public static function classifications(): array
    {
        return [self::UNCLASSIFIED, self::CLASSIFIED, self::LONG_TERM];
    }

    public static function labels(): array
    {
        return [
            self::UNCLASSIFIED => 'Meublé de tourisme non classé',
            self::CLASSIFIED => 'Meublé de tourisme classé',
            self::LONG_TERM => 'Location meublée (bail)',
        ];
    }

    /**
     * Valeur par défaut raisonnable d'après le type de location — jamais une
     * certitude : seul un classement officiel (préfecture / Atout France) fait foi.
     * En cas de doute (saisonnier, mixte), on retient le classement le plus
     * restrictif (non classé) plutôt que de surestimer le plafond disponible.
     */
    public static function defaultClassificationForRentalType(?string $rentalType): string
    {
        return $rentalType === 'long_term' ? self::LONG_TERM : self::UNCLASSIFIED;
    }

    /**
     * Taux d'abattement applicable, en points de pourcentage (chaîne bcmath).
     */
    public static function rate(string $classification, int $year): string
    {
        return (string) (self::scheduleFor($year)[$classification]['rate'] ?? 50);
    }

    /**
     * Plafond de chiffre d'affaires, en centimes. `0` = aucun plafond connu.
     */
    public static function ceilingCents(string $classification, int $year): int
    {
        return (int) (self::scheduleFor($year)[$classification]['ceiling'] ?? 0);
    }

    /**
     * Retrouve un plafond à partir d'un taux brut (compatibilité avec l'ancien
     * paramètre "50"/"30" encore accepté par le simulateur et les outils MCP).
     * `null` si aucun classement connu de l'année ne porte ce taux.
     */
    public static function ceilingForRate(string $ratePercent, int $year): ?int
    {
        foreach (self::scheduleFor($year) as $rules) {
            if ((string) $rules['rate'] === (string) $ratePercent) {
                return (int) $rules['ceiling'];
            }
        }

        return null;
    }

    public static function isAvailable(string $classification, int $year, int $grossIncomeCents): bool
    {
        $ceiling = self::ceilingCents($classification, $year);

        return $ceiling <= 0 || $grossIncomeCents <= $ceiling;
    }

    /**
     * `null` si le micro-BIC reste possible, sinon le motif à afficher.
     */
    public static function unavailableReason(string $classification, int $year, int $grossIncomeCents): ?string
    {
        if (self::isAvailable($classification, $year, $grossIncomeCents)) {
            return null;
        }

        $ceiling = self::ceilingCents($classification, $year);
        $label = self::labels()[$classification] ?? $classification;

        return sprintf(
            'CA %s € supérieur au plafond du micro-BIC %s (%s € pour %d) : le régime réel est obligatoire.',
            number_format($grossIncomeCents / 100, 0, ',', ' '),
            mb_strtolower($label),
            number_format($ceiling / 100, 0, ',', ' '),
            $year,
        );
    }

    /**
     * Disponibilité pour un taux brut ("50"/"30"), même logique que `isAvailable()`.
     */
    public static function isAvailableForRate(string $ratePercent, int $year, int $grossIncomeCents): bool
    {
        $ceiling = self::ceilingForRate($ratePercent, $year);

        return $ceiling === null || $ceiling <= 0 || $grossIncomeCents <= $ceiling;
    }

    public static function unavailableReasonForRate(string $ratePercent, int $year, int $grossIncomeCents): ?string
    {
        if (self::isAvailableForRate($ratePercent, $year, $grossIncomeCents)) {
            return null;
        }

        $ceiling = self::ceilingForRate($ratePercent, $year);

        return sprintf(
            'CA %s € supérieur au plafond du micro-BIC à %s%% (%s € pour %d) : le régime réel est obligatoire.',
            number_format($grossIncomeCents / 100, 0, ',', ' '),
            $ratePercent,
            number_format((int) $ceiling / 100, 0, ',', ' '),
            $year,
        );
    }

    /**
     * Barème applicable à une année de revenus : reprend le dernier millésime
     * publié dont l'année est ≤ `$year` (ou le plus ancien connu si l'année
     * demandée leur est antérieure à tous).
     *
     * @return array<string, array{rate: int, ceiling: int}>
     */
    private static function scheduleFor(int $year): array
    {
        $schedule = config('micro_bic.schedule', []);

        if ($schedule === []) {
            return [];
        }

        $years = array_keys($schedule);
        sort($years);

        $applicable = $years[0];
        foreach ($years as $y) {
            if ($y <= $year) {
                $applicable = $y;
            }
        }

        return $schedule[$applicable];
    }
}
