<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\NavigationAware;
use App\Models\BadgeDefinition;
use App\Models\BadgeProgress;
use App\Services\BadgeService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Badges extends Page
{
    use NavigationAware;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;
    protected static string | UnitEnum | null $navigationGroup = null;
    protected static ?string $navigationLabel = 'Badges';
    protected static ?string $title = 'Mes badges';
    protected static ?int $navigationSort = 99;
    protected static ?string $slug = 'badges';
    protected string $view = 'filament.pages.badges';

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        if (!$user) {
            return null;
        }

        $earned = $user->userBadges()->count();
        $total = BadgeDefinition::active()->count();

        return "{$earned}/{$total}";
    }

    public function getBadgeData(): array
    {
        $user = auth()->user();
        $year = (int) date('Y');

        $categories = [
            'onboarding' => [
                'label' => 'Démarrage',
                'icon' => 'heroicon-o-rocket-launch',
                'description' => 'Premiers pas pour configurer votre comptabilité LMNP.',
            ],
            'regularite' => [
                'label' => 'Régularité',
                'icon' => 'heroicon-o-calendar-days',
                'description' => 'Récompense la saisie régulière de vos données comptables. Obtenable chaque année.',
            ],
            'qualite' => [
                'label' => 'Qualité',
                'icon' => 'heroicon-o-shield-check',
                'description' => 'Indicateurs de qualité et conformité de vos données. Obtenable chaque année.',
            ],
            'exploration' => [
                'label' => 'Exploration',
                'icon' => 'heroicon-o-magnifying-glass',
                'description' => 'Découverte des outils d\'analyse et de pilotage.',
            ],
        ];

        $allBadges = BadgeDefinition::active()->orderBy('sort_order')->get();
        $userBadges = $user->userBadges()->with('definition')->get();
        $progressRecords = BadgeProgress::where('user_id', $user->id)->get();

        $grouped = [];
        $totalEarned = 0;
        $totalPossibleThisYear = 0;
        $earnedThisYearCount = 0;

        foreach ($categories as $catCode => $catInfo) {
            $badges = $allBadges->where('category', $catCode);
            $items = [];
            $catEarned = 0;

            foreach ($badges as $badge) {
                $earned = $userBadges->where('badge_definition_id', $badge->id);

                if ($badge->is_yearly) {
                    $earnedYears = $earned->pluck('fiscal_year')->sort()->values()->toArray();
                    $isEarnedThisYear = in_array($year, $earnedYears);
                    $totalPossibleThisYear++;

                    // Progress for current year
                    $progress = $progressRecords
                        ->where('badge_definition_id', $badge->id)
                        ->where('fiscal_year', $year)
                        ->first();

                    $items[] = [
                        'badge' => $badge,
                        'earned' => $isEarnedThisYear,
                        'earned_years' => $earnedYears,
                        'is_yearly' => true,
                        'total_earned' => count($earnedYears),
                        'progress' => $progress ? [
                            'current' => $progress->current_value,
                            'target' => $progress->target_value,
                            'percentage' => $progress->percentage,
                        ] : null,
                    ];

                    if ($isEarnedThisYear) {
                        $earnedThisYearCount++;
                    }
                    $catEarned += count($earnedYears);
                } else {
                    $earnedBadge = $earned->first();
                    $isEarned = $earnedBadge !== null;

                    $items[] = [
                        'badge' => $badge,
                        'earned' => $isEarned,
                        'earned_at' => $earnedBadge?->unlocked_at,
                        'is_yearly' => false,
                        'progress' => null,
                    ];

                    if ($isEarned) {
                        $catEarned++;
                    }
                }

                if (isset($items[count($items) - 1]['earned']) && $items[count($items) - 1]['earned']) {
                    $totalEarned++;
                }
            }

            $grouped[$catCode] = [
                'label' => $catInfo['label'],
                'icon' => $catInfo['icon'],
                'description' => $catInfo['description'],
                'items' => $items,
                'earned_count' => $catEarned,
                'total_count' => $badges->count(),
            ];
        }

        return [
            'categories' => $grouped,
            'totalEarned' => $userBadges->count(),
            'totalDefinitions' => $allBadges->count(),
            'earnedThisYear' => $earnedThisYearCount,
            'totalPossibleThisYear' => $totalPossibleThisYear,
            'year' => $year,
        ];
    }
}
