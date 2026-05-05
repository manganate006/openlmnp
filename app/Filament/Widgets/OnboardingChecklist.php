<?php

namespace App\Filament\Widgets;

use App\Services\OnboardingChecklistService;
use Filament\Widgets\Widget;
use Livewire\Attributes\On;

class OnboardingChecklist extends Widget
{
    protected string $view = 'filament.widgets.onboarding-checklist';

    protected int|string|array $columnSpan = [
        'default' => 1,
        'sm' => 2,
        'lg' => 4,
    ];

    protected static ?int $sort = -1;

    public int $year;

    public function mount(): void
    {
        $this->year = (int) date('Y') - 1;
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        if ($user->onboarding_dismissed_at) {
            return false;
        }

        return true;
    }

    public function getData(): array
    {
        $user = auth()->user();
        $service = app(OnboardingChecklistService::class);

        return [
            'steps' => $service->getChecklist($user, $this->year),
            'progress' => $service->getProgress($user, $this->year),
            'year' => $this->year,
        ];
    }

    public function setYear(int $year): void
    {
        $this->year = $year;
    }

    public function dismiss(): void
    {
        auth()->user()->update(['onboarding_dismissed_at' => now()]);
        $this->redirect('/');
    }
}
