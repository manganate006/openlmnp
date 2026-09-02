<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Models\PropertyComponent;
use Illuminate\Console\Command;

/**
 * Répare les composants d'amortissement désynchronisés du bien qui les porte.
 *
 * Contexte : jusqu'au correctif du wizard d'onboarding, les montants saisis
 * étaient stockés 100 fois trop grands (double conversion euros -> centimes).
 * Un utilisateur qui corrigeait ensuite le prix à la main laissait derrière lui
 * des composants calculés sur l'ancienne valeur : l'amortissement annuel restait
 * 100 fois trop élevé, ce qui fausse le résultat fiscal sans rien afficher
 * d'anormal sur la fiche du bien.
 *
 * base_amount et annual_depreciation sont des données DÉRIVÉES (base
 * amortissable x pourcentage du composant) : les recalculer est sans risque et
 * idempotent. Les pourcentages et durées personnalisés sont préservés.
 */
class RepairComponentsCommand extends Command
{
    protected $signature = 'openlmnp:repair-components
                            {--fix : Applique les corrections (sinon simple rapport)}
                            {--all : Répare aussi les écarts modérés, potentiellement volontaires}
                            {--property= : Limite le traitement à un bien}';

    protected $description = 'Détecte et répare les composants d\'amortissement désynchronisés du prix du bien';

    /** Au-delà, un prix relève presque sûrement du bug de double conversion. */
    private const SUSPICIOUS_PRICE_CENTS = 2_000_000_000; // 20 M€

    /**
     * base_amount et annual_depreciation sont modifiables à la main dans l'onglet
     * Composants : un écart avec le calcul théorique peut donc être VOLONTAIRE.
     * Seul un facteur d'au moins 10 signe une corruption — aucun ajustement
     * délibéré ne multiplie une base par 10. En dessous, on se contente de
     * signaler, sauf --all.
     */
    private const CORRUPTION_FACTOR = 10;

    public function handle(): int
    {
        $apply = (bool) $this->option('fix');

        $query = Property::withoutGlobalScopes()->with(['components' => fn ($q) => $q->withoutGlobalScopes()]);

        if ($id = $this->option('property')) {
            $query->whereKey($id);
        }

        $properties = $query->get();

        if ($properties->isEmpty()) {
            $this->info('Aucun bien à examiner.');

            return self::SUCCESS;
        }

        $repairAll = (bool) $this->option('all');
        $repaired = 0;
        $rows = [];       // écarts d'un facteur >= 10 : corruption certaine
        $moderate = [];   // écarts faibles : possiblement volontaires
        $suspicious = [];

        foreach ($properties as $property) {
            $base = $property->depreciable_base; // chaîne bcmath, en centimes

            if ((int) $property->acquisition_price > self::SUSPICIOUS_PRICE_CENTS
                || (int) ($property->market_value ?? 0) > self::SUSPICIOUS_PRICE_CENTS) {
                $suspicious[] = [
                    $property->id,
                    $property->name,
                    number_format((int) $property->acquisition_price / 100, 0, ',', ' ') . ' €',
                    number_format((int) $property->acquisition_price / 10000, 0, ',', ' ') . ' €',
                ];
            }

            foreach ($property->components as $component) {
                [$expectedBase, $expectedAnnual] = $this->expectedAmounts($base, $component);

                if ((int) $component->base_amount === $expectedBase
                    && (int) $component->annual_depreciation === $expectedAnnual) {
                    continue;
                }

                $isCorruption = $this->looksCorrupted((int) $component->base_amount, $expectedBase);

                $row = [
                    $property->id,
                    mb_strimwidth($property->name, 0, 22, '…'),
                    mb_strimwidth($component->name, 0, 22, '…'),
                    number_format((int) $component->base_amount / 100, 0, ',', ' '),
                    number_format($expectedBase / 100, 0, ',', ' '),
                ];

                if ($isCorruption) {
                    $rows[] = $row;
                } else {
                    $moderate[] = $row;
                }

                if ($apply && ($isCorruption || $repairAll)) {
                    $component->forceFill([
                        'base_amount' => $expectedBase,
                        'annual_depreciation' => $expectedAnnual,
                    ])->save();
                    $repaired++;
                }
            }
        }

        if ($suspicious) {
            $this->newLine();
            $this->warn('Biens dont le prix semble affecté par la double conversion (×100) :');
            $this->table(['Bien', 'Nom', 'Prix stocké', 'Prix probable'], $suspicious);
            $this->line('  Ces prix ne sont PAS corrigés automatiquement : rien ne permet de');
            $this->line('  distinguer un montant réel d\'un montant corrompu. À vérifier à la main,');
            $this->line('  puis relancer cette commande pour resynchroniser les composants.');
        }

        $headers = ['Bien', 'Nom', 'Composant', 'Base actuelle (€)', 'Base attendue (€)'];

        if ($moderate) {
            $this->newLine();
            $this->warn('Écarts modérés (facteur < ' . self::CORRUPTION_FACTOR . ') — possiblement volontaires :');
            $this->table($headers, $moderate);
            $this->line('  base_amount est modifiable à la main : ces écarts peuvent être délibérés.');
            $this->line('  Ils ne sont PAS corrigés sans --all.');
        }

        if (! $rows) {
            $this->newLine();
            $this->info('Aucune corruption manifeste : rien à réparer d\'office.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('Corruption manifeste (facteur >= ' . self::CORRUPTION_FACTOR . ') :');
        $this->table($headers, $rows);

        if ($apply) {
            $this->info("{$repaired} composant(s) réparé(s).");
        } else {
            $this->warn(count($rows) . ' composant(s) corrompu(s). Relancer avec --fix pour corriger.');
        }

        return self::SUCCESS;
    }

    /**
     * Reproduit exactement le calcul de DepreciationService::generateDefaultComponents().
     *
     * @return array{0: int, 1: int}
     */
    private function expectedAmounts(string $base, PropertyComponent $component): array
    {
        $expectedBase = bcmul($base, bcdiv((string) $component->percentage, '100', 10), 0);
        $duration = max(1, (int) $component->duration_years);
        $expectedAnnual = bcdiv($expectedBase, (string) $duration, 0);

        return [(int) $expectedBase, (int) $expectedAnnual];
    }

    /**
     * Un écart d'au moins un facteur 10 (dans un sens ou dans l'autre) ne peut pas
     * résulter d'un ajustement manuel raisonnable.
     */
    private function looksCorrupted(int $stored, int $expected): bool
    {
        if ($expected === 0) {
            return $stored !== 0;
        }

        if ($stored === 0) {
            return true;
        }

        $ratio = $stored / $expected;

        return $ratio >= self::CORRUPTION_FACTOR || $ratio <= 1 / self::CORRUPTION_FACTOR;
    }
}
