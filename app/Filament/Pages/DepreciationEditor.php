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

        $depreciableBase = (int) $property->depreciable_base;

        // groupBy et non keyBy : deux composants homonymes existent (un utilisateur peut
        // renommer, ou ajouter « Toiture » deux fois). keyBy en écrasait un en silence,
        // ce qui, maintenant que l'écriture se fait par id, aurait perdu des données.
        $existing = $property->components->groupBy('name');
        $matchedIds = [];

        $components = [];
        foreach (DepreciationService::FULL_CATALOG as $catalog) {
            $match = $existing->get($catalog['name'])?->first();

            if ($match) {
                $matchedIds[] = $match->id;
                $components[] = self::lineFromComponent($match, $catalog['percentage'], $catalog['optional']);
            } else {
                $components[] = [
                    'id'                  => null,
                    'name'                => $catalog['name'],
                    'percentage'          => 0,
                    'baseAmount'          => 0,
                    'baseSource'          => PropertyComponent::BASE_SOURCE_PERCENTAGE,
                    'duration'            => $catalog['duration_years'],
                    'suggestedPercentage' => $catalog['percentage'],
                    'optional'            => $catalog['optional'],
                    'enabled'             => ! $catalog['optional'],
                    'sortOrder'           => $catalog['sort_order'],
                ];
            }
        }

        // Composants en base qui ne correspondent à aucune entrée du catalogue,
        // et les doublons d'un même nom : tous « personnalisés ».
        foreach ($property->components as $comp) {
            if (! in_array($comp->id, $matchedIds, true)) {
                $components[] = self::lineFromComponent($comp, (float) $comp->percentage, true);
            }
        }

        usort($components, fn ($a, $b) => $a['sortOrder'] <=> $b['sortOrder']);

        return [
            'empty'           => false,
            'depreciableBase' => $depreciableBase / 100,
            // En centimes : c'est en centimes que se vérifie l'invariant de ventilation.
            // Le raisonnement en euros flottants perdait des centimes dès que la base
            // n'était pas divisible par 100.
            'depreciableBaseCents' => $depreciableBase,
            'components'           => $components,
        ];
    }

    /** @return array<string, mixed> */
    private static function lineFromComponent(PropertyComponent $component, float $suggested, bool $optional): array
    {
        return [
            'id'                  => $component->id,
            'name'                => $component->name,
            'percentage'          => (float) $component->percentage,
            'baseAmount'          => (int) $component->base_amount,
            'baseSource'          => $component->base_source,
            'duration'            => $component->duration_years,
            'suggestedPercentage' => $suggested,
            'optional'            => $optional,
            'enabled'             => true,
            'sortOrder'           => $component->sort_order,
        ];
    }

    public function updatedPropertyId(): void
    {
        unset($this->editorData);
        unset($this->properties);
        $this->dispatch('components-loaded', data: $this->editorData);
    }

    /**
     * Enregistre la ventilation.
     *
     * ⚠️ Méthode Livewire PUBLIQUE : tout ce qui arrive ici vient du navigateur et doit
     * être validé côté serveur. Jusqu'au 2026-09-03, la seule garde était l'arrondi de
     * Hamilton appliqué en JavaScript, et un `(int)` tronquait ensuite chaque pourcentage
     * — six composants à 16,67 % s'enregistraient donc à 96 % de la base.
     */
    public function saveComponents(array $components): void
    {
        if (! $this->propertyId) {
            return;
        }

        $property = Property::findOrFail($this->propertyId);

        $lines = [];
        foreach ($components as $comp) {
            $source = ($comp['baseSource'] ?? null) === PropertyComponent::BASE_SOURCE_MANUAL
                ? PropertyComponent::BASE_SOURCE_MANUAL
                : PropertyComponent::BASE_SOURCE_PERCENTAGE;

            $percentage = (float) ($comp['percentage'] ?? 0);
            $baseAmount = (int) ($comp['baseAmount'] ?? 0);

            // Une ligne décochée, ou vide dans son propre mode, n'est pas conservée.
            $keeps = ($comp['enabled'] ?? false)
                && ($source === PropertyComponent::BASE_SOURCE_MANUAL ? $baseAmount > 0 : $percentage > 0);

            if (! $keeps) {
                continue;
            }

            $lines[] = [
                'id'                  => isset($comp['id']) ? (int) $comp['id'] : null,
                'name'                => (string) $comp['name'],
                'duration_years'      => max(1, (int) ($comp['duration'] ?? 1)),
                'sort_order'          => (int) ($comp['sortOrder'] ?? 0),
                'base_source'         => $source,
                'percentage'          => $percentage,
                'base_amount'         => $baseAmount,
                'annual_depreciation' => isset($comp['annualDepreciation'])
                    ? (int) $comp['annualDepreciation']
                    : null,
            ];
        }

        try {
            $result = app(DepreciationService::class)->syncComponents($property, $lines);
        } catch (\RuntimeException $e) {
            Notification::make()
                ->danger()
                ->title('Ventilation impossible')
                ->body($e->getMessage()
                    . ' Ajustez la part du terrain ou la valeur retenue sur la fiche du bien.')
                ->persistent()
                ->send();

            return;
        }

        unset($this->editorData);

        $remainder = (int) $result['remainder'];

        $notification = Notification::make()->title('Composants enregistrés');

        if ($remainder > 0) {
            // Sous-ventiler est légitime — un comptable peut n'avoir réparti qu'une part
            // de la base. On l'accepte, mais jamais en silence.
            $notification->warning()->body(sprintf(
                '%s € de base amortissable ne sont rattachés à aucun composant.',
                number_format($remainder / 100, 0, ',', ' '),
            ));
        } else {
            $notification->success();
        }

        $notification->send();
    }

    public function resetToDefaults(): void
    {
        if (! $this->propertyId) {
            return;
        }

        $property = Property::findOrFail($this->propertyId);

        $manualCount = $property->components()
            ->where('base_source', PropertyComponent::BASE_SOURCE_MANUAL)
            ->count();

        PropertyComponent::where('property_id', $this->propertyId)->delete();

        app(DepreciationService::class)->generateDefaultComponents($property);

        unset($this->editorData);
        $this->dispatch('components-loaded', data: $this->editorData);

        Notification::make()
            ->success()
            ->title('Composants réinitialisés')
            ->body($manualCount > 0
                ? sprintf(
                    'Les 6 composants standards ont été restaurés. %d base(s) saisie(s) à la main ont été perdues.',
                    $manualCount,
                )
                : 'Les 6 composants standards ont été restaurés.')
            ->send();
    }
}
