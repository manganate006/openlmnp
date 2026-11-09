<?php

namespace App\Livewire;

use App\Models\AiChatReport;
use App\Models\AiUsageLog;
use App\Services\Ai\Assistant\AssistantService;
use App\Services\Ai\Assistant\Conversation;
use App\Services\Ai\Assistant\ConversationRedactor;
use App\Services\Ai\Assistant\SafeMarkdown;
use App\Services\Ai\Assistant\ToolBridge;
use App\Support\AiAccess;
use App\Support\HelpContentRegistry;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * L'onglet « Assistant » du panneau d'aide. La conversation vit dans l'état du
 * composant : rien n'est écrit en base, elle disparaît au rechargement de la page.
 * Seule exception, à la demande de l'utilisateur : sur un pouce vers le bas, il peut
 * cocher « joindre la conversation », qui est alors masquée puis conservée 90 jours
 * (AiChatReport).
 */
class AiAssistant extends Component
{
    public const MAX_INPUT = 1000;

    /**
     * Motifs proposés sur un pouce vers le bas : une liste fermée, pour ne journaliser
     * qu'un type et jamais un texte écrit par l'utilisateur.
     *
     * @var array<int, string>
     */
    public const FEEDBACK_REASONS = ['inexact', 'hors-sujet', 'imprecis'];

    /**
     * Messages affichés : `role`, `text` (brut, renvoyé au modèle), `html` (rendu sûr),
     * `tools` (libellés consultés), `feedback` (`up`/`down`/null).
     *
     * @var array<int, array<string, mixed>>
     */
    public array $messages = [];

    public string $input = '';

    public bool $busy = false;

    #[Locked]
    public ?string $routeName = null;

    /** Encart d'indisponibilité (quota, clé manquante, panne fournisseur). */
    public ?string $notice = null;

    public ?string $noticeReason = null;

    /** @var array<int, string> */
    public array $suggestions = [];

    public bool $dataAccess = false;

    public function mount(?string $routeName = null): void
    {
        $this->routeName = $routeName ?? request()->route()?->getName();
        $this->suggestions = HelpContentRegistry::suggestions($this->routeName);
        $this->dataAccess = (bool) auth()->user()?->ai_data_access;
    }

    public function send(AssistantService $assistant, SafeMarkdown $markdown, ToolBridge $tools): void
    {
        $question = trim($this->input);

        if ($question === '' || $this->busy) {
            return;
        }

        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $this->busy = true;
        $this->notice = null;
        $this->noticeReason = null;
        $this->input = '';

        $conversation = Conversation::fromDisplayed($this->messages);

        $this->messages[] = [
            'role' => 'user',
            'text' => Str::limit($question, self::MAX_INPUT, ''),
            'html' => nl2br(e(Str::limit($question, self::MAX_INPUT, ''))),
            'tools' => [],
            'feedback' => null,
        ];

        $answer = $assistant->answer(
            $user,
            $conversation,
            Str::limit($question, self::MAX_INPUT, ''),
            $this->routeName,
            fn (string $delta) => $this->stream(name: 'assistant-reply', content: e($delta)),
        );

        $this->busy = false;

        if (! $answer->isDelivered()) {
            // La question reste affichée : l'encart explique pourquoi il n'y a pas de réponse.
            $this->notice = $answer->text;
            $this->noticeReason = $answer->reason;

            $this->dispatch('analytics', [
                'event' => 'ai_chat_unavailable',
                'reason' => (string) ($answer->reason ?? $answer->status),
            ]);

            return;
        }

        $this->messages[] = [
            'role' => 'assistant',
            'text' => $answer->text,
            'html' => $markdown->render($answer->text),
            'tools' => $answer->tools === [] ? [] : [$tools->labels($answer->tools)],
            'feedback' => null,
        ];

        $this->dispatch('analytics', [
            'event' => 'ai_chat_question',
            'route' => (string) (HelpContentRegistry::normalize($this->routeName) ?? 'inconnue'),
            'data_access' => $this->dataAccess,
            'refused' => $answer->refused,
            'tools_used' => count($answer->tools),
        ]);
    }

    public function suggest(string $question, AssistantService $assistant, SafeMarkdown $markdown, ToolBridge $tools): void
    {
        $this->input = $question;
        $this->send($assistant, $markdown, $tools);
    }

    public function newConversation(): void
    {
        $this->messages = [];
        $this->input = '';
        $this->notice = null;
        $this->noticeReason = null;
        $this->busy = false;
    }

