<?php

namespace App\Livewire;

use App\Enums\NavMode;
use App\Support\DossierContext;
use Livewire\Component;

class NavModeToggle extends Component
{
    public string $mode = 'simple';

    public function mount(): void
    {
        // Mode de navigation : résolu sur le PRINCIPAL (plan des dossiers liés § 2.3),
        // comme App\Filament\Pages\Concerns\NavigationAware::getNavigationGroup() —
        // sinon le menu changerait de forme à chaque bascule de dossier.
        $this->mode = DossierContext::principal()?->nav_mode?->value ?? 'simple';
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

        // Le compte du dossier ACTIF reçoit aussi la valeur : de nombreux écrans lisent
        // encore `Auth::user()->nav_mode` directement (hors NavigationAware), et sans ce
        // miroir le bouton n'aurait aucun effet visible tant qu'on reste dans ce dossier.
        if (($principal = DossierContext::principal($user)) !== null && $principal->isNot($user)) {
            $principal->nav_mode = $navMode;
            $principal->save();
        }

        $this->mode = $mode;
        $this->js("Livewire.navigate(window.location.href)");
    }

    public function render()
    {
        return view('livewire.nav-mode-toggle');
    }
}
