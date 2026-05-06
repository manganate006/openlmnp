<?php

namespace App\Filament\Resources\Furniture;

use App\Filament\Resources\Furniture\Pages\CreateFurniture;
use App\Filament\Resources\Furniture\Pages\EditFurniture;
use App\Filament\Resources\Furniture\Pages\ListFurniture;
use App\Filament\Resources\Furniture\Schemas\FurnitureForm;
use App\Filament\Resources\Furniture\Tables\FurnitureTable;
use App\Filament\Pages\Concerns\NavigationAware;
use App\Models\Furniture;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FurnitureResource extends Resource
{
    use NavigationAware;

    protected static ?string $model = Furniture::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string | \UnitEnum | null $navigationGroup = 'Mes biens';

    protected static ?string $navigationLabel = 'Mobilier & Équipements';

    protected static ?string $modelLabel = 'équipement';

    protected static ?string $pluralModelLabel = 'mobilier & équipements';

    protected static ?int $navigationSort = 3;

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
        return FurnitureForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FurnitureTable::configure($table);
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
            'index' => ListFurniture::route('/'),
            'property' => ListFurniture::route('/{propertyId}'),
            'create' => CreateFurniture::route('/create'),
            'edit' => EditFurniture::route('/{record}/edit'),
        ];
    }
}
