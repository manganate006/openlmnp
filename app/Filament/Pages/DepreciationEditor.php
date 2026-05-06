<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\NavigationAware;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Services\DepreciationService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use UnitEnum;

class DepreciationEditor extends Page
{
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

    #[Computed]
    public function editorData(): array
    {
        if (! $this->propertyId) {
            return ['empty' => true];
        }

        $property = Property::find($this->propertyId);
        if (! $property) {
            return ['empty' => true];
        }

        $depreciableBase = $property->depreciable_base;
        $depreciableBaseEuros = (int) $depreciableBase / 100;

        $existing = $property->components->keyBy('name');

        $components = [];
        foreach (DepreciationService::FULL_CATALOG as $catalog) {
            $match = $existing->get($catalog['name']);

            if ($match) {
                $components[] = [
                    'name'                => $match->name,
                    'percentage'          => $match->percentage,
                    'duration'            => $match->duration_years,
                    'suggestedPercentage' => $catalog['percentage'],
                    'optional'            => $catalog['optional'],
                    'enabled'             => true,
                    'sortOrder'           => $match->sort_order,
                ];
            } else {
                $components[] = [
                    'name'                => $catalog['name'],
                    'percentage'          => 0,
                    'duration'            => $catalog['duration_years'],
                    'suggestedPercentage' => $catalog['percentage'],
                    'optional'            => $catalog['optional'],
                    'enabled'             => ! $catalog['optional'],
                    'sortOrder'           => $catalog['sort_order'],
                ];
            }
        }

        // Composants en base qui ne sont pas dans le catalogue (personnalisés)
        foreach ($existing as $name => $comp) {
            $inCatalog = collect(DepreciationService::FULL_CATALOG)->contains('name', $name);
            if (! $inCatalog) {
                $components[] = [
                    'name'                => $comp->name,
                    'percentage'          => $comp->percentage,
                    'duration'            => $comp->duration_years,
                    'suggestedPercentage' => $comp->percentage,
                    'optional'            => true,
                    'enabled'             => true,
                    'sortOrder'           => $comp->sort_order,
                ];
            }
        }

        usort($components, fn ($a, $b) => $a['sortOrder'] <=> $b['sortOrder']);

        return [
            'empty'              => false,
            'depreciableBase'    => $depreciableBaseEuros,
            'components'         => $components,
        ];
    }

    public function updatedPropertyId(): void
    {
        unset($this->editorData);
        unset($this->properties);
        $this->dispatch('components-loaded', data: $this->editorData);
    }

    public function saveComponents(array $components): void
    {
        if (! $this->propertyId) {
            return;
        }

        $property = Property::findOrFail($this->propertyId);
        $depreciableBase = $property->depreciable_base;

        $enabled = array_filter($components, fn ($c) => $c['enabled'] && $c['percentage'] > 0);
        $total = (int) round(array_sum(array_column($enabled, 'percentage')));

        if ($total !== 100) {
            Notification::make()
                ->danger()
                ->title('Le total des pourcentages doit faire 100 %')
                ->body("Total actuel : {$total} %")
                ->persistent()
                ->send();
            return;
        }

        PropertyComponent::where('property_id', $this->propertyId)->delete();

        foreach ($enabled as $comp) {
            $baseAmount = bcmul($depreciableBase, bcdiv((string) $comp['percentage'], '100', 10), 0);
            $annualDep = (int) $comp['duration'] > 0
                ? bcdiv($baseAmount, (string) $comp['duration'], 0)
                : '0';

            PropertyComponent::create([
                'property_id'         => $this->propertyId,
                'name'                => $comp['name'],
                'percentage'          => (int) $comp['percentage'],
                'duration_years'      => (int) $comp['duration'],
                'base_amount'         => (int) $baseAmount,
                'annual_depreciation' => (int) $annualDep,
                'sort_order'          => (int) $comp['sortOrder'],
            ]);
        }

        unset($this->editorData);

        Notification::make()
            ->success()
            ->title('Composants enregistrés')
            ->send();
    }

    public function resetToDefaults(): void
    {
        if (! $this->propertyId) {
            return;
        }

        $property = Property::findOrFail($this->propertyId);

        PropertyComponent::where('property_id', $this->propertyId)->delete();

        app(DepreciationService::class)->generateDefaultComponents($property);

        unset($this->editorData);
        $this->dispatch('components-loaded', data: $this->editorData);

        Notification::make()
            ->success()
            ->title('Composants réinitialisés')
            ->body('Les 6 composants standards ont été restaurés.')
            ->send();
    }
}
