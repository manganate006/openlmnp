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

    /**
     * Tranche de confiance d'une analyse IA.
     *
     * Une tranche, jamais le score exact : associé au type de document, un
     * score continu identifierait des documents précis d'un compte précis.
     */
    public static function confidenceBucket(float $confidence): string
    {
        return match (true) {
            $confidence >= 0.85 => 'high',
            $confidence >= 0.6 => 'medium',
            default => 'low',
        };
    }
}
