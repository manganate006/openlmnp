<?php

namespace App\Filament\Actions;

use App\Models\FiscalYear;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Enregistrer le dépôt effectif d'une liasse : sa date et son numéro d'accusé.
 *
 * `fiscal_years.transmitted_at` et `ack_number` existaient en base, étaient exposées par deux
 * outils MCP, et restaient nulles sur TOUS les exercices : aucun écran ne les saisissait. Elles
 * portent la seule différence qui compte entre une liasse générée et une liasse déposée —
 * générer un PDF ne dépose rien, et c'est une confusion réelle.
 *
 * ⚠️ Ces deux champs ne se déduisent de rien. Ni la génération du PDF, ni la clôture de
 * l'exercice ne les renseignent : une date de dépôt inventée serait pire qu'une case vide.
 * La seule écriture possible est celle de l'utilisateur, par cette action.
 *
 * Deux montages pour une seule saisie : `forTable()` sur la ligne d'un exercice (le record
 * vient de la table), `forYear()` en en-tête d'un écran qui affiche déjà un exercice précis.
 */
class RecordTransmissionAction
{
    public const NAME = 'record_transmission';

    /** Action de ligne : l'exercice est celui de la ligne. */
    public static function forTable(): Action
    {
        return self::base(fn (?FiscalYear $record) => $record)
            ->color(fn (FiscalYear $record) => $record->transmitted_at === null ? 'gray' : 'success')
            ->fillForm(fn (FiscalYear $record) => self::formState($record))
            ->action(fn (FiscalYear $record, array $data) => self::save($record, $data));
    }

    /**
     * Action d'en-tête : l'exercice est celui que l'écran affiche.
     *
     * Le résolveur est rappelé à chaque évaluation plutôt que capturé une fois : l'exercice
     * affiché change avec le sélecteur d'année, et une valeur figée ferait saisir le dépôt
     * sur l'exercice précédent.
     *
     * @param  Closure(): ?FiscalYear  $resolver
     */
    public static function forYear(Closure $resolver): Action
    {
        return self::base(fn (?FiscalYear $record) => $resolver())
            ->color(fn () => $resolver()?->transmitted_at === null ? 'gray' : 'success')
            ->visible(fn () => $resolver() !== null)
            ->fillForm(fn () => self::formState($resolver()))
            ->action(fn (array $data) => self::save($resolver(), $data));
    }

    /**
     * Libellés communs et les deux champs, qui vont par paire.
     *
     * @param  Closure(?FiscalYear): ?FiscalYear  $resolve  l'exercice visé, depuis le record
     *                                                     injecté par la table ou sans lui
     */
    private static function base(Closure $resolve): Action
    {
        return Action::make(self::NAME)
            ->label('Dépôt')
            ->icon('heroicon-o-paper-airplane')
            ->modalHeading('Enregistrer le dépôt de la liasse')
            ->modalDescription('Générer une liasse ne la dépose pas. Notez ici la date du dépôt et le '
                . 'numéro rendu par l\'administration : certificat de dépôt pour une saisie en ligne, '
                . 'compte rendu de traitement pour un envoi EDI. Videz la date pour annuler '
                . 'l\'enregistrement.')
            ->modalSubmitActionLabel('Enregistrer')
            ->schema([
                DatePicker::make('transmitted_at')
                    ->label('Date du dépôt')
                    ->displayFormat('d/m/Y')
                    // Une liasse ne se dépose ni avant l'exercice qu'elle déclare, ni dans le
                    // futur : les deux bornes n'attrapent qu'une faute de frappe, mais c'est
                    // exactement ce qui se glisse dans une date recopiée à la main d'un accusé.
                    ->minDate(function (?FiscalYear $record = null) use ($resolve) {
                        $fy = $resolve($record);

                        return $fy === null ? null : Carbon::create($fy->year, 1, 1);
                    })
                    // ⚠️ `endOfDay()`, pas `now()` : un DatePicker non natif renvoie un état
                    // HORODATÉ (la date choisie + l'heure du clic) alors qu'il n'affiche qu'un
                    // jour. Une borne à l'instant présent refuserait une date du jour choisie
                    // une seconde plus tard, avec un message qui ne parle pas de l'heure.
                    ->maxDate(now()->endOfDay())
                    ->helperText('La date portée sur l\'accusé, pas celle où la liasse a été générée.'),
                TextInput::make('ack_number')
                    ->label('Numéro d\'accusé')
                    ->maxLength(60)
                    ->helperText('Laissez vide si vous ne l\'avez pas sous la main : la date seule suffit '
                        . 'à distinguer une liasse déposée d\'une liasse imprimée.'),
            ]);
    }

    /** @return array{transmitted_at: ?string, ack_number: ?string} */
    private static function formState(?FiscalYear $fy): array
    {
        return [
            'transmitted_at' => $fy?->transmitted_at?->toDateString(),
            'ack_number'     => $fy?->ack_number,
        ];
    }

    private static function save(?FiscalYear $fy, array $data): void
    {
        if ($fy === null) {
            return;
        }

        // L'heure du clic ne veut rien dire pour un dépôt : elle est retirée ici, sans quoi
        // le MCP rendrait « 2026-05-12 14:03:22 » comme date de dépôt.
        $date = filled($data['transmitted_at'] ?? null)
            ? Carbon::parse($data['transmitted_at'])->startOfDay()
            : null;

        // Un numéro d'accusé sans date de dépôt ne prouve rien : les deux champs se saisissent
        // ensemble et se vident ensemble. Sans cette règle, effacer une date laisserait un
        // numéro orphelin, que le MCP rendrait comme la trace d'un dépôt.
        $ack = $date === null
            ? null
            : (filled($data['ack_number'] ?? null) ? trim($data['ack_number']) : null);

        $fy->update(['transmitted_at' => $date, 'ack_number' => $ack]);

        Notification::make()
            ->title($date === null
                ? 'Dépôt effacé — exercice ' . $fy->year
                : 'Dépôt enregistré — exercice ' . $fy->year)
            ->body($date === null
                ? 'L\'exercice ne porte plus de date de dépôt ni de numéro d\'accusé.'
                : $fy->fresh()->transmissionSummary())
            ->success()
            ->send();
    }
}
