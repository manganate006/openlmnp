<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\DemoSeedChoiceService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Sort réservé aux données d'exemple, au premier accès d'un compte promu.
 *
 * Il n'apparaît qu'une fois, et le choix n'est pas récupérable : c'est une suppression.
 * L'option conseillée — « ne garder que mes saisies » — n'est possible que parce que
 * `DemoDataService::seedForUser()` ne crée QU'UN bien et mémorise lequel dans `demo_seed`.
 */
class DemoSeedChoice extends Component
{
    public bool $applies = false;

    public string $choice = DemoSeedChoiceService::MINE_ONLY;

    /** Décomptes réels, pour que chaque option annonce ce qu'elle supprime. */
    public int $sampleProperties = 0;

    public int $sampleYears = 0;

    public int $ownProperties = 0;

    public int $ownYears = 0;

    public function mount(): void
    {
        $user = Auth::user();

        if ($user === null || blank($user->demo_promoted_at) || filled($user->demo_seed_choice)) {
            return;
        }

        $this->applies = true;
        $this->count($user);
    }

    public function apply(DemoSeedChoiceService $service): void
    {
        $user = Auth::user();

        if ($user === null || ! $service->apply($user, $this->choice)) {
            return;
        }

        $this->dispatch('analytics', [
            'event' => 'demo_seed_choice',
            'demo_choice' => $this->choice,
        ]);

        // Rechargement complet : les widgets du tableau de bord affichent des totaux qui
        // viennent d'être recalculés, et un rendu partiel les laisserait sur l'ancien état.
        $this->redirect('/', navigate: false);
    }

    /**
     * Ce que l'option supprime et ce qu'elle conserve, en chiffres réels.
     *
     * Une formule vague (« vos données d'exemple ») laisse le visiteur deviner. Les chiffres
     * sont déjà en base : les afficher ne coûte rien, et c'est la différence entre un choix
     * éclairé et un pari sur une suppression irréversible.
     */
    public function summaryFor(string $choice): string
    {
        $sample = $this->sampleProperties + $this->sampleYears;
        $own = $this->ownProperties + $this->ownYears;

        return match ($choice) {
            DemoSeedChoiceService::MINE_ONLY => $sample === 0
                ? 'Rien à supprimer : aucune donnée d\'exemple'
                : 'Supprime '.$this->phrase($this->sampleProperties, $this->sampleYears)
                    .' · conserve '.($own === 0 ? 'un compte vide' : $this->phrase($this->ownProperties, $this->ownYears)),
            DemoSeedChoiceService::KEEP_ALL => 'Conserve '.$this->phrase(
                $this->sampleProperties + $this->ownProperties,
                $this->sampleYears + $this->ownYears,
            ),
            default => 'Supprime '.$this->phrase(
                $this->sampleProperties + $this->ownProperties,
                $this->sampleYears + $this->ownYears,
            ),
        };
    }

    /**
     * ⚠️ Accords écrits à la main : `Str::plural()` est un pluraliseur ANGLAIS.
     */
    private function phrase(int $properties, int $years): string
    {
        $parts = [];

        if ($properties > 0) {
            $parts[] = $properties.' bien'.($properties > 1 ? 's' : '');
        }

        if ($years > 0) {
            $parts[] = $years.' exercice'.($years > 1 ? 's' : '');
        }

        return $parts === [] ? 'rien' : implode(' et ', $parts);
    }

    public function render()
    {
        return view('livewire.demo-seed-choice');
    }

    private function count(User $user): void
    {
        $seed = $user->demo_seed ?? [];
        $sampleProperty = $seed['property_id'] ?? null;
        $sampleYears = $seed['fiscal_year_ids'] ?? [];

        $this->sampleProperties = $sampleProperty ? 1 : 0;
        $this->sampleYears = count($sampleYears);

        $this->ownProperties = $user->properties()
            ->when($sampleProperty, fn ($q) => $q->whereKeyNot($sampleProperty))
            ->count();

        $this->ownYears = $user->fiscalYears()
            ->when($sampleYears !== [], fn ($q) => $q->whereKeyNot($sampleYears))
            ->count();
    }
}
