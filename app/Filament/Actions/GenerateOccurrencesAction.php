<?php

namespace App\Filament\Actions;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Helpers\TvaHelper;
use App\Models\Expense;
use App\Services\FiscalYearService;
use App\Services\RecurringExpenseService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;

/**
 * Matérialise les échéances d'une charge mensuelle ou trimestrielle (issue #9).
 *
 * ⚠️ Pas de `requiresConfirmation()` ici : Filament l'ignore dès qu'un `modalHeading()`
 * existe, et la modale porte de toute façon un formulaire — c'est lui qui confirme.
 *
 * ⚠️ `$record` est capturé par les closures internes plutôt que redéclaré en paramètre :
 * les callbacks des composants s'évaluent dans le contexte du SCHÉMA de la modale, pas
 * dans celui de l'action.
 */
class GenerateOccurrencesAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'generate_occurrences';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Générer les échéances')
            ->icon('heroicon-o-calendar-days')
            ->color('info')
            ->visible(fn (Expense $record): bool => RecurringExpenseService::isGeneratable($record->recurring_type))
            ->modalHeading('Générer les échéances de l\'année')
            ->modalDescription('Chaque échéance devient une charge à part entière, avec sa propre date. Les justificatifs ne sont pas recopiés : joignez à chaque ligne sa facture.')
            ->modalSubmitActionLabel('Générer')
            ->fillForm(fn (Expense $record): array => [
                'until' => static::service()->defaultUntil($record)->toDateString(),
            ])
            ->schema(fn (Expense $record): array => [
                DatePicker::make('until')
                    ->label('Générer jusqu\'au')
                    ->required()
                    ->live(onBlur: true)
                    ->minDate(static::service()->firstOccurrence($record))
                    // ⚠️ Borne haute à la FIN de la journée : le picker non natif renvoie
                    // un état horodaté (l'heure du clic), qu'un `maxDate` à minuit refuse.
                    // Sans ça, l'action rejetait sa propre valeur par défaut au navigateur.
                    ->maxDate(static::service()->defaultUntil($record)->endOfDay())
                    ->helperText('Bornée à l\'année civile de la charge : un exercice ne déborde pas sur le suivant.'),
                Placeholder::make('preview')
                    ->label('Aperçu')
                    ->content(fn (callable $get): string => static::preview($record, $get('until'))),
            ])
            ->action(function (Expense $record, array $data): void {
                try {
                    $result = static::service()->generate($record, CarbonImmutable::parse($data['until']));
                } catch (\RuntimeException $exception) {
                    Notification::make()
                        ->title('Génération impossible')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                if ($result['created'] > 0 && ($ownerId = static::service()->ownerId($record)) !== null) {
                    // Les totaux d'exercice sont figés en base et rien n'observe `Expense` :
                    // sans ce recalcul, le tableau de bord verrait les échéances mais pas
                    // les exercices. Les exercices clôturés sont laissés tels quels.
                    app(FiscalYearService::class)->recalculateDrafts($ownerId);
                }

                $notification = Notification::make()
                    ->title($result['created'] . ' échéance(s) créée(s)')
                    ->body($result['skipped'] > 0
                        ? $result['skipped'] . ' échéance(s) déjà saisie(s), laissée(s) telle(s) quelle(s).'
                        : 'Chaque échéance attend maintenant son justificatif.');

                ($result['created'] > 0 ? $notification->success() : $notification->warning())->send();
            });
    }

    /**
     * Invitation à générer, posée juste après l'enregistrement d'une charge récurrente.
     *
     * L'action ne se découvre autrement que dans la liste : quelqu'un qui vient de
     * saisir sa première charge « Mensuel » ignore qu'elle existe — c'est exactement la
     * situation qui a produit l'issue #9. Le bouton ouvre la modale sur la bonne ligne
     * (Filament monte une action de table depuis l'URL), donc rien n'est automatique :
     * l'aperçu et la confirmation restent en travers du chemin.
     *
     * Rend `null` quand il n'y a rien à proposer — la notification d'origine passe alors
     * inchangée.
     */
    public static function proposal(Expense $record, string $title): ?Notification
    {
        if (! RecurringExpenseService::isGeneratable($record->recurring_type)) {
            return null;
        }

        $plan = static::service()->plan($record, static::service()->defaultUntil($record));

        if ($plan['to_create'] === 0) {
            return null;
        }

        $year = CarbonImmutable::parse($record->expense_date)->year;

        return Notification::make()
            ->success()
            ->title($title)
            ->body($plan['to_create'] . ' échéance(s) restent à saisir pour ' . $year . '.')
            ->actions([
                Action::make('generate')
                    ->label('Générer les échéances')
                    ->button()
                    ->url(ExpenseResource::getUrl('index', [
                        'tableAction' => static::getDefaultName(),
                        'tableActionRecord' => $record->getKey(),
                    ])),
            ]);
    }

    protected static function service(): RecurringExpenseService
    {
        return app(RecurringExpenseService::class);
    }

    /**
     * Résumé de ce que la génération produirait, recalculé à chaque changement de date.
     */
    protected static function preview(Expense $record, mixed $until): string
    {
        if (blank($until)) {
            return '—';
        }

        try {
            $date = CarbonImmutable::parse($until);
        } catch (\Throwable) {
            return '—';
        }

        // Le sélecteur borne déjà à l'année civile, mais un état forgé ne doit pas
        // faire miroiter des échéances de l'exercice suivant.
        $max = static::service()->defaultUntil($record);
        $plan = static::service()->plan($record, $date->startOfDay()->greaterThan($max) ? $max : $date);

        if ($plan['to_create'] === 0) {
            return $plan['existing'] > 0
                ? 'Toutes les échéances de cette période sont déjà saisies.'
                : 'Aucune échéance à créer avant cette date.';
        }

        return $plan['to_create'] . ' échéance(s) à créer · '
            . $plan['existing'] . ' déjà présente(s) · '
            . TvaHelper::formatEuros($plan['total_cents']) . ' au total';
    }
}
