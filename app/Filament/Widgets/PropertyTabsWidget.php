<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class PropertyTabsWidget extends Widget
{
    protected string $view = 'filament.widgets.property-tabs';

    protected int | string | array $columnSpan = 'full';

    public ?int $propertyId = null;
    public string $active = 'general';
}
