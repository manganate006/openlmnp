<?php

namespace App\Livewire;

use App\Enums\NavMode;
use Livewire\Component;

class NavModeToggle extends Component
{
    public string $mode = 'simple';

    public function mount(): void
    {
        $this->mode = auth()->user()?->nav_mode?->value ?? 'simple';
    }

    public function setMode(string $mode): void
    {
        $navMode = NavMode::tryFrom($mode);
        if (! $navMode || $mode === $this->mode) {
            return;
        }

        $user = auth()->user();
        $user->nav_mode = $navMode;
        $user->save();

        $this->mode = $mode;
        $this->js("Livewire.navigate(window.location.href)");
    }

    public function render()
    {
        return view('livewire.nav-mode-toggle');
    }
}
