<?php

namespace App\Services\Ai\Analysis;

use App\Models\AiUsageLog;
use App\Models\User;
use App\Support\AiAccess;
use App\Support\AiQuota;

/**
 * Séquence d'autorisation commune à TOUS les points d'entrée de l'analyse
 * (page Filament, action de formulaire, outil MCP).
 *
 * Elle existe parce qu'AiClient n'applique délibérément PAS les quotas : la
 * vérification appartient à l'appelant (socle § 4.1), et un point d'entrée qui
 * l'oublierait laisserait passer des appels facturés au-delà du plafond.
 * Un seul endroit à auditer, un seul message par situation.
 */
final class AnalysisGuard
{
    /**
     * @return string|null Message français à afficher, ou null si tout est bon.
     */
    public static function check(User $user): ?string
    {
        $access = AiAccess::for($user);

        if (! $access->available()) {
            return $access->unavailableMessage()
                ?? "Les fonctions d'intelligence artificielle ne sont pas disponibles pour ce compte.";
        }

        $quota = AiQuota::for($user, AiQuota::FEATURE_DOCUMENTS);

        if (! $quota->allowed()) {
            // Aucun appel réseau n'a lieu : c'est à nous de tracer le refus,
            // sans quoi la page admin ne verrait jamais les quotas atteints.
            AiUsageLog::recordQuota($user, AiQuota::FEATURE_DOCUMENTS, [
                'limit' => $quota->limit(),
            ]);

            return $quota->exhaustedMessage();
        }

        return null;
    }

    /** Compteur « 12 / 30 » affiché sous le bouton d'analyse, ou null si illimité. */
    public static function remainingLabel(User $user): ?string
    {
        $quota = AiQuota::for($user, AiQuota::FEATURE_DOCUMENTS);
        $limit = $quota->limit();

        if ($limit <= 0) {
            return null;
        }

        return "{$quota->used()} / {$limit} documents analysés ce mois-ci.";
    }
}
