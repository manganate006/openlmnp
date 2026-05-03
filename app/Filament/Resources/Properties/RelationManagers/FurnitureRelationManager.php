<?php

namespace App\Filament\Resources\Properties\RelationManagers;

use App\Enums\TvaRate;
use App\Filament\Schemas\DocumentsSection;
use App\Helpers\TvaHelper;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FurnitureRelationManager extends RelationManager
{
    protected static string $relationship = 'furniture';
    protected static ?string $title = 'Mobilier & Équipements';
    protected static ?string $modelLabel = 'mobilier';

    private function isOwnerTvaLiable(): bool
    {
        return $this->ownerRecord?->isTvaLiable() ?? false;
    }

    public function form(Schema $schema): Schema
    {
        $isTvaLiable = $this->isOwnerTvaLiable();

        return $schema->components([
            TextInput::make('description')->label('Description')->required(),
            Grid::make(2)->schema([
                TextInput::make('amount')
                    ->label($isTvaLiable ? 'Montant TTC' : 'Montant (€)')
                    ->suffix('€')->required()->numeric()
                    ->live(onBlur: true)
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : null)
                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),
                DatePicker::make('purchase_date')->label('Date d\'achat')->required()->displayFormat('d/m/Y'),
            ]),
            ...($isTvaLiable ? [
                Grid::make(2)->schema([
                    Select::make('tva_rate')
                        ->label('Taux de TVA')
                        ->options(TvaRate::options())
                        ->required()
                        ->default(TvaRate::Standard20->value)
                        ->live(),
                    Placeholder::make('tva_preview')
                        ->label('Décomposition TVA')
                        ->content(function (callable $get) {
                            $amount = (float) ($get('amount') ?? 0);
                            $rate = (int) ($get('tva_rate') ?? 0);
                            if ($amount <= 0 || $rate <= 0) {
                                return '—';
                            }
                            $ttcCents = (int) round($amount * 100);
                            $result = TvaHelper::fromTtc($ttcCents, $rate);

                            return 'HT : ' . number_format($result['ht'] / 100, 2, ',', ' ') . ' € · TVA : ' . number_format($result['tva'] / 100, 2, ',', ' ') . ' €';
                        }),
                ]),
            ] : []),
            TextInput::make('duration_years')->label('Durée amortissement')->suffix('ans')->required()->numeric()->default(5)
                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Literie, linge, petits meubles → 5 ans · TV, réfrigérateur, lave-vaisselle → 7 ans · Cuisine équipée, climatisation, jacuzzi → 10 ans · Occasion → 3 ans'),
            Toggle::make('is_dedicated')->label('100% dédié')->default(true),
            Toggle::make('is_second_hand')->label('Occasion')->default(false),
            DocumentsSection::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        $isTvaLiable = $this->isOwnerTvaLiable();

        return $table
            ->columns([
                TextColumn::make('description')->label('Description')->limit(30),
                TextColumn::make('purchase_date')->label('Date')->date('d/m/Y'),
                TextColumn::make('amount')->label($isTvaLiable ? 'TTC' : 'Montant')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0, ',', ' ') . ' €'),
                ...($isTvaLiable ? [
                    TextColumn::make('tva_rate')->label('TVA')
                        ->formatStateUsing(fn ($state) => $state ? (TvaRate::tryFrom($state)?->label() ?? $state) : '—'),
                ] : []),
                TextColumn::make('duration_years')->label('Durée')->suffix(' ans'),
                IconColumn::make('is_dedicated')->label('100%')->boolean(),
                IconColumn::make('is_second_hand')->label('Occasion')->boolean(),
                TextColumn::make('documents_count')
                    ->label('Docs')
                    ->counts('documents')
                    ->icon('heroicon-o-paper-clip')
                    ->default(0),
                TextColumn::make('annual_depreciation')->label('Amort./an')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0, ',', ' ') . ' €'),
            ])
            ->defaultSort('purchase_date', 'desc')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->headerActions([CreateAction::make()]);
    }
}
