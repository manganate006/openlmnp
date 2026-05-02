<?php

namespace App\Filament\Widgets;

use App\Models\Property;
use Filament\Widgets\Widget;

class AlertBanner extends Widget
{
    protected static ?int $sort = 0;

    protected string $view = 'filament.widgets.alert-banner';

    protected int|string|array $columnSpan = [
        'default' => 2,
        'sm' => 2,
        'lg' => 4,
    ];

    public function getAlerts(): array
    {
        $alerts = [];
        $year = (int) date('Y');

        $properties = Property::all();

        if ($properties->isEmpty()) {
            return [];
        }

        $totalIncome = 0;
        foreach ($properties as $property) {
            $totalIncome += $property->incomes()->whereYear('income_date', $year)->sum('amount');
        }

        if ($totalIncome === 0) {
            $alerts[] = [
                'message' => "Ajoutez vos recettes {$year} — Saisie manuelle ou import CSV Airbnb",
                'url' => '/incomes/create',
            ];
        }

        return $alerts;
    }
}
