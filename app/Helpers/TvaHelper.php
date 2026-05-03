<?php

namespace App\Helpers;

/**
 * Utilitaire de calcul TVA.
 *
 * Tous les montants en centimes, taux en points de base (2000 = 20 %).
 */
class TvaHelper
{
    /**
     * À partir d'un montant TTC et d'un taux TVA, calcule HT et TVA.
     *
     * Formule : HT = TTC / (1 + taux/10000), TVA = TTC - HT
     *
     * @return array{ht: int, tva: int}
     */
    public static function fromTtc(int $amountTtc, int $tvaRate): array
    {
        if ($tvaRate === 0) {
            return ['ht' => $amountTtc, 'tva' => 0];
        }

        $rateFraction = bcdiv((string) $tvaRate, '10000', 10);
        $divisor = bcadd('1', $rateFraction, 10);
        $ht = (int) bcdiv((string) $amountTtc, $divisor, 0);
        $tva = $amountTtc - $ht;

        return ['ht' => $ht, 'tva' => $tva];
    }

    /**
     * À partir d'un montant HT et d'un taux TVA, calcule TTC et TVA.
     *
     * @return array{ttc: int, tva: int}
     */
    public static function fromHt(int $amountHt, int $tvaRate): array
    {
        if ($tvaRate === 0) {
            return ['ttc' => $amountHt, 'tva' => 0];
        }

        $rateFraction = bcdiv((string) $tvaRate, '10000', 10);
        $tva = (int) bcmul((string) $amountHt, $rateFraction, 0);
        $ttc = $amountHt + $tva;

        return ['ttc' => $ttc, 'tva' => $tva];
    }

    /**
     * Formate un montant en centimes pour l'affichage (ex : "1 234,56 €").
     */
    public static function formatEuros(int $centimes): string
    {
        return number_format($centimes / 100, 2, ',', ' ') . ' €';
    }
}
