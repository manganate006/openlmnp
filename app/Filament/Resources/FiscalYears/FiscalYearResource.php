<?php

namespace App\Filament\Resources\FiscalYears;

use App\Filament\Resources\FiscalYears\Pages\EditFiscalYear;
use App\Filament\Resources\FiscalYears\Pages\ListFiscalYears;
use App\Filament\Resources\FiscalYears\Tables\FiscalYearsTable;
use App\Filament\Pages\Concerns\NavigationAware;
use App\Models\FiscalYear;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FiscalYearResource extends Resource
{
    use NavigationAware;

    protected static ?string $model = FiscalYear::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string | \UnitEnum | null $navigationGroup = 'Fiscal';

    protected static ?string $navigationLabel = 'Exercices fiscaux';

    protected static ?string $modelLabel = 'exercice fiscal';

    protected static ?string $pluralModelLabel = 'exercices fiscaux';

    protected static ?int $navigationSort = 1;

    protected static function getGuidedNavigationGroup(): string
    {
        return 'Déclaration annuelle';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Exercice fiscal')
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        Select::make('year')
                            ->label('Année')
                            ->options(function () {
                                $years = [];
                                for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 5; $y--) {
                                    $years[$y] = $y;
                                }
                                return $years;
                            })
                            ->required()
                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'L\'exercice va du 1er janvier au 31 décembre de cette année'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return FiscalYearsTable::configure($table);
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
            'index' => ListFiscalYears::route('/'),
            'edit' => EditFiscalYear::route('/{record}/edit'),
        ];
    }
}
