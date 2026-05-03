<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\NavigationAware;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class HelpPage extends Page
{
    use NavigationAware;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;
    protected static string | UnitEnum | null $navigationGroup = null;
    protected static ?string $navigationLabel = 'Aide';
    protected static ?string $title = 'Guide d\'utilisation';
    protected static ?int $navigationSort = 98;
    protected string $view = 'filament.pages.help';
}
