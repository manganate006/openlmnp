<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\NavigationAware;
use App\Models\Property;
use App\Services\TvaDeclarationService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use UnitEnum;

class TvaDeclaration extends Page
{
    use NavigationAware;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;
    protected static string|UnitEnum|null $navigationGroup = 'Fiscal';
    protected static ?string $navigationLabel = 'Déclaration TVA';
    protected static ?string $title = 'Déclaration TVA';
    protected static ?int $navigationSort = 4;

    protected static function isHiddenInSimpleMode(): bool
    {
        return true;
    }

    protected static function getGuidedNavigationGroup(): string
    {
        return 'Déclaration annuelle';
    }

    protected string $view = 'filament.pages.tva-declaration';

    public int $year;
    public string $period = 'annual'; // annual ou quarterly

    public function mount(): void
    {
        $this->year = (int) date('Y');
    }

    public static function shouldRegisterNavigation(): bool
    {
        // Visible uniquement si l'utilisateur a au moins un bien assujetti TVA
        return Property::where('tva_regime', Property::TVA_LIABLE)->exists();
    }

    #[Computed]
    public function tvaData(): array
    {
        $user = auth()->user();
        $properties = Property::where('tva_regime', Property::TVA_LIABLE)->get();

        if ($properties->isEmpty()) {
            return ['empty' => true];
        }

        return app(TvaDeclarationService::class)->calculate($user, $this->year);
    }

    #[Computed]
    public function availableYears(): array
    {
        $current = (int) date('Y');

        return range($current, $current - 5);
    }
}
