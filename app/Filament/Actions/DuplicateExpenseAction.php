<?php

namespace App\Filament\Actions;

use App\Models\Expense;
use App\Services\RecurringExpenseService;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;

/**
 * Recopie une charge dans une nouvelle ligne, date et montant à confirmer.
 *
 * Complète « Générer les échéances », qui ne couvre que le mensuel et le trimestriel :
 * une taxe foncière se ressaisit chaque année, une charge ponctuelle se répète sans
 * périodicité fixe. Prévue dès `docs/ui-design-openlmnp.md` et jamais faite jusqu'ici,
 * c'était le vrai irritant derrière l'issue #9 pour tout ce qui n'est pas mensuel.
 *
 * Ne recopie PAS les justificatifs : `replicate()` ne touche pas aux relations, et
 * c'est voulu — une facture appartient à une échéance et à une seule.
 */
class DuplicateExpenseAction extends ReplicateAction
{
    public static function getDefaultName(): ?string
    {
        return 'duplicate_expense';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Dupliquer')
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->modalHeading('Dupliquer la charge')
            ->modalDescription('La copie reprend le bien, la catégorie, la description, la TVA et la quote-part. Ajustez la date et le montant ; le justificatif, lui, n\'est pas recopié.')
            ->modalSubmitActionLabel('Dupliquer')
            ->successNotificationTitle('Charge dupliquée')
            ->mutateRecordDataUsing(function (array $data, Expense $record): array {
                // La date proposée avance d'une période : c'est ce qu'on duplique en
                // pratique, la même charge à l'échéance suivante.
                $data['expense_date'] = app(RecurringExpenseService::class)
                    ->defaultCopyDate($record)
                    ->toDateString();

                return $data;
            })
            ->schema([
                // La description porte souvent le millésime (« Taxe foncière 2026 ») :
                // la recopier telle quelle sur la copie de l'année suivante mettrait un
                // libellé faux dans les écritures comptables.
                TextInput::make('description')
                    ->label('Description')
                    ->required(),
                DatePicker::make('expense_date')
                    ->label('Date de la copie')
                    ->required(),
                TextInput::make('amount')
                    ->label('Montant TTC')
                    ->suffix('€')
                    ->required()
                    ->numeric()
                    ->step(0.01)
                    // Mêmes conversions que le formulaire : la base stocke des centimes.
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : null)
                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                    ->helperText('Un montant qui a changé depuis l\'échéance précédente se corrige ici.'),
            ]);
    }
}
