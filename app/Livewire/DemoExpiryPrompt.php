<?php

namespace App\Livewire;

use App\Models\User;
use App\Notifications\DemoResumeLink;
use App\Support\DemoExpiry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Compte à rebours du bac à sable, et relances avant effacement.
 *
 * Monté globalement par un render hook `panels::body.end`, comme {@see FeedbackPrompt} et
 * `ContextualHelp`. Le composant rend un conteneur INERTE : le serveur fournit la date
 * d'expiration et la liste des paliers encore dus, mais c'est le navigateur qui égrène les
 * secondes et réclame l'ouverture — le serveur ne sait pas depuis quand une page est ouverte.
 *
 * Le serveur garde la décision : {@see DemoExpiry::isReached()} revalide tout palier réclamé
 * avant de l'enregistrer. Sans cela, n'importe qui pourrait faire marquer les paliers
 * restants comme servis et se soustraire à toutes les relances suivantes.
 *
 * ⚠️ La pastille est en bas DROITE, EMPILÉE au-dessus de `.ctx-help-btn` (48 px à 24 px des
 * bords, donc 72 px au total). Le bas gauche paraissait libre puisqu'il évitait l'aide
 * contextuelle, mais il tombe sur le sélecteur de mode de navigation du pied de barre
 * latérale — vérifié au rendu, la pastille le recouvrait.
 */
class DemoExpiryPrompt extends Component
{
    /** « idle » | « banner » | « modal » | « offer » | « extend » | « extended » */
    public string $step = 'idle';

    public bool $applies = false;

    /** Date d'expiration au format ISO, lue par la minuterie du navigateur. */
    public ?string $expiresAtIso = null;

    public int $remainingSeconds = 0;

    /**
     * Paliers encore dus, du plus lointain au plus proche : [['hours' => 6, 'form' => 'modal']].
     *
     * @var list<array{hours: int, form: string}>
     */
    public array $pending = [];

    public bool $canExtend = false;

    public bool $hasOffer = false;

    /** URL de l'iframe commerciale. Vide en auto-hébergé : l'offre n'existe alors pas. */
    public string $offerUrl = '';

    public int $iframeTimeoutMs = 4000;

    /** Lien de reprise, affiché ET envoyé par courriel après prolongation. */
    public string $resumeUrl = '';

    /** Décompte de ce qui serait perdu, pour ne pas dramatiser devant un sandbox vide. */
    public array $atRisk = [];

    #[Validate('required|email|max:190')]
    public string $email = '';

    #[Validate('accepted')]
    public bool $consent = false;

    public function mount(): void
    {
        $expiry = DemoExpiry::for(Auth::user());

        $this->applies = $expiry->applies();

        if (! $this->applies) {
            return;
        }

        $user = Auth::user();

        $this->expiresAtIso = $expiry->expiresAt()?->toIso8601String();
        $this->remainingSeconds = $expiry->remainingSeconds();
        $this->canExtend = $expiry->canExtend();
        $this->hasOffer = $expiry->hasOffer();
        $this->iframeTimeoutMs = (int) config('demo.iframe_timeout_ms');
        $this->atRisk = $this->countAtRisk($user);

        $seen = $expiry->seen();
        $mostUrgentSeen = $seen === [] ? PHP_INT_MAX : min($seen);

        foreach ($expiry->applicableThresholds() as $hours => $form) {
            if ($hours < $mostUrgentSeen) {
                $this->pending[] = ['hours' => $hours, 'form' => $form];
            }
        }
    }

    /**
     * Le navigateur signale qu'un palier vient d'être franchi.
     */
    public function reach(int $hours): void
    {
        $expiry = DemoExpiry::for(Auth::user());

        if (! $expiry->isReached($hours)) {
            return;
        }

        $form = $expiry->applicableThresholds()[$hours] ?? null;

        if ($form === null) {
            return;
        }

        $expiry->markSeen($hours);

        $this->step = $form;
        $this->remainingSeconds = $expiry->remainingSeconds();
        $this->pending = [];

        $this->dispatch('analytics', [
            'event' => 'demo_expiry_prompted',
            'demo_threshold' => $hours,
            'demo_format' => $form,
        ]);
    }

    /**
     * « Garder mes données » : pose le jeton de reprise, gèle l'expiration le temps du
     * paiement, et ouvre l'iframe commerciale.
     */
    public function keepData(): void
    {
        $user = Auth::user();

        if (! DemoExpiry::for($user)->applies()) {
            return;
        }

        if (! $this->hasOffer) {
            // Sans cible configurée (auto-hébergé), « garder » ne peut vouloir dire que
            // « prolonger » : on n'affiche pas un cadre vide en guise d'offre.
            $this->step = 'extend';

            return;
        }

        $token = $user->demo_claim_token ?: Str::random(48);

        // Le bac à sable doit survivre au temps du paiement : quelqu'un qui sort sa carte
        // à une heure de l'effacement ne doit pas revenir sur un compte purgé.
        $floor = Carbon::now()->addDays((int) config('demo.extended_ttl_days'));

        $user->forceFill([
            'demo_claim_token' => $token,
            'demo_expires_at' => $user->demo_expires_at?->max($floor) ?? $floor,
        ])->save();

        $this->offerUrl = $this->buildOfferUrl($token);
        $this->step = 'offer';

        $this->dispatch('analytics', ['event' => 'demo_claim_started']);
    }

