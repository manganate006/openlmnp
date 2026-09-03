<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Models\PropertyComponent;
use App\Services\DepreciationService;
use Illuminate\Console\Command;

/**
 * Répare les composants d'amortissement désynchronisés du bien qui les porte.
 *
 * Contexte : jusqu'au correctif du wizard d'onboarding, les montants saisis étaient
 * stockés 100 fois trop grands (double conversion euros -> centimes). Un utilisateur qui
 * corrigeait ensuite le prix à la main laissait derrière lui des composants calculés sur
 * l'ancienne valeur : l'amortissement annuel restait 100 fois trop élevé, ce qui fausse
 * le résultat fiscal sans rien afficher d'anormal sur la fiche du bien.
 *
 * Depuis le 2026-09-03, `base_source` dit qui pilote la base d'un composant, ce qui
 * simplifie beaucoup cette commande :
 *
 *   - `percentage` : la base est DÉRIVÉE de la base amortissable du bien. Tout écart est
 *     une désynchronisation, corrigée par `--fix`, sans seuil ni arbitrage.
 *   - `manual`     : la base a été fixée à la main, typiquement pour reproduire le plan
 *     d'un comptable. Elle n'est JAMAIS touchée, sauf `--all` — qui devient donc une
 *     option destructrice, et non plus un simple assouplissement.
 *
 * Avant `base_source`, rien ne distinguait un réglage volontaire d'une corruption : d'où
 * l'ancien seuil « facteur 10 », qui laissait passer les corruptions modestes et menaçait
 * les ajustements importants. Il ne sert plus qu'au rétro-classement des bases créées
 * avant la colonne (DepreciationService::classifyLegacyBaseSource()).
 */
class RepairComponentsCommand extends Command
{
    protected $signature = 'openlmnp:repair-components
                            {--fix : Applique les corrections (sinon simple rapport)}
                            {--all : Resynchronise AUSSI les bases saisies à la main (destructeur)}
                            {--property= : Limite le traitement à un bien}';

    protected $description = 'Détecte et répare les composants d\'amortissement désynchronisés du prix du bien';

    /** Au-delà, un prix relève presque sûrement du bug de double conversion. */
    private const SUSPICIOUS_PRICE_CENTS = 2_000_000_000; // 20 M€

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
        $rows = [];       // bases dérivées désynchronisées : à corriger
        $manual = [];     // bases saisies à la main : à ne pas toucher
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

                $isManual = $component->base_source === PropertyComponent::BASE_SOURCE_MANUAL;

                $row = [
                    $property->id,
                    mb_strimwidth($property->name, 0, 22, '…'),
                    mb_strimwidth($component->name, 0, 22, '…'),
                    number_format((int) $component->base_amount / 100, 0, ',', ' '),
                    number_format($expectedBase / 100, 0, ',', ' '),
                ];

                if ($isManual) {
                    $manual[] = $row;
                } else {
                    $rows[] = $row;
                }

                if ($apply && (! $isManual || $repairAll)) {
                    // `percentage` n'est pas recalculé : pour un composant ventilé il EST
                    // l'entrée du calcul, et le redériver de la base réintroduirait la
                    // troncature qu'on vient d'appliquer.
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

        if ($manual) {
            $this->newLine();
            $this->warn('Bases saisies à la main — ' . ($repairAll ? 'RESYNCHRONISÉES par --all :' : 'non touchées :'));
            $this->table($headers, $manual);
            $this->line($repairAll
                ? '  --all a écrasé ces montants par la ventilation théorique. Si l\'un d\'eux'
                    . ' reproduisait le plan d\'un comptable, il est perdu.'
                : '  Ces montants sont volontaires (reprise d\'une comptabilité existante).'
                    . ' Utiliser --all pour les écraser quand même.');
        }

        if (! $rows) {
            $this->newLine();
            $this->info('Aucune désynchronisation : les bases dérivées suivent le prix du bien.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('Composants désynchronisés du prix du bien :');
        $this->table($headers, $rows);

        if ($apply) {
            $this->info("{$repaired} composant(s) réparé(s).");
        } else {
            $this->warn(count($rows) . ' composant(s) désynchronisé(s). Relancer avec --fix pour corriger.');
        }

        return self::SUCCESS;
    }

    /**
     * Ce que valent la base et la dotation d'un composant ventilé.
     *
     * La formule vit dans DepreciationService, et nulle part ailleurs.
     *
     * @return array{0: int, 1: int}
     */
    private function expectedAmounts(string $base, PropertyComponent $component): array
    {
        $expectedBase = DepreciationService::baseFromPercentage($base, (string) $component->percentage);
        $duration = max(1, (int) $component->duration_years);

        return [
            (int) $expectedBase,
            (int) DepreciationService::annualFromBase($expectedBase, $duration),
        ];
    }
}
