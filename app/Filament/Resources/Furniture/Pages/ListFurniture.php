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

    public function getSubheading(): ?string
    {
        if ($this->propertyId) {
            return Property::find($this->propertyId)?->name;
        }

        return null;
    }

    public function getHeader(): ?View
    {
        if ($this->propertyId) {
            return view('filament.partials.list-with-tabs', [
                'propertyId' => $this->propertyId,
                'active' => 'furniture',
                'heading' => 'Mobilier & Équipements',
                'subheading' => $this->getSubheading(),
                'actions' => $this->getCachedHeaderActions(),
            ]);
        }

        return null;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