    /**
     * « Non merci » : on referme l'offre et on enchaîne sur la prolongation.
     *
     * Ce bouton appartient au pied de la modale, HORS de l'iframe. C'est ce qui évite
     * tout `postMessage` : le refus n'a jamais à traverser la frontière d'origine.
     */
    public function declineOffer(): void
    {
        $this->step = DemoExpiry::for(Auth::user())->canExtend() ? 'extend' : 'idle';
        $this->offerUrl = '';

        $this->dispatch('analytics', ['event' => 'demo_offer_declined']);
    }

    public function extend(): void
    {
        $this->validate();

        $user = Auth::user();
        $expiry = DemoExpiry::for($user);

        if (! $expiry->canExtend()) {
            $this->step = 'idle';

            return;
        }

        $user->forceFill([
            'demo_email' => $this->email,
            'demo_email_consent_at' => Carbon::now(),
            'demo_extended_at' => Carbon::now(),
            'demo_expires_at' => Carbon::now()->addDays((int) config('demo.extended_ttl_days')),
        ])->save();

        DemoExpiry::resetSeen($user);

        $this->resumeUrl = DemoResumeLink::urlFor($user);
        $user->notify(new DemoResumeLink($this->resumeUrl));

        $fresh = DemoExpiry::for($user->fresh());
        $this->expiresAtIso = $fresh->expiresAt()?->toIso8601String();
        $this->remainingSeconds = $fresh->remainingSeconds();
        $this->canExtend = false;
        $this->step = 'extended';

        $this->dispatch('analytics', ['event' => 'demo_extended']);
    }

    public function dismiss(): void
    {
        $this->step = 'idle';
        $this->offerUrl = '';
    }

    /**
     * Ouvre la prolongation depuis la pastille, sans attendre un palier.
     */
    public function openExtend(): void
    {
        $this->step = DemoExpiry::for(Auth::user())->canExtend() ? 'extend' : 'modal';
    }

    public function render()
    {
        return view('livewire.demo-expiry-prompt');
    }

    /**
     * Phrase courte du bandeau : « Vos 2 biens et vos 4 exercices ».
     *
     * ⚠️ Accords écrits à la main. `Str::plural()` est un pluraliseur ANGLAIS : il rend
     * « sas » pour « sa » et « sons » pour « son ». Il tombe juste sur « biens » et
     * « exercices » par coïncidence, pas par compétence.
     */
    public function atRiskSentence(): string
    {
        $parts = [];

        if (($this->atRisk['properties'] ?? 0) > 0) {
            $n = $this->atRisk['properties'];
            $parts[] = "vos {$n} bien".($n > 1 ? 's' : '');
        }

        if (($this->atRisk['fiscal_years'] ?? 0) > 0) {
            $n = $this->atRisk['fiscal_years'];
            $parts[] = "vos {$n} exercice".($n > 1 ? 's' : '');
        }

        return ucfirst(implode(' et ', $parts));
    }

    /**
     * Lignes de l'encart « ce que vous perdriez ».
     *
     * @return list<string>
     */
    public function atRiskLines(): array
    {
        $lines = [];

        if (($this->atRisk['properties'] ?? 0) > 0) {
            $n = $this->atRisk['properties'];
            $lines[] = $n > 1
                ? "{$n} biens et leur ventilation par composants"
                : '1 bien et sa ventilation par composants';
        }

        if (($this->atRisk['fiscal_years'] ?? 0) > 0) {
            $n = $this->atRisk['fiscal_years'];
            $lines[] = $n > 1
                ? "{$n} exercices et leurs amortissements"
                : '1 exercice et son amortissement';
        }

        return $lines;
    }

    /**
     * URL de l'iframe. Le jeton voyage jusqu'aux metadata Stripe puis revient par le webhook.
     */
    private function buildOfferUrl(string $token): string
    {
        $base = (string) config('demo.links.pro');

        return $base.(str_contains($base, '?') ? '&' : '?').http_build_query([
            'demo' => $token,
            'embed' => 1,
        ]);
    }

    /**
     * Ce que le visiteur perdrait, compté sur ses données RÉELLES.
     *
     * Rédiger l'encart d'avance dramatiserait devant quelqu'un qui a cliqué trois fois.
     * Les chiffres sont déjà en base : les compter ne coûte rien, et c'est la seule façon
     * de distinguer celui qui a investi une heure de celui qui passait.
     *
     * Le jeu d'exemple est exclu du décompte via `demo_seed` : il n'a pas été « perdu »
     * par quelqu'un qui ne l'a pas saisi.
     */
    private function countAtRisk(User $user): array
    {
        $seededProperty = $user->demo_seed['property_id'] ?? null;

        $properties = $user->properties()
            ->when($seededProperty, fn ($q) => $q->whereKeyNot($seededProperty))
            ->count();

        $seededYears = $user->demo_seed['fiscal_year_ids'] ?? [];

        $years = $user->fiscalYears()
            ->when($seededYears !== [], fn ($q) => $q->whereKeyNot($seededYears))
            ->count();

        return array_filter([
            'properties' => $properties,
            'fiscal_years' => $years,
        ]);
    }
}
