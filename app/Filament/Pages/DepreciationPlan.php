<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\NavigationAware;
use App\Models\Property;
use App\Services\DepreciationService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * Plan d'amortissement de chaque actif d'un bien, année par année (issue #13, OpenProject
 * n° 1171). Lecture seule : tout vient de `DepreciationService::planForProperty()`, qui
 * reprend les calculs de la liasse sans en écrire un second.
 *
 * Onglet du bien (`x-property-tabs`), hors menu : le menu « Amortissements » mène déjà à la
 * ventilation des composants.
 */
class DepreciationPlan extends Page
{
    use NavigationAware;

    protected static bool $shouldRegisterNavigation = false;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;
    protected static string | UnitEnum | null $navigationGroup = 'Mes biens';
    protected static ?string $navigationLabel = 'Plan d\'amortissement';
    protected static ?string $title = 'Plan d\'amortissement';

    protected static function getGuidedNavigationGroup(): string
    {
        return 'Mise en route';
    }

    protected static ?string $slug = 'depreciation-plan/{propertyId?}';

    protected string $view = 'filament.pages.depreciation-plan';

    public ?int $propertyId = null;

    public function mount(?int $propertyId = null): void
    {
        // Le scope global de Property limite la requête aux biens de l'utilisateur : un
        // identifiant étranger est introuvable, comme sur l'éditeur des composants.
        if ($propertyId) {
            abort_unless(Property::whereKey($propertyId)->exists(), 404);
            $this->propertyId = $propertyId;

            return;
        }

        if (Property::count() === 1) {
            $this->redirect('/depreciation-plan/' . Property::query()->value('id'));
        }
    }

    public function getHeader(): ?View
    {
        $propertyName = $this->propertyId ? Property::find($this->propertyId)?->name : null;

        return view('filament.partials.list-with-tabs', [
            'propertyId' => $this->propertyId,
            'propertyName' => $propertyName,
            'active' => 'plan',
            'heading' => 'Plan d\'amortissement',
            'actions' => [],
            'properties' => $this->propertyId ? null : Property::orderBy('name')->get(['id', 'name']),
            'currentUrl' => '/depreciation-plan',
        ]);
    }

    /**
     * @return array{property: Property, plans: list<array>, year: int}|null
     */
    #[Computed]
    public function planData(): ?array
    {
        if (! $this->propertyId) {
            return null;
        }

        $property = Property::with(['components', 'works', 'furniture'])->find($this->propertyId);

        if (! $property) {
            return null;
        }

        return [
            'property' => $property,
            'plans' => app(DepreciationService::class)->planForProperty($property),
            'year' => (int) now()->format('Y'),
        ];
    }
}
