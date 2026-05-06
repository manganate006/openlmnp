<?php

namespace App\Filament\Resources\PropertyWorks;

use App\Filament\Resources\PropertyWorks\Pages\CreatePropertyWork;
use App\Filament\Resources\PropertyWorks\Pages\EditPropertyWork;
use App\Filament\Resources\PropertyWorks\Pages\ListPropertyWorks;
use App\Filament\Resources\PropertyWorks\Schemas\PropertyWorkForm;
use App\Filament\Resources\PropertyWorks\Tables\PropertyWorksTable;
use App\Filament\Pages\Concerns\NavigationAware;
use App\Models\PropertyWork;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PropertyWorkResource extends Resource
{
    use NavigationAware;

    protected static ?string $model = PropertyWork::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrench;

    protected static string | \UnitEnum | null $navigationGroup = 'Mes biens';

    protected static ?string $navigationLabel = 'Travaux';

    protected static ?string $modelLabel = 'travaux';

    protected static ?string $pluralModelLabel = 'travaux';

    protected static ?int $navigationSort = 2;

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
        return PropertyWorkForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PropertyWorksTable::configure($table);
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
            'index' => ListPropertyWorks::route('/'),
            'property' => ListPropertyWorks::route('/{propertyId}'),
            'create' => CreatePropertyWork::route('/create'),
            'edit' => EditPropertyWork::route('/{record}/edit'),
        ];
    }
}
