<?php

namespace App\Filament\Resources\PropertyWorks\Pages;

use App\Filament\Resources\PropertyWorks\PropertyWorkResource;
use App\Filament\Widgets\PropertyTabsWidget;
use App\Models\Property;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListPropertyWorks extends ListRecords
{
    protected static string $resource = PropertyWorkResource::class;

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

    protected function getHeaderWidgets(): array
    {
        if ($this->propertyId) {
            return [PropertyTabsWidget::class];
        }

        return [];
    }

    protected function getHeaderWidgetsData(): array
    {
        return [
            'propertyId' => $this->propertyId,
            'active' => 'works',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
