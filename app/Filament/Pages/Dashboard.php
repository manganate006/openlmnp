<?php

namespace App\Filament\Pages;

use App\Support\DossierContext;
use App\Support\DossierSwitchException;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Auth;

class Dashboard extends BaseDashboard
{
    /**
     * Un compte « membre » (App\Enums\AccountKind::Member) n'a jamais de dossier à
     * lui : à la connexion, il doit arriver DIRECTEMENT dans son dossier lié plutôt
     * que sur un tableau de bord personnel vide dont la saisie lui est de toute
     * façon bloquée (lot « dossiers membre », décision du 2026-10-15).
     *
     * ⚠️ Posé ICI (le tableau de bord est la page d'ACCUEIL du panel, `->path('/')`)
     * et pas dans un middleware : un middleware agirait sur TOUTE requête du panel,
     * y compris les mises à jour Livewire internes d'un écran déjà ouvert — on ne
     * veut rebasculer qu'à l'ARRIVÉE, jamais interrompre un écran en cours.
     *
     * Un seul dossier OUVRABLE (membre actif, backer actif — voir
     * `LinkedDossier::hasActiveNonMemberBacker()`) : bascule directe. Plusieurs, ou
     * aucun : la page « Dossiers liés » (zéro affiche son état vide, qui explique
     * déjà la situation — voir LinkedDossiers::getSubheading()).
     */
    public function mount(): void
    {
        $user = Auth::user();

        if (DossierContext::isMemberActingAsPrincipal($user) && config('dossiers.enabled')) {
            $ouvrables = DossierContext::dossiersFor($user)
                ->filter(fn ($dossier) => $dossier->hasActiveNonMemberBacker());

            if ($ouvrables->count() === 1) {
                try {
                    DossierContext::switchTo($ouvrables->first());
                    $this->redirect(Filament::getUrl());

                    return;
                } catch (DossierSwitchException) {
                    // Changement d'état entre la lecture et la bascule (révocation
                    // concurrente) : on retombe sur la liste, qui revérifiera tout.
                }
            }

            if ($ouvrables->count() > 1) {
                $this->redirect(LinkedDossiers::getUrl());

                return;
            }
        }

        // ⚠️ PAS de `parent::mount()` : `Filament\Pages\Dashboard` n'en déclare
        // aucun (seuls ses traits portent des hooks `mount{Trait}`, appelés par
        // Livewire INDÉPENDAMMENT de cette méthode — voir HandleComponents::
        // getMountMethodParameters()). L'appeler lèverait une erreur fatale.
    }

    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'sm' => 2,
            'lg' => 4,
        ];
    }
}
