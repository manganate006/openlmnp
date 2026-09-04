<?php

namespace App\Livewire;

use App\Models\Feedback;
use App\Notifications\FeedbackReceived;
use App\Services\UpdateService;
use App\Support\FeedbackEligibility;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Invitation ponctuelle à donner son avis sur le logiciel.
 *
 * Monté globalement par un render hook `panels::body.end`, comme `ContextualHelp`.
 * Le composant ne décide PAS seul du moment : il rend un conteneur inerte et c'est le
 * navigateur qui l'ouvre, une fois le temps écoulé ET les actions franchies (le serveur
 * n'a aucun moyen de savoir combien de temps une page est restée ouverte).
 *
 * Trois mises en forme sont en lice (`config('feedback.variants')`), tirées au sort et
 * figées par cookie. Une ligne est écrite dès l'AFFICHAGE : c'est le dénominateur sans
 * lequel aucun taux de réponse n'existe.
 */
class FeedbackPrompt extends Component
{
    /** « idle » | « ask » | « positive » | « negative » | « done » */
    public string $step = 'idle';

    #[Validate('nullable|string|max:2000')]
    public string $message = '';

    #[Validate('nullable|string|max:80')]
    public string $authorName = '';

    #[Validate('nullable|email|max:190')]
    public string $authorEmail = '';

    public bool $canPublish = false;

    /** Renseignés au montage, lus par le minuteur côté navigateur. */
    public bool $eligible = false;

    public int $minSeconds = 0;

    public int $minActions = 0;

    /** @var list<string> */
    public array $trackedActions = [];

    public string $trigger = Feedback::TRIGGER_SESSION;

    public string $audience = Feedback::AUDIENCE_USER;

    /** Mise en forme tirée au sort : « a », « b » ou « c ». */
    public string $variant = FeedbackEligibility::VARIANT_FALLBACK;

    /** Ligne créée à l'affichage, complétée ensuite par les réponses. */
    public ?int $feedbackId = null;

    /**
     * Route de la PAGE qui portait le composant. Capturée au montage : dans une requête
     * Livewire, `request()->route()` désigne l'endpoint de mise à jour, pas la page.
     */
    public ?string $pageRoute = null;

    public function mount(): void
    {
        $eligibility = FeedbackEligibility::for(Auth::user(), request()->cookies->all());

        $this->eligible = $eligibility->shouldOffer();
        $this->pageRoute = request()->route()?->getName();

        if (! $this->eligible) {
            return;
        }

        $thresholds = $eligibility->thresholds();
        $this->minSeconds = $thresholds['minSeconds'];
        $this->minActions = $thresholds['minActions'];
        $this->trackedActions = $thresholds['actions'];
        $this->trigger = $eligibility->trigger();
        $this->audience = $eligibility->audience();
        $this->variant = $eligibility->variant();
    }

    /**
     * Le navigateur a constaté que les conditions étaient réunies.
     */
    public function open(): void
    {
        if (! $this->eligible || $this->step !== 'idle') {
            return;
        }

        $this->step = 'ask';

        // L'impression est enregistrée ici, avant toute réponse. C'est ce qui permet de
        // dire « 12 affichages, 3 réponses » plutôt que « 3 réponses », qui ne se compare
        // à rien — et donc de départager les trois variantes.
        $this->record();

        Auth::user()?->update(['feedback_prompted_at' => now()]);

        // La variante est figée dès maintenant : la personne l'a vue, elle ne doit plus
        // en changer, y compris après la purge de son compte de démonstration.
        $this->remember(['v' => $this->variant]);

        $this->dispatch('analytics', [
            'event' => 'feedback_prompted',
            'feedback_variant' => $this->variant,
            'feedback_trigger' => $this->trigger,
        ]);
    }

    public function choose(string $sentiment): void
    {
        if ($this->step !== 'ask') {
            return;
        }

        $sentiment = $sentiment === Feedback::SENTIMENT_POSITIVE
            ? Feedback::SENTIMENT_POSITIVE
            : Feedback::SENTIMENT_NEGATIVE;

        $this->step = $sentiment === Feedback::SENTIMENT_POSITIVE ? 'positive' : 'negative';

        // Le sentiment est la seule réponse que tout le monde donne : on l'enregistre
        // tout de suite. Attendre l'envoi du formulaire perdrait l'immense majorité des
        // réponses, celles des gens qui cliquent puis referment.
        $this->currentFeedback()?->update(['sentiment' => $sentiment]);

        $this->dispatch('analytics', [
            'event' => 'feedback_given',
            'feedback_variant' => $this->variant,
            'sentiment' => $sentiment,
        ]);
    }

