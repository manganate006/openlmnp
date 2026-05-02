<?php

namespace App\Filament\Resources\Properties\RelationManagers;

use App\Models\PropertyComponent;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ComponentsRelationManager extends RelationManager
{
    protected static string $relationship = 'components';
    protected static ?string $title = 'Composants d\'amortissement';
    protected static ?string $modelLabel = 'composant';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Composant')->required(),
            TextInput::make('percentage')->label('Pourcentage')->suffix('%')->required()->numeric()
                ->rules([
                    fn (?Model $record): Closure => function (string $attribute, $value, Closure $fail) use ($record) {
                        $propertyId = $this->ownerRecord->id;
                        $existingSum = PropertyComponent::where('property_id', $propertyId)
                            ->when($record, fn ($q) => $q->where('id', '!=', $record->id))
                            ->sum('percentage');
                        $total = $existingSum + (int) $value;
                        if ($total > 100) {
                            $fail("Le total des pourcentages dépasserait 100 % ({$existingSum} % existants + {$value} % = {$total} %).");
                        }
                    },
                ]),
            TextInput::make('duration_years')->label('Durée')->suffix('ans')->required()->numeric(),
            TextInput::make('base_amount')->label('Base (€)')
                ->suffix('€')->numeric()
                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : null)
                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),
            TextInput::make('annual_depreciation')->label('Amort. annuel (€)')
                ->suffix('€')->numeric()
                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : null)
                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Composant'),
                TextColumn::make('percentage')->label('%')->suffix(' %')
                    ->summarize(
                        Sum::make()->label('Total')->suffix(' %')
                            ->extraAttributes(fn ($state) => [
                                'class' => match (true) {
                                    $state > 100 => 'text-danger-600 dark:text-danger-400 font-bold',
                                    $state < 100 => 'text-warning-600 dark:text-warning-400 font-bold',
                                    default => 'text-success-600 dark:text-success-400 font-bold',
                                },
                            ]),
                    ),
                TextColumn::make('duration_years')->label('Durée')->suffix(' ans'),
                TextColumn::make('base_amount')->label('Base')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0, ',', ' ') . ' €'),
                TextColumn::make('annual_depreciation')->label('Amort./an')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0, ',', ' ') . ' €'),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make()->after(fn () => $this->warnIfUnder100()),
                DeleteAction::make(),
            ])
            ->headerActions([
                CreateAction::make()->after(fn () => $this->warnIfUnder100()),
            ]);
    }

    private function warnIfUnder100(): void
    {
        $total = PropertyComponent::where('property_id', $this->ownerRecord->id)->sum('percentage');
        if ($total < 100) {
            Notification::make()
                ->warning()
                ->title('Total des pourcentages incomplet')
                ->body("Le total des composants est de {$total} % (attendu : 100 %).")
                ->persistent()
                ->send();
        }
    }
}
