<?php

namespace App\Livewire;

use App\Support\AiAccess;
use App\Support\HelpContentRegistry;
use Livewire\Component;

class ContextualHelp extends Component
{
    public bool $open = false;

    public string $helpView = 'help._fallback';

    public string $pageTitle = 'Aide';

    public ?string $routeName = null;

    /**
     * L'onglet Assistant n'apparaît que si l'IA peut fonctionner pour ce compte. Il
     * reste visible quand il manque seulement une clé : c'est là qu'on explique comment
     * en renseigner une.
     */
    public bool $assistantEnabled = false;

    public function mount(): void
    {
        $this->routeName = request()->route()?->getName();
        $resolved = HelpContentRegistry::resolve($this->routeName);
        $this->helpView = $resolved['view'];
        $this->pageTitle = $resolved['title'];

        $user = auth()->user();

        $this->assistantEnabled = $user !== null && ! in_array(
            AiAccess::for($user)->unavailableReason(),
            [AiAccess::REASON_DISABLED, AiAccess::REASON_SUSPENDED, AiAccess::REASON_DEMO],
            true,
        );
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