    /**
     * Pouce sur une réponse. Le motif d'un pouce vers le bas est choisi dans une liste
     * fermée. Le contenu de la conversation n'est conservé que si l'utilisateur a coché
     * « joindre la conversation » ($share), et seulement une fois masqué.
     */
    public function feedback(int $index, bool $up, ?string $reason = null, bool $share = false): void
    {
        if (($this->messages[$index]['role'] ?? null) !== 'assistant') {
            return;
        }

        $reason = in_array($reason, self::FEEDBACK_REASONS, true) ? $reason : null;

        $this->messages[$index]['feedback'] = $up ? 'up' : 'down';
        $this->messages[$index]['reason'] = $reason;

        $shared = false;

        if ($user = auth()->user()) {
            AiUsageLog::recordFeedback($user, $up, $reason);

            if (! $up && $share) {
                // Jusqu'à la réponse notée incluse : la suite n'a pas été jugée.
                AiChatReport::create([
                    'user_id' => $user->id,
                    'reason' => $reason,
                    'route' => HelpContentRegistry::normalize($this->routeName),
                    'model' => AiAccess::for($user)->model('chat'),
                    'transcript' => app(ConversationRedactor::class)
                        ->redact($user, array_slice($this->messages, 0, $index + 1)),
                ]);
                $shared = true;
            }
        }

        $this->messages[$index]['shared'] = $shared;

        $this->dispatch('analytics', array_filter([
            'event' => 'ai_chat_feedback',
            'up' => $up,
            'reason' => $reason,
            'shared' => $shared ?: null,
        ], fn ($value) => $value !== null));
    }

    /**
     * Ce qui serait transmis pour la réponse $index, tel quel, pour l'aperçu proposé
     * avant d'envoyer.
     */
    public function sharePreview(int $index): string
    {
        $user = auth()->user();

        if ($user === null || ($this->messages[$index]['role'] ?? null) !== 'assistant') {
            return '';
        }

        return collect(app(ConversationRedactor::class)->redact($user, array_slice($this->messages, 0, $index + 1)))
            ->map(fn (array $m) => ($m['role'] === 'user' ? 'Vous : ' : 'Assistant : ').$m['text'])
            ->implode("\n\n");
    }

    #[On('ai-assistant-opened')]
    public function opened(): void
    {
        $this->dispatch('analytics', [
            'event' => 'ai_chat_opened',
            'route' => (string) (HelpContentRegistry::normalize($this->routeName) ?? 'inconnue'),
        ]);
    }

    /**
     * Rejoue la dernière question après une erreur du fournisseur.
     */
    public function retry(AssistantService $assistant, SafeMarkdown $markdown, ToolBridge $tools): void
    {
        $last = null;

        foreach (array_reverse($this->messages) as $message) {
            if (($message['role'] ?? null) === 'user') {
                $last = (string) $message['text'];
                break;
            }
        }

        if ($last === null) {
            return;
        }

        // La question est réémise : on retire son affichage précédent pour ne pas la
        // voir deux fois dans le fil.
        array_pop($this->messages);

        $this->input = $last;
        $this->send($assistant, $markdown, $tools);
    }

    public function render()
    {
        return view('livewire.ai-assistant', [
            'keyNotice' => $this->keyNotice(),
            'profileUrl' => \Illuminate\Support\Facades\Route::has('filament.admin.auth.profile')
                ? route('filament.admin.auth.profile')
                : null,
            // Crédits épuisés : le message dit ce qui ne va pas, ce lien dit
            // où le régler. Absent si la clé n'est pas celle de l'utilisateur.
            'creditsUrl' => $this->noticeReason === 'AiInsufficientCredits'
                ? $this->creditsUrl()
                : null,
            'canRetry' => in_array($this->noticeReason, [
                'AiTimeout', 'AiModelUnavailable', 'AiRateLimited', 'AiBadResponse', 'unexpected', 'empty',
            ], true),
        ]);
    }

    /** Page de recharge du fournisseur — seulement si la clé est celle de l'utilisateur. */
    private function creditsUrl(): ?string
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $access = AiAccess::for($user);

        if ($access->source() !== AiAccess::SOURCE_USER) {
            return null;
        }

        try {
            return $access->provider()->creditsUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Encart permanent quand aucune clé n'est configurée : l'onglet reste visible pour
     * expliquer comment activer l'assistant (auto-hébergement).
     */
    private function keyNotice(): ?string
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $access = AiAccess::for($user);

        return $access->available() ? null : $access->unavailableMessage();
    }
}
