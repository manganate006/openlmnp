<?php

namespace App\Filament\Schemas;

use App\Services\OpenData\DpeClient;
use App\Services\OpenData\DpeUnavailable;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Action « Retrouver le DPE », branchée sur le champ `dpe_number` du formulaire d'un bien.
 *
 * Même patron que `MarketValueEstimate` : deux contextes de formulaire (parent/modale),
 * état du bien recopié au montage dans des champs cachés — voir son docblock pour le détail
 * de ce piège.
 *
 * ⚠️ JAMAIS de choix silencieux, même à un seul résultat (`TODO-OPENDATA-DPE.md`) : la liste
 * trouvée est toujours présentée pour confirmation, rien n'est écrit avant le clic sur
 * « Reprendre ce DPE ».
 *
 * ⚠️ APPEL SORTANT, ET IL RÉVÈLE L'ADRESSE DU BIEN. Action explicite uniquement ;
 * `DPE_ENABLED=false` retire l'action entièrement.
 *
 * ⚠️ Pas une seule classe CSS ici : le panel ne sert aucun utilitaire Tailwind
 * (`PanelStylesheetTest`). HTML sémantique uniquement, mis en forme par Filament.
 */
class DpeLookup
{
    public static function action(): Action
    {
        return Action::make('lookupDpe')
            ->label('Retrouver le DPE')
            ->icon('heroicon-m-bolt')
            ->visible(fn () => DpeClient::enabled())
            ->modalHeading('Retrouver le DPE du bien')
            ->modalDescription(
                'D\'après la base de l\'ADEME. Votre adresse est transmise à la Géoplateforme '
                .'(IGN) puis à l\'ADEME pour retrouver le DPE ; aucune autre donnée de votre '
                .'dossier ne l\'est.'
            )
            ->modalSubmitActionLabel('Reprendre ce DPE')
            ->fillForm(fn (Get $get) => [
                'address' => (string) $get('address'),
                'postal_code' => (string) $get('postal_code'),
                'city' => (string) $get('city'),
                'insee_code' => (string) $get('insee_code'),
                'number' => '',
                'choice' => null,
            ])
            ->schema([
                Hidden::make('address'),
                Hidden::make('postal_code'),
                Hidden::make('city'),
                Hidden::make('insee_code'),
                TextInput::make('number')
                    ->label('Ou numéro de DPE')
                    ->live(onBlur: true)
                    ->helperText('Si l\'adresse ne trouve rien, ou que vous avez déjà l\'attestation sous les yeux.'),
                Radio::make('choice')
                    ->label('DPE trouvés à cette adresse')
                    ->options(fn (Get $get) => self::radioOptions($get))
                    ->descriptions(fn (Get $get) => self::radioDescriptions($get))
                    ->live(),
                Placeholder::make('result')
                    ->hiddenLabel()
                    ->content(fn (Get $get) => self::summary($get)),
            ])
            ->action(function (array $data, Set $set) {
                $result = self::search($data);
                $row = null;

                foreach ($result['rows'] ?? [] as $candidate) {
                    if ($candidate['numero_dpe'] === ($data['choice'] ?? null)) {
                        $row = $candidate;
                        break;
                    }
                }

                if ($row === null) {
                    Notification::make()
                        ->title('Aucun DPE choisi')
                        ->body('Sélectionnez une ligne dans la liste avant de valider.')
                        ->warning()
                        ->send();

                    return;
                }

                $set('dpe_number', $row['numero_dpe']);
                $set('dpe_class', $row['etiquette_dpe']);
                $set('dpe_established_at', $row['date_etablissement_dpe'] ?: null);
                $set('dpe_valid_until', $row['date_fin_validite_dpe'] ?: null);

                Notification::make()
                    ->title('DPE repris')
                    ->body($row['expired']
                        ? 'Ce DPE est expiré : il reste repris pour mémoire, mais n\'est plus valable.'
                        : 'Classe '.$row['etiquette_dpe'].', valable jusqu\'au '.self::frenchDate($row['date_fin_validite_dpe']).'.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Recherche courante : par numéro si saisi, sinon par adresse.
     *
     * @param  array<string, mixed>  $data
     * @return array{rows?: array<int, array<string, mixed>>, error?: string}
     */
    private static function search(array $data): array
    {
        $number = trim((string) ($data['number'] ?? ''));

        try {
            if ($number !== '') {
                $row = app(DpeClient::class)->byNumber($number);

                return ['rows' => $row ? [$row] : []];
            }

            $address = trim((string) ($data['address'] ?? ''));
            if ($address === '') {
                return ['error' => 'Renseignez d\'abord l\'adresse du bien, dans l\'onglet Identité.'];
            }

            return ['rows' => app(DpeClient::class)->forAddress(
                $address,
                (string) ($data['postal_code'] ?? ''),
                (string) ($data['city'] ?? ''),
                ($data['insee_code'] ?? null) ?: null,
            )];
        } catch (DpeUnavailable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private static function currentSearch(Get $get): array
    {
        return self::search([
            'address' => $get('address'),
            'postal_code' => $get('postal_code'),
            'city' => $get('city'),
            'insee_code' => $get('insee_code'),
            'number' => $get('number'),
        ]);
    }

    /** @return array<string, string> */
    private static function radioOptions(Get $get): array
    {
        $options = [];

        foreach (self::currentSearch($get)['rows'] ?? [] as $row) {
            $options[$row['numero_dpe']] = sprintf(
                'Classe %s — établi le %s',
                $row['etiquette_dpe'],
                self::frenchDate($row['date_etablissement_dpe'])
            );
        }

        return $options;
    }

    /** @return array<string, string> */
    private static function radioDescriptions(Get $get): array
    {
        $descriptions = [];

        foreach (self::currentSearch($get)['rows'] ?? [] as $row) {
            $parts = array_filter([
                $row['type_batiment'],
                ($row['numero_etage_appartement'] ?? 0) > 0 ? 'étage '.$row['numero_etage_appartement'] : null,
                $row['complement_adresse_logement'],
                $row['surface_habitable_logement'] ? $row['surface_habitable_logement'].' m²' : null,
                ($row['expired'] ? 'EXPIRÉ le ' : 'valable jusqu\'au ').self::frenchDate($row['date_fin_validite_dpe']),
                'n° '.$row['numero_dpe'],
            ]);

            $descriptions[$row['numero_dpe']] = implode(' · ', $parts);
        }

        return $descriptions;
    }

    private static function summary(Get $get): HtmlString
    {
        $result = self::currentSearch($get);

        if (isset($result['error'])) {
            return new HtmlString('<p>'.e($result['error']).'</p>');
        }

        if (($result['rows'] ?? []) === []) {
            return new HtmlString(
                '<p>Aucun DPE de la base ADEME (établis depuis juillet 2021) n\'a été trouvé à '
                .'cette adresse. Si vous en avez un, saisissez son numéro ci-dessus.</p>'
            );
        }

        return new HtmlString('');
    }

    private static function frenchDate(?string $date): string
    {
        return $date ? Carbon::parse($date)->format('d/m/Y') : '?';
    }
}
