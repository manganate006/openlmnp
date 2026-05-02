<?php

namespace App\Filament\Pages;

use DateTimeZone;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class EditProfile extends BaseEditProfile
{
    public static function isSimple(): bool
    {
        return false;
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
            ]);
    }
}
