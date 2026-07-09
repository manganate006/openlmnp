<?php

namespace App\Support;

/**
 * Utilitaires pour les événements analytics (GA4 via GTM).
 * RGPD : jamais de valeurs exactes ni de montants — uniquement des tranches.
 */
class Analytics
{
    /**
     * Tranche anonymisée d'un nombre de lignes importées.
     */
    public static function rowsBucket(int $rows): string
    {
        return match (true) {
            $rows <= 0 => '0',
            $rows <= 10 => '1-10',
            $rows <= 50 => '11-50',
            default => '50+',
        };
    }
}
