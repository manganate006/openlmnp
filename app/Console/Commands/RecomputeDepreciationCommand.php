<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Models\PropertyComponent;
use App\Services\DepreciationService;
use Illuminate\Console\Command;

/**
 * Resynchronise les DOTATIONS figées en base avec la règle de calcul en vigueur.
 *
 * Les composants, travaux et meubles portent leur `annual_depreciation` en base : c'est
 * une donnée dérivée, mais STOCKÉE. Un correctif de calcul ne se propage donc pas tout
 * seul aux bases existantes — d'où cette commande, sur le modèle d'`openlmnp:repair-components`.
 *
 * Ce qu'elle fait, et ce qu'elle ne fait pas :
 *
 *   - elle ne touche JAMAIS une base (`base_amount`) : c'est le domaine
 *     d'`openlmnp:repair-components`, et les deux commandes ne doivent pas se marcher dessus ;
 *   - elle ne touche JAMAIS une dotation déclarée manuelle (`base_source = manual` côté
 *     composants, `depreciation_source = manual` côté travaux et mobilier). C'est
 *     exactement ce qu'un utilisateur qui reprend la comptabilité de son cabinet a saisi
 *     à la main, et l'écraser lui ferait perdre la fidélité à sa liasse ;
 *   - elle SIGNALE, sans jamais y toucher, les cumuls d'ouverture qui dépassent la valeur
 *     brute de l'actif : c'est le symptôme d'un double comptage (des exercices repris ET
 *     un stock d'ouverture pour les mêmes années).
 *
 * Convention commune aux commandes de réparation : rapport par défaut, `--fix` pour agir.
 */
class RecomputeDepreciationCommand extends Command
{
    protected $signature = 'openlmnp:recompute-depreciation
                            {--fix : Applique les corrections (sinon simple rapport)}
                            {--property= : Limite le traitement à un bien}';

    protected $description = 'Resynchronise les dotations d\'amortissement figées en base avec la règle en vigueur';

