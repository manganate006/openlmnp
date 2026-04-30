<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class HelpPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;
    protected static string | UnitEnum | null $navigationGroup = 'Paramètres';
    protected static ?string $navigationLabel = 'Aide';
    protected static ?string $title = 'Guide d\'utilisation';
    protected static ?int $navigationSort = 10;
    protected string $view = 'filament.pages.help';
}
