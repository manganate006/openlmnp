<?php

namespace App\Filament\Resources\Properties\Pages;

use App\Filament\Pages\DepreciationEditor;
use App\Filament\Resources\Properties\PropertyResource;
use App\Services\DepreciationService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\View\View;

class EditProperty extends EditRecord
{
    protected static string $resource = PropertyResource::class;

    public function getHeader(): ?View
    {
        return view('filament.partials.list-with-tabs', [
            'propertyId' => $this->record->id,
            'propertyName' => $this->record->name,
            'active' => 'general',
            'heading' => 'Modifier Bien',
            'actions' => $this->getCachedHeaderActions(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Retour')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(static::$resource::getUrl()),
            DeleteAction::make(),
        ];
    }

    /**
     * Avertir ICI, au moment où le prix change — pas trois écrans plus loin.
     *
     * Changer la valeur du bien ou la part du terrain change la base amortissable. Les
     * composants ventilés en POURCENTAGE suivent tout seuls (`PropertyObserver`) : rien à
     * signaler. Ceux dont le montant est SAISI EN EUROS, eux, ne bougent pas — c'est leur
     * raison d'être — et la somme peut alors dépasser la base.
     *
     * Jusqu'ici ce dépassement ne se découvrait qu'à la génération de la liasse, des
     * semaines après le geste qui l'avait créé (issue #11 de cocool97 : 4 335 € trouvés en
     * imprimant). Le moment de la décision est le moment de le dire.
     *
     * ⚠️ La notification vit dans la PAGE, pas dans `PropertyObserver` : celui-ci tourne
     * aussi sous MCP et en console, où `Notification::send()` n'a aucun destinataire.
     *
     * Patron repris d'`EditExpense::getSavedNotification()`, qui propose de la même façon
     * la génération des échéances après l'enregistrement d'une charge.
     */
    protected function getSavedNotification(): ?Notification
    {
        $ecart = app(DepreciationService::class)->overAllocation($this->getRecord());

        if ($ecart <= 0) {
            return parent::getSavedNotification();
        }

        return Notification::make()
            ->warning()
            ->title('Vos amortissements dépassent la nouvelle base')
            ->body(sprintf(
                'La base amortissable est maintenant de %s €, et vos composants totalisent '
                . '%s € de plus. Les montants que vous avez saisis en euros ne suivent pas '
                . 'la valeur du bien — c\'est voulu, mais il faut les ajuster.',
                number_format((int) $this->getRecord()->depreciable_base / 100, 0, ',', ' '),
                number_format($ecart / 100, 0, ',', ' '),
            ))
            ->persistent()
            ->actions([
                Action::make('ajuster')
                    ->label('Ajuster les amortissements')
                    ->url(DepreciationEditor::getUrl(['propertyId' => $this->getRecord()->id]))
                    ->button(),
            ]);
    }
}
