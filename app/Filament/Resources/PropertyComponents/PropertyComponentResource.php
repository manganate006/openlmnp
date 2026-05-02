<?php

namespace App\Filament\Resources\PropertyComponents;

use App\Filament\Resources\PropertyComponents\Pages\CreatePropertyComponent;
use App\Filament\Resources\PropertyComponents\Pages\EditPropertyComponent;
use App\Filament\Resources\PropertyComponents\Pages\ListPropertyComponents;
use App\Filament\Resources\PropertyComponents\Schemas\PropertyComponentForm;
use App\Filament\Resources\PropertyComponents\Tables\PropertyComponentsTable;
use App\Filament\Pages\Concerns\NavigationAware;
use App\Models\PropertyComponent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PropertyComponentResource extends Resource
{
    use NavigationAware;

    protected static ?string $model = PropertyComponent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string | \UnitEnum | null $navigationGroup = 'Mes biens';

    protected static ?string $navigationLabel = 'Amortissements';

    protected static ?string $modelLabel = 'composant';

    protected static ?string $pluralModelLabel = "composants d'amortissement";

    protected static ?int $navigationSort = 4;

    protected static function isHiddenInSimpleMode(): bool
    {
        return true;
    }

    protected static function getGuidedNavigationGroup(): string
    {
        return 'Mise en route';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::count();
        return $count > 0 ? (string) $count : null;
    }

    public static function form(Schema $schema): Schema
    {
        return PropertyComponentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PropertyComponentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPropertyComponents::route('/'),
            'create' => CreatePropertyComponent::route('/create'),
            'edit' => EditPropertyComponent::route('/{record}/edit'),
        ];
    }
}
