<?php

namespace App\Filament\Resources\Furniture\Pages;

use App\Filament\Resources\Furniture\FurnitureResource;
use App\Models\Property;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

class ListFurniture extends ListRecords
{
    protected static string $resource = FurnitureResource::class;

    public ?int $propertyId = null;

    public function mount(): void
    {
        parent::mount();

        if ($this->propertyId) {
            $this->propertyId = (int) $this->propertyId;
        } else {
            $count = Property::count();
            if ($count === 1) {
                $this->redirect(FurnitureResource::getUrl('property', [
                    'propertyId' => Property::first()->id,
                ]));
                return;
            }
        }
    }

    protected function getTableQuery(): ?Builder
    {
        $query = parent::getTableQuery();

        if ($this->propertyId) {
            $query->where('property_id', $this->propertyId);
        }

        return $query;
    }

    public function getHeader(): ?View
    {
        $propertyName = $this->propertyId ? Property::find($this->propertyId)?->name : null;

        return view('filament.partials.list-with-tabs', [
            'propertyId' => $this->propertyId,
            'propertyName' => $propertyName,
            'active' => 'furniture',
            'heading' => 'Mobilier & Équipements',
            'actions' => $this->getCachedHeaderActions(),
            'properties' => $this->propertyId ? null : Property::orderBy('name')->get(['id', 'name']),
            'currentUrl' => '/furniture',
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