    /**
     * Complète le retour déjà enregistré avec le texte, s'il y en a un.
     */
    public function submit(): void
    {
        if (! in_array($this->step, ['positive', 'negative'], true)) {
            return;
        }

        $this->validate();

        $feedback = $this->currentFeedback();

        if ($feedback) {
            $feedback->update([
                'message' => trim($this->message) ?: null,
                'author_name' => trim($this->authorName) ?: null,
                'author_email' => trim($this->authorEmail) ?: null,
                // Un consentement ne vaut que s'il porte sur un texte.
                'can_publish' => $this->canPublish && filled(trim($this->message)),
            ]);

            $this->forward($feedback->refresh());
        }

        $this->step = 'done';
        $this->close();

        $this->dispatch('analytics', [
            'event' => 'feedback_message',
            'feedback_variant' => $this->variant,
            'sentiment' => $feedback?->sentiment ?? 'unknown',
            'has_message' => filled(trim($this->message)) ? 'yes' : 'no',
        ]);
    }

    public function dismiss(): void
    {
        $this->currentFeedback()?->update(['dismissed_at' => now()]);

        $this->step = 'idle';
        $this->eligible = false;
        $this->close();

        $this->dispatch('analytics', [
            'event' => 'feedback_dismissed',
            'feedback_variant' => $this->variant,
        ]);
    }

    /**
     * Adresse `mailto:` préremplie — le chemin des instances auto-hébergées, qui ne
     * transmettent rien d'elles-mêmes. C'est l'utilisateur qui envoie, s'il le veut.
     */
    public function getMailtoLinkProperty(): string
    {
        return 'mailto:'.config('feedback.links.contact').'?'.http_build_query([
            'subject' => 'Retour sur OpenLMNP',
            'body' => $this->message,
        ]);
    }

    public function getForwardsProperty(): bool
    {
        return filled(config('feedback.forward_email'));
    }

    /**
     * Propose-t-on l'offre hébergée ? Deux conditions, cumulatives :
     *
     *  - la personne est dans la DÉMONSTRATION — un compte réel est déjà client ou
     *    auto-hébergé, lui vendre l'offre n'aurait aucun sens ;
     *  - une URL est configurée — vide par défaut, donc rien ne s'affiche sur une instance
     *    auto-hébergée, et le dépôt public ne porte aucun argumentaire commercial.
     */
    public function getShowsProCtaProperty(): bool
    {
        return $this->audience === Feedback::AUDIENCE_DEMO
            && filled(config('feedback.links.pro'));
    }

    /**
     * Durée de vie du bac à sable, telle qu'elle est réellement configurée sur CETTE
     * instance : le texte du bloc l'annonce, autant qu'il dise vrai partout.
     */
    public function getDemoHoursProperty(): int
    {
        return max(1, (int) config('demo.ttl_hours'));
    }

    public function render()
    {
        return view('livewire.feedback-prompt');
    }

    private function record(): void
    {
        $feedback = Feedback::create([
            'sentiment' => null,
            'variant' => $this->variant,
            'audience' => $this->audience,
            'trigger' => $this->trigger,
            // Contexte technique seulement — jamais un montant, jamais une donnée fiscale.
            'context' => [
                'route' => $this->pageRoute,
                'version' => app(UpdateService::class)->getCurrentVersion(),
            ],
            'user_id' => Auth::id(),
        ]);

        $this->feedbackId = $feedback->id;
    }

    private function currentFeedback(): ?Feedback
    {
        return $this->feedbackId ? Feedback::find($this->feedbackId) : null;
    }

    private function forward(Feedback $feedback): void
    {
        $address = config('feedback.forward_email');

        if (blank($address)) {
            // Instance auto-hébergée : rien ne sort de la machine. Le retour reste en
            // base, lisible par l'administrateur de l'instance, et l'utilisateur se voit
            // proposer de l'envoyer lui-même.
            return;
        }

        try {
            Notification::route('mail', $address)->notify(new FeedbackReceived($feedback));
        } catch (\Throwable $e) {
            // Un relais SMTP mal configuré ne doit pas faire perdre le retour ni afficher
            // une erreur à quelqu'un qui vient de rendre service : la ligne est déjà en base.
            report($e);
        }
    }

    private function close(): void
    {
        Auth::user()?->update(['feedback_answered_at' => now()]);

        $this->remember(['v' => $this->variant, 'at' => now()->toDateString()]);
    }

    /**
     * Le cookie est la seule mémoire qui survive à la purge d'un compte de
     * démonstration : sans lui, le prochain sandbox reposerait la même question, et
     * avec une autre mise en forme.
     */
    private function remember(array $state): void
    {
        Cookie::queue(
            FeedbackEligibility::COOKIE_STATE,
            json_encode($state),
            60 * 24 * 400,
        );
    }
}
