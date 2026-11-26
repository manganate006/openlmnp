<?php

namespace App\Filament\Resources\Leases;

use App\Filament\Pages\Concerns\NavigationAware;
use App\Filament\Resources\Concerns\BlocksMemberPrincipal;
use App\Filament\Resources\Leases\Pages\CreateLease;
use App\Filament\Resources\Leases\Pages\EditLease;
use App\Filament\Resources\Leases\Pages\ListLeases;
use App\Filament\Resources\Leases\Schemas\LeaseForm;
use App\Filament\Resources\Leases\Tables\LeasesTable;
use App\Models\Lease;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Baux de location à l'année (issue #24) : saisis une fois, ils génèrent les loyers
 * mensuels de l'exercice, qui sont des recettes ordinaires.
 */
class LeaseResource extends Resource
{
    use BlocksMemberPrincipal;
    use NavigationAware;

    protected static ?string $model = Lease::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string | \UnitEnum | null $navigationGroup = 'Mes biens';

    protected static ?string $navigationLabel = 'Baux';

    protected static ?string $modelLabel = 'bail';

    protected static ?string $pluralModelLabel = 'baux';

    // Même rang que les emprunts : le menu départage par ordre d'enregistrement, et
    // « Leases » passe avant « Loans ».
    protected static ?int $navigationSort = 5;

    // Le mode simple vise la location courte durée : le bail à l'année n'y a pas sa place.
    protected static function isHiddenInSimpleMode(): bool
    {
        return true;
    }

    protected static function getGuidedNavigationGroup(): string
    {
        return 'Mise en route';
    }

    public static function form(Schema $schema): Schema
    {
        return LeaseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeasesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeases::route('/'),
            'create' => CreateLease::route('/create'),
            'edit' => EditLease::route('/{record}/edit'),
        ];
    }
}
