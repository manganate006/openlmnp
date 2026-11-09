<?php

namespace App\Services\Ai\Analysis;

/**
 * Contrôles de cohérence d'un tableau d'amortissement importé (CDC § 5.6).
 *
 * Un tableau lu par un modèle peut perdre une ligne ou décaler une colonne :
 * ces trois contrôles, tous en bcmath, attrapent l'essentiel avant que
 * l'utilisateur ne valide des intérêts faux — qui alimenteraient directement
 * son résultat fiscal via Loan::getInterestsForYear().
 *
 * Un écart n'empêche jamais l'import : il produit un AVERTISSEMENT, parce qu'un
 * différé d'amortissement ou une modulation d'échéance en produit légitimement.
 */
final class ScheduleChecker
{
    /** Tolérance sur la somme des capitaux : 1 € (CDC § 5.6). */
    private const TOLERANCE_CENTS = 100;

    /**
     * @param  array<int, array<string, mixed>>  $rows    Échéances normalisées.
     * @param  array<string, mixed>              $values  En-tête du prêt.
     * @return array<int, string>                         Avertissements en français.
     */
    public static function check(array $rows, array $values): array
    {
        $warnings = [];
        $principal = (int) ($values['amount'] ?? 0);
        $expectedMonths = (int) ($values['duration_months'] ?? 0);

        // 1. Somme des capitaux remboursés = capital emprunté.
        if ($principal > 0) {
            $sum = self::sumCapital($rows);
            $gap = (int) bcsub((string) $sum, (string) $principal, 0);

            if (abs($gap) > self::TOLERANCE_CENTS) {
                $warnings[] = sprintf(
                    'La somme des capitaux remboursés (%s €) diffère du capital emprunté (%s €) de %s €. '
                    . 'Le tableau est peut-être incomplet : vérifiez les échéances avant de valider.',
                    self::euros($sum),
                    self::euros($principal),
                    self::euros(abs($gap)),
                );
            }
        }

        // 2. Capital restant dû strictement décroissant.
        if (self::hasIncreasingRemainder($rows)) {
            $warnings[] = 'Le capital restant dû ne décroît pas de façon continue : une ligne a probablement été mal lue.';
        }

        // 3. Nombre d'échéances = durée annoncée.
        if ($expectedMonths > 0 && count($rows) !== $expectedMonths) {
            $warnings[] = sprintf(
                '%d échéance(s) lue(s) pour une durée annoncée de %d mois : différé ou modulation détectés, ou tableau tronqué.',
                count($rows),
                $expectedMonths,
            );
        }

        return $warnings;
    }

    /** @param array<int, array<string, mixed>> $rows */
    public static function sumCapital(array $rows): int
    {
        $sum = '0';

        foreach ($rows as $row) {
            $sum = bcadd($sum, (string) ($row['capital_amount'] ?? 0), 0);
        }

        return (int) $sum;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private static function hasIncreasingRemainder(array $rows): bool
    {
        $previous = null;

        foreach ($rows as $row) {
            $remaining = (int) ($row['remaining_capital'] ?? 0);

            // Une valeur absente (0) n'est pas une anomalie : certaines banques
            // ne publient pas la colonne.
            if ($remaining === 0) {
                continue;
            }

            if ($previous !== null && $remaining > $previous) {
                return true;
            }

            $previous = $remaining;
        }

        return false;
    }

    private static function euros(int $cents): string
    {
        return number_format((float) bcdiv((string) $cents, '100', 2), 2, ',', "\u{202F}");
    }
}
