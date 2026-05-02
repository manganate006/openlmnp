<?php

namespace App\Livewire;

use App\Support\HelpContentRegistry;
use Livewire\Component;

class ContextualHelp extends Component
{
    public bool $open = false;

    public string $helpView = 'help._fallback';

    public string $pageTitle = 'Aide';

    public function mount(): void
    {
        $routeName = request()->route()?->getName();
        $resolved = HelpContentRegistry::resolve($routeName);
        $this->helpView = $resolved['view'];
        $this->pageTitle = $resolved['title'];
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function render()
    {
        return view('livewire.contextual-help');
    }
}
