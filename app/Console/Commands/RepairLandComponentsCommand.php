<?php

namespace App\Console\Commands;

use App\Models\FiscalYear;
use App\Models\PropertyComponent;
use App\Services\FiscalYearService;
use Illuminate\Console\Command;

/**
 * Supprime les composants « Terrains » importés à tort comme immobilisations amortissables.
 *
 * Contexte : jusqu'au correctif, `RepriseDossier::applyLiasse()` recopiait TOUTES les lignes
 * du tableau 2033-C lues par l'IA, terrain compris. Un terrain ne s'amortit jamais, mais rien
 * ne l'excluait : faute de dotation imprimée pour une ligne qui n'en a pas, l'assistant lui
 * complétait une durée par défaut de 10 ans, et le terrain se mettait à « s'amortir » —
 * constaté lors d'un test de reprise (ligne « Terrains », case 420 du 2033-C).
 *
 * Un composant « Terrains » n'a AUCUNE existence légitime dans `property_components` : la base
 * amortissable exclut déjà le terrain séparément, via `land_percentage` sur le bien (voir le
 * docblock de `PropertyComponent::CERFA_CATEGORY_LAND`). Contrairement à
 * `openlmnp:repair-components`, qui corrige des MONTANTS, cette commande supprime des LIGNES
 * qui n'auraient jamais dû exister — supprimer ne peut jamais faire déborder une ventilation
 * (`syncComponents()` ne refuse que le SUR-allocation).
 */
class RepairLandComponentsCommand extends Command
{
    protected $signature = 'openlmnp:repair-land-components
                            {--fix : Supprime les composants concernés (sinon simple rapport)}
                            {--property= : Limite le traitement à un bien}';

    protected $description = 'Détecte et supprime les composants "Terrains" importés à tort comme amortissables';

    /** Même expression que Normalizer::liasseRows() — un terrain, jamais autre chose. */
    private const LAND_LABEL_PATTERN = '/\bterrains?\b/iu';

    public function handle(FiscalYearService $fiscalYears): int
    {
        $apply = (bool) $this->option('fix');

        $query = PropertyComponent::withoutGlobalScopes()->with('property');

        if ($id = $this->option('property')) {
            $query->whereHas('property', fn ($q) => $q->withoutGlobalScopes()->whereKey($id));
        }

        $components = $query->get()->filter(
            fn (PropertyComponent $c) => preg_match(self::LAND_LABEL_PATTERN, (string) $c->name) === 1
        );

        if ($components->isEmpty()) {
            $this->info('Aucun composant "Terrains" : rien à faire.');

            return self::SUCCESS;
        }

        $rows = [];
        $userIds = [];

        foreach ($components as $component) {
            $property = $component->property;

            $rows[] = [
                $property?->id,
                mb_strimwidth((string) $property?->name, 0, 22, '…'),
                $component->name,
                number_format($component->base_amount / 100, 0, ',', ' ') . ' €',
                $component->duration_years,
                number_format($component->annual_depreciation / 100, 0, ',', ' ') . ' €/an',
            ];

            if ($apply) {
                if ($property?->user_id) {
                    $userIds[$property->user_id] = true;
                }

                $component->delete();
            }
        }

        $this->newLine();
        $this->warn('Composants "Terrains" amortis à tort :');
        $this->table(['Bien', 'Nom', 'Composant', 'Base', 'Durée', 'Dotation annuelle'], $rows);

        if (! $apply) {
            $this->warn(count($rows) . ' composant(s) à supprimer. Relancer avec --fix pour corriger.');

            return self::SUCCESS;
        }

        // Les exercices figent leurs totaux : sans recalcul, l'utilisateur continue de voir
        // l'ancienne dotation (fictive) du terrain dans son résultat fiscal.
        $recalculated = 0;
        $closed = [];

        foreach (array_keys($userIds) as $userId) {
            $years = FiscalYear::withoutGlobalScopes()->where('user_id', $userId)->orderBy('year')->get();

            foreach ($years as $year) {
                if ($year->status === FiscalYear::STATUS_CLOSED) {
                    $closed[] = [$userId, $year->year, number_format($year->total_depreciation / 100, 0, ',', ' ') . ' €'];

                    continue;
                }

                $fiscalYears->calculate($year, force: true);
                $recalculated++;
            }
        }

        $this->info(count($rows) . " composant(s) supprimé(s), {$recalculated} exercice(s) recalculé(s).");

        if ($closed) {
            $this->newLine();
            $this->warn('Exercices CLÔTURÉS non recalculés (une déclaration déposée ne se réécrit pas en silence) :');
            $this->table(['Utilisateur', 'Année', 'Dotation figée'], $closed);
        }

        return self::SUCCESS;
    }
}
