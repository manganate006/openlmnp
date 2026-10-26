<?php

namespace App\Support\Dpe;

use Illuminate\Support\Carbon;

/**
 * Applique `DpeRules` à la configuration de location d'un bien précis.
 *
 * Point de passage unique entre la fiche du bien (formulaire, donc lu depuis l'état en
 * cours de saisie — potentiellement pas encore enregistré) et le modèle `Property` (lu
 * depuis ses colonnes) : les deux appellent ces méthodes plutôt que de dupliquer le choix
 * des usages applicables.
 *
 * Formulation volontairement prudente : « Décent / Non décent », jamais « interdit » — un
 * logement non décent ne rend pas le bail nul, voir DpeRules.
 */
final class PropertyDpeVerdict
{
    /**
     * Verdicts applicables à la configuration de location d'un bien, un par usage pertinent.
     *
     * Bail meublé (décence, art. 6 via 25-3) pour une location longue durée ou mixte ;
     * meublé de tourisme (art. L. 324-2-2 du code du tourisme, en vigueur au 1er janvier
     * 2034 seulement) pour une location saisonnière ou mixte — sauf résidence principale
     * du loueur, non concernée par cette obligation.
     *
     * @return array<string, array{label: string, applies: bool, allowed: bool|null, worst_allowed: string|null, next: array{date: string, worst_allowed: string}|null}>
     */
    public static function forRentalConfiguration(
        ?string $dpeClass,
        ?string $rentalType,
        bool $isPrimaryResidence,
        ?string $postalCode,
        ?string $date = null,
    ): array {
        if (! $dpeClass) {
            return [];
        }

        $date ??= now()->toDateString();
        $overseas = self::overseas($postalCode);

        $verdicts = [];
        foreach (self::usages($rentalType, $isPrimaryResidence) as $usage => $label) {
            $verdicts[$usage] = ['label' => $label] + DpeRules::verdict($dpeClass, $usage, $date, $overseas);
        }

        return $verdicts;
    }

    /** Outre-mer visé par l'article 6 (Guadeloupe, Martinique, Guyane, La Réunion, Mayotte). */
    public static function overseas(?string $postalCode): bool
    {
        return in_array(substr((string) $postalCode, 0, 3), DpeRules::OVERSEAS, true);
    }

    /**
     * État d'expiration d'une date de fin de validité, ou null si rien n'est à signaler.
     *
     * `expired` dès que la date est dépassée ; `soon` dans les six mois qui précèdent — le
     * seuil retenu pour l'alerte de la fiche du bien et du tableau de bord.
     */
    public static function expiryStatus(?string $validUntil): ?string
    {
        if (! $validUntil) {
            return null;
        }

        $validUntil = Carbon::parse($validUntil)->startOfDay();
        $today = Carbon::now()->startOfDay();

        if ($validUntil->lt($today)) {
            return 'expired';
        }

        if ($validUntil->lte($today->copy()->addMonths(6))) {
            return 'soon';
        }

        return null;
    }

    /** @return array<string, string> */
    private static function usages(?string $rentalType, bool $isPrimaryResidence): array
    {
        $usages = [];

        if (in_array($rentalType, ['long_term', 'mixed'], true)) {
            $usages['bail_meuble'] = 'Bail meublé (résidence principale du locataire)';
        }

        if (in_array($rentalType, ['seasonal', 'mixed'], true)) {
            $usages[$isPrimaryResidence ? 'tourisme_residence_principale' : 'tourisme']
                = $isPrimaryResidence
                    ? 'Meublé de tourisme dans votre résidence principale'
                    : 'Meublé de tourisme (hors résidence principale du loueur)';
        }

        return $usages;
    }
}
