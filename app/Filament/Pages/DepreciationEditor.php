<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\EditsDepreciationComponents;
use App\Filament\Pages\Concerns\NavigationAware;
use App\Models\Property;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use UnitEnum;

class DepreciationEditor extends Page
{
    use EditsDepreciationComponents;
    use NavigationAware;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;
    protected static string | UnitEnum | null $navigationGroup = 'Mes biens';
    protected static ?string $navigationLabel = 'Amortissements';
    protected static ?string $title = 'Ventilation des composants';
    protected static ?int $navigationSort = 4;

    protected static function isHiddenInSimpleMode(): bool
    {
        return true;
    }

    protected static function getGuidedNavigationGroup(): string
    {
        return 'Mise en route';
    }

    protected static ?string $slug = 'depreciation-editor/{propertyId?}';

    protected string $view = 'filament.pages.depreciation-editor';

    public ?int $propertyId = null;

    public function mount(?int $propertyId = null): void
    {
        if ($propertyId) {
            $this->propertyId = $propertyId;
        } else {
            $count = Property::count();
            if ($count === 1) {
                $this->redirect('/depreciation-editor/' . Property::first()->id);
                return;
            }
        }
    }

    public function getHeader(): ?View
    {
        $propertyName = $this->propertyId ? Property::find($this->propertyId)?->name : null;

        return view('filament.partials.list-with-tabs', [
            'propertyId' => $this->propertyId,
            'propertyName' => $propertyName,
            'active' => 'components',
            'heading' => 'Ventilation des composants',
            'actions' => [],
            'properties' => $this->propertyId ? null : Property::orderBy('name')->get(['id', 'name']),
            'currentUrl' => '/depreciation-editor',
        ]);
    }

    #[Computed]
    public function properties(): array
    {
        return Property::orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])
            ->all();
    }

}