    public function handle(): int
    {
        $apply = (bool) $this->option('fix');

        $query = Property::withoutGlobalScopes()->with([
            'components' => fn ($q) => $q->withoutGlobalScopes(),
            'works' => fn ($q) => $q->withoutGlobalScopes(),
            'furniture' => fn ($q) => $q->withoutGlobalScopes(),
        ]);

        if ($id = $this->option('property')) {
            $query->whereKey($id);
        }

        $properties = $query->get();

        if ($properties->isEmpty()) {
            $this->info('Aucun bien à examiner.');

            return self::SUCCESS;
        }

        $drift = [];      // dotations dérivées qui ne suivent plus la règle
        $protected = [];  // dotations manuelles, laissées telles quelles
        $overOpening = []; // cumuls d'ouverture supérieurs à la valeur brute
        $fixed = 0;

        foreach ($properties as $property) {
            foreach ($property->components as $component) {
                $expected = (int) DepreciationService::annualFromBase(
                    (string) $component->base_amount,
                    max(1, (int) $component->duration_years),
                );

                $isManual = $component->base_source === PropertyComponent::BASE_SOURCE_MANUAL
                    && (int) $component->annual_depreciation > 0;

                if ((int) $component->annual_depreciation !== $expected) {
                    $row = $this->row($property, 'Composant', $component->name, (int) $component->annual_depreciation, $expected);

                    if ($isManual) {
                        $protected[] = $row;
                    } else {
                        $drift[] = $row;

                        if ($apply) {
                            $component->forceFill(['annual_depreciation' => $expected])->save();
                            $fixed++;
                        }
                    }
                }

                $this->checkOpening(
                    $overOpening,
                    $property,
                    'Composant',
                    $component->name,
                    (int) $component->opening_accumulated_depreciation,
                    (int) $component->base_amount,
                );
            }

            foreach ($property->works as $work) {
                $work->setRelation('property', $property);
                $expected = $work->expectedAnnualDepreciation();

                if ((int) $work->annual_depreciation !== $expected) {
                    $row = $this->row($property, 'Travaux', $work->description, (int) $work->annual_depreciation, $expected);

                    if ($work->hasManualDepreciation()) {
                        $protected[] = $row;
                    } else {
                        $drift[] = $row;

                        if ($apply) {
                            $work->forceFill(['annual_depreciation' => $expected])->saveQuietly();
                            $fixed++;
                        }
                    }
                }

                $this->checkOpening(
                    $overOpening,
                    $property,
                    'Travaux',
                    $work->description,
                    (int) $work->opening_accumulated_depreciation,
                    (int) $work->amount,
                );
            }

            foreach ($property->furniture as $item) {
                $item->setRelation('property', $property);
                $expected = $item->expectedAnnualDepreciation();

                if ((int) $item->annual_depreciation !== $expected) {
                    $row = $this->row($property, 'Mobilier', $item->description, (int) $item->annual_depreciation, $expected);

                    if ($item->hasManualDepreciation()) {
                        $protected[] = $row;
                    } else {
                        $drift[] = $row;

                        if ($apply) {
                            $item->forceFill(['annual_depreciation' => $expected])->saveQuietly();
                            $fixed++;
                        }
                    }
                }

                $this->checkOpening(
                    $overOpening,
                    $property,
                    'Mobilier',
                    $item->description,
                    (int) $item->opening_accumulated_depreciation,
                    (int) $item->amount,
                );
            }
        }

        $headers = ['Bien', 'Type', 'Actif', 'Dotation stockée (€)', 'Dotation attendue (€)'];

        if ($protected) {
            $this->newLine();
            $this->warn('Dotations saisies à la main — non touchées :');
            $this->table($headers, $protected);
            $this->line('  Ces montants reproduisent le plan d\'un cabinet comptable. Pour qu\'une');
            $this->line('  ligne redevienne calculée, repasser sa source en « computed » depuis l\'interface.');
        }

        if ($overOpening) {
            $this->newLine();
            $this->error('Cumuls d\'ouverture supérieurs à la valeur brute de l\'actif :');
            $this->table(['Bien', 'Type', 'Actif', 'Cumul d\'ouverture (€)', 'Valeur brute (€)'], $overOpening);
            $this->line('  Symptôme habituel : les exercices concernés ont été repris DANS l\'application');
            $this->line('  ET saisis comme stock d\'ouverture. Le cumul est alors compté deux fois au bilan.');
            $this->line('  Aucune correction automatique : seul l\'utilisateur sait lequel des deux est de trop.');
        }

        if (! $drift) {
            $this->newLine();
            $this->info('Aucune dotation désynchronisée.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('Dotations désynchronisées de la règle de calcul :');
        $this->table($headers, $drift);

        if ($apply) {
            $this->info("{$fixed} dotation(s) recalculée(s).");
        } else {
            $this->warn(count($drift) . ' dotation(s) désynchronisée(s). Relancer avec --fix pour corriger.');
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function row(Property $property, string $type, ?string $label, int $stored, int $expected): array
    {
        return [
            mb_strimwidth((string) $property->name, 0, 22, '…'),
            $type,
            mb_strimwidth((string) $label, 0, 24, '…'),
            number_format($stored / 100, 2, ',', ' '),
            number_format($expected / 100, 2, ',', ' '),
        ];
    }

    /** @param  list<list<string>>  $bucket */
    private function checkOpening(array &$bucket, Property $property, string $type, ?string $label, int $opening, int $gross): void
    {
        if ($opening <= 0 || $gross <= 0 || $opening <= $gross) {
            return;
        }

        $bucket[] = [
            mb_strimwidth((string) $property->name, 0, 22, '…'),
            $type,
            mb_strimwidth((string) $label, 0, 24, '…'),
            number_format($opening / 100, 2, ',', ' '),
            number_format($gross / 100, 2, ',', ' '),
        ];
    }
}
