<?php

namespace App\Filament\Widgets;

use App\Models\Property;
use Filament\Widgets\Widget;

class AlertBanner extends Widget
{
    protected static ?int $sort = 0;

    protected string $view = 'filament.widgets.alert-banner';

    protected int|string|array $columnSpan = [
        'default' => 1,
        'sm' => 2,
        'lg' => 4,
    ];

    public function getAlerts(): array
    {
        // Pas d'alertes si la checklist onboarding est visible (évite les doublons)
        if (! auth()->user()->onboarding_dismissed_at) {
            return [];
        }

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
