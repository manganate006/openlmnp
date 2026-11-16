<?php

namespace App\Filament\Actions;

use App\Filament\Resources\Leases\LeaseResource;
use App\Helpers\TvaHelper;
use App\Models\Lease;
use App\Services\LeaseRentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;

/**
 * Génère les loyers d'un bail sur une année civile (issue #24).
 *
 * Calquée sur `GenerateOccurrencesAction` : pas de `requiresConfirmation()` (le formulaire
 * de la modale confirme), et `$record` capturé par les closures du schéma.
 *
 * Le recalcul des exercices en brouillon est fait par le service, pas ici : un appel
 * par le MCP doit laisser les totaux aussi justes qu'un clic.
 */
class GenerateLeaseRentsAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'generate_rents';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Générer les loyers')
            ->icon('heroicon-o-calendar-days')
            ->color('info')
            ->modalHeading('Générer les loyers de l\'année')
            ->modalDescription('Chaque loyer devient une recette à part entière, datée de son échéance. Les mois d\'entrée et de sortie ne sont pas générés : leur montant dépend du bail, saisissez-les à la main.')
            ->modalSubmitActionLabel('Générer')
            ->fillForm(fn (Lease $record): array => [
                'year' => static::service()->defaultYear($record),
            ])
            ->schema(fn (Lease $record): array => [
                Select::make('year')
                    ->label('Année')
                    ->options(static::yearOptions($record))
                    ->required()
                    ->live()
                    ->selectablePlaceholder(false)
                    ->helperText('Un exercice est une année civile. Un bail en cours s\'arrête à l\'année en cours.'),
                Placeholder::make('preview')
                    ->label('Aperçu')
                    ->content(fn (callable $get): string => static::preview($record, $get('year'))),
            ])
            ->action(function (Lease $record, array $data): void {
                try {
                    $result = static::service()->generate($record, (int) $data['year']);
                } catch (\RuntimeException $exception) {
                    Notification::make()
                        ->title('Génération impossible')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $notification = Notification::make()
                    ->title($result['created'] . ' loyer(s) créé(s)')
                    ->body($result['skipped'] > 0
                        ? $result['skipped'] . ' loyer(s) déjà saisi(s), laissé(s) tel(s) quel(s).'
                        : 'Chaque loyer attend maintenant son justificatif.');

                ($result['created'] > 0 ? $notification->success() : $notification->warning())->send();
            });
    }

    /**
     * Invitation à générer, posée juste après l'enregistrement d'un bail.
     *
     * Même raison que pour les charges récurrentes : sans elle, l'action ne se découvre
     * que dans la liste. Le bouton ouvre la modale sur la bonne ligne, rien n'est créé
     * sans passer par l'aperçu.
     */
    public static function proposal(Lease $record, string $title): ?Notification
    {
        $year = static::service()->defaultYear($record);
        $plan = static::service()->plan($record, $year);

        if ($plan['to_create'] === 0) {
            return null;
        }

        return Notification::make()
            ->success()
            ->title($title)
            ->body($plan['to_create'] . ' loyer(s) à générer pour ' . $year . '.')
            ->actions([
                Action::make('generate')
                    ->label('Générer les loyers')
                    ->button()
                    ->url(LeaseResource::getUrl('index', [
                        'tableAction' => static::getDefaultName(),
                        'tableActionRecord' => $record->getKey(),
                    ])),
            ]);
    }

    protected static function service(): LeaseRentService
    {
        return app(LeaseRentService::class);
    }

    /** @return array<int, string> */
    protected static function yearOptions(Lease $record): array
    {
        $years = static::service()->years($record);

        return array_combine($years, array_map('strval', $years));
    }

    /**
     * Résumé de ce que la génération produirait, mois partiels compris : ce sont eux
     * qu'il faudra saisir à la main, autant les nommer avant.
     */
    protected static function preview(Lease $record, mixed $year): string
    {
        if (! is_numeric($year) || ! in_array((int) $year, static::service()->years($record), true)) {
            return '-';
        }

        $plan = static::service()->plan($record, (int) $year);

        $summary = match (true) {
            $plan['to_create'] > 0 => $plan['to_create'] . ' loyer(s) à créer · '
                . $plan['existing'] . ' déjà présent(s) · '
                . TvaHelper::formatEuros($plan['total_cents']) . ' au total.',
            $plan['existing'] > 0 => 'Tous les loyers de cette année sont déjà saisis.',
            default => 'Aucun mois complet sur cette année.',
        };

        foreach ($plan['partial'] as $month) {
            $summary .= ' Mois partiel, à saisir à la main : du '
                . $month['from']->format('d/m/Y') . ' au ' . $month['to']->format('d/m/Y') . '.';
        }

        return $summary;
    }
}
