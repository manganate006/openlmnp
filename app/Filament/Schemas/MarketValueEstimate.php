<?php

namespace App\Filament\Schemas;

use App\Services\OpenData\DvfUnavailable;
use App\Services\OpenData\MarketValueEstimator;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\HtmlString;

/**
 * Action « Estimer (DVF) », branchée sur le champ `market_value` du formulaire d'un bien.
 *
 * ⚠️ APPEL SORTANT, ET IL RÉVÈLE LA COMMUNE DU BIEN à data.gouv.fr. D'où une ACTION
 * EXPLICITE et jamais un calcul au chargement : ouvrir la fiche d'un bien ne doit rien
 * envoyer nulle part. `DVF_ENABLED=false` retire l'action entièrement.
 *
 * ⚠️ DEUX CONTEXTES DE FORMULAIRE, à ne pas confondre. `fillForm()` et `action()` s'exécutent
 * dans le schéma PARENT (le bien) ; les callbacks des composants de la modale, dans le sien.
 * On ne peut pas lire le parent depuis la modale en déclarant deux paramètres `Get` : ils
 * sont injectés PAR TYPE et recevraient le même objet. L'état nécessaire du bien est donc
 * recopié au montage dans des champs cachés, et tout le reste ne lit que la modale.
 *
 * ⚠️ Pas une seule classe CSS ici : le panel ne sert aucun utilitaire Tailwind
 * (`PanelStylesheetTest`). HTML sémantique uniquement, mis en forme par Filament.
 */
class MarketValueEstimate
{
    public static function action(): Action
    {
        return Action::make('estimateMarketValue')
            ->label('Estimer (DVF)')
            ->icon('heroicon-m-map-pin')
            ->visible(fn () => MarketValueEstimator::enabled())
            ->modalHeading('Estimer la valeur vénale')
            ->modalDescription(
                'D\'après les ventes réelles de la commune, publiées par la DGFiP (données DVF, '
                .'data.gouv.fr). Votre commune est transmise à data.gouv.fr ; aucun montant ne l\'est.'
            )
            ->modalSubmitActionLabel('Reprendre cette valeur')
            ->fillForm(fn (Get $get) => [
                'insee' => $get('insee_code'),
                'year' => self::defaultYear($get),
                'area' => (int) ($get('total_area') ?: 0),
                'property_type' => (string) ($get('type') ?: 'apartment'),
                'query' => trim((string) ($get('postal_code') ?: $get('city'))),
            ])
            ->schema([
                Hidden::make('area'),
                Hidden::make('property_type'),
                Hidden::make('query'),
                Select::make('insee')
                    ->label('Commune')
                    ->options(fn (Get $get) => self::communeOptions((string) $get('query')))
                    ->searchable()
                    ->live()
                    ->helperText('DVF est publié par commune — et par arrondissement à Paris, Lyon et Marseille.'),
                Select::make('year')
                    ->label('Année de référence')
                    ->options(fn () => array_combine(MarketValueEstimator::years(), MarketValueEstimator::years()))
                    ->live()
                    ->helperText('Prenez l\'année de mise en location : c\'est elle qui fixe la base amortissable.'),
                Placeholder::make('result')
                    ->label('Résultat')
                    ->content(fn (Get $get) => self::summary(self::estimate([
                        'insee' => $get('insee'),
                        'year' => $get('year'),
                        'area' => $get('area'),
                        'property_type' => $get('property_type'),
                    ]))),
            ])
            ->action(function (array $data, Set $set) {
                $estimate = self::estimate($data);

                if ($estimate === null || ! ($estimate['enough'] ?? false)) {
                    Notification::make()
                        ->title('Aucune estimation exploitable')
                        ->body('Pas assez de ventes comparables : la valeur vénale n\'a pas été modifiée.')
                        ->warning()
                        ->send();

                    return;
                }

                // Le champ `market_value` affiche des EUROS et reconvertit en centimes à
                // l'enregistrement (`dehydrateStateUsing`) : on écrit donc des euros.
                $set('market_value', (string) intdiv($estimate['value_cents'], 100));
                $set('market_value_date', now()->toDateString());
                $set('insee_code', $data['insee']);

                Notification::make()
                    ->title('Valeur vénale estimée reprise')
                    ->body('Conservez le détail de l\'échantillon : c\'est lui qui justifie le chiffre.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Communes candidates pour l'adresse du bien.
     *
     * @return array<string, string>
     */
    private static function communeOptions(string $query): array
    {
        if ($query === '') {
            return [];
        }

        try {
            $communes = app(MarketValueEstimator::class)->communes($query);
        } catch (DvfUnavailable) {
            return [];
        }

        $options = [];
        foreach ($communes as $commune) {
            $options[$commune['code']] = $commune['nom'].' ('.$commune['departement'].', '.$commune['code'].')';
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $data  état de la modale
     * @return array<string, mixed>|null
     */
    private static function estimate(array $data): ?array
    {
        $insee = (string) ($data['insee'] ?? '');
        $area = (int) ($data['area'] ?? 0);

        if ($insee === '' || $area <= 0) {
            return null;
        }

        try {
            return app(MarketValueEstimator::class)->estimate(
                $insee,
                (string) ($data['property_type'] ?? 'apartment'),
                $area,
                ((int) ($data['year'] ?? 0)) ?: null,
            );
        } catch (DvfUnavailable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>|null  $estimate
     */
    private static function summary(?array $estimate): HtmlString
    {
        if ($estimate === null) {
            return new HtmlString('<p>Choisissez une commune pour lancer l\'estimation.</p>');
        }

        if (isset($estimate['error'])) {
            return new HtmlString('<p>'.e($estimate['error']).'</p>');
        }

        $years = implode(', ', $estimate['years']);

        if (! $estimate['enough']) {
            return new HtmlString(sprintf(
                '<p><strong>Pas assez de ventes comparables</strong> : %d sur le%s millésime%s %s, '
                .'pour un minimum de %d. Une médiane sur si peu de transactions serait trompeuse.</p>',
                $estimate['sample_size'],
                count($estimate['years']) > 1 ? 's' : '',
                count($estimate['years']) > 1 ? 's' : '',
                e($years),
                $estimate['minimum'],
            ));
        }

        return new HtmlString(sprintf(
            '<p><strong>%s €</strong> pour %d m², soit %s €/m².</p>'
            .'<p>Médiane de %d ventes mono-lot (%s), millésime%s %s.</p>'
            .'<p><em>Ce n\'est pas une expertise</em> : une médiane communale ignore l\'étage, '
            .'l\'état et le DPE. Conservez ce détail, c\'est lui qui justifie le chiffre.</p>'
            .'<p>Source : Demandes de valeurs foncières (DGFiP), data.gouv.fr, Licence Ouverte 2.0.</p>',
            number_format(intdiv($estimate['value_cents'], 100), 0, ',', ' '),
            $estimate['area_m2'],
            number_format(intdiv($estimate['price_per_m2_cents'], 100), 0, ',', ' '),
            $estimate['sample_size'],
            e($estimate['type']),
            count($estimate['years']) > 1 ? 's' : '',
            e($years),
        ));
    }

    private static function defaultYear(Get $get): ?int
    {
        $years = MarketValueEstimator::years();
        $reference = $get('market_value_date') ?: $get('rental_start_date');
        $year = $reference ? (int) substr((string) $reference, 0, 4) : null;

        return $year !== null && in_array($year, $years, true) ? $year : ($years[0] ?? null);
    }
}
