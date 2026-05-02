<?php

namespace App\Filament\Widgets;

use App\Services\BadgeService;
use Filament\Widgets\Widget;

class BadgesWidget extends Widget
{
    protected string $view = 'filament.widgets.badges-widget';
    protected int | string | array $columnSpan = 'full';
    protected static ?int $sort = 3;

    public function getData(): array
    {
        $user = auth()->user();
        $year = (int) date('Y');
        $service = app(BadgeService::class);

        $completeness = $service->getCompletenessScore($user, $year);
        $heatmap = $service->getMonthlyHeatmap($user, $year);

        $recentBadges = $user->userBadges()
            ->with('definition')
            ->orderByDesc('unlocked_at')
            ->limit(5)
            ->get();

        $nextBadge = $service->getNextAchievable($user);

        $totalBadges = $user->badgeCount();
        $totalPossible = \App\Models\BadgeDefinition::active()->where('is_yearly', false)->count();
        $yearlyEarned = $user->userBadges()
            ->whereHas('definition', fn ($q) => $q->where('is_yearly', true))
            ->where('fiscal_year', $year)
            ->count();
        $yearlyTotal = \App\Models\BadgeDefinition::active()->where('is_yearly', true)->count();

        return [
            'year' => $year,
            'completeness' => $completeness,
            'heatmap' => $heatmap,
            'recentBadges' => $recentBadges,
            'nextBadge' => $nextBadge,
            'totalBadges' => $totalBadges,
            'totalPossible' => $totalPossible,
            'yearlyEarned' => $yearlyEarned,
            'yearlyTotal' => $yearlyTotal,
            'months' => ['Jan', 'Fev', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aou', 'Sep', 'Oct', 'Nov', 'Dec'],
        ];
    }
}
