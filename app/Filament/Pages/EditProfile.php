<?php

namespace App\Filament\Pages;

use DateTimeZone;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;

class EditProfile extends BaseEditProfile
{
    public static function isSimple(): bool
    {
        return false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('restoreOnboarding')
                ->label('Réafficher le guide de démarrage')
                ->icon('heroicon-o-rocket-launch')
                ->color('gray')
                ->visible(fn () => auth()->user()->onboarding_dismissed_at !== null)
                ->action(function () {
                    auth()->user()->update(['onboarding_dismissed_at' => null]);
                    Notification::make()
                        ->title('Guide réactivé')
                        ->body('Le guide de démarrage est de nouveau visible sur le tableau de bord.')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function form(Schema $schema): Schema
    {
        $timezones = collect(DateTimeZone::listIdentifiers())
            ->mapWithKeys(fn (string $tz) => [$tz => $tz])
            ->toArray();

        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                TextInput::make('siren')
                    ->label('SIREN')
                    ->maxLength(9)
                    ->placeholder('953353034'),
                Select::make('timezone')
                    ->label('Fuseau horaire')
                    ->options($timezones)
                    ->searchable()
                    ->required(),
                Toggle::make('mcp_enabled')
                    ->label('Activer l\'API MCP')
                    ->helperText('Permet aux assistants IA (Claude, etc.) d\'accéder à vos données comptables via le protocole MCP.')
                    ->visible(fn () => config('mcp.enabled')),
            ]);
    }
}
