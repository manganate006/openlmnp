<?php

namespace App\Support;

use App\Models\Feedback;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Décide si l'on peut proposer à quelqu'un de donner son avis, et sous quelle forme.
 *
 * Toute la règle vit ici plutôt que dans le composant : c'est la partie qui doit être
 * vérifiable sans monter d'interface, et celle qu'on relira dans six mois en se
 * demandant pourquoi la modale s'ouvre (ou pas).
 */
class FeedbackEligibility
{
    /**
     * Cookie posé au premier passage sur la démonstration, portant la date ISO de ce
     * passage. Il existe parce qu'un sandbox de démonstration ne vit que
     * `demo.ttl_hours` (24 h par défaut) : « revenu plusieurs jours après » ne peut pas
     * se lire dans le compte, qui n'existe plus. Il se lit entre deux sandboxes.
     *
     * ⚠️ Volontairement distinct de `olmnp_demo_seen`, qui existe déjà et dont la valeur
     * (`'1'`) est consommée ailleurs — la changer casserait la mesure de conversion
     * démo → inscription.
     */
    public const COOKIE_FIRST_SEEN = 'olmnp_demo_first_seen';

    /**
     * État de l'invitation, pour ne pas insister. Un cookie est le seul support qui
     * survive à la purge d'un compte de démonstration.
     */
    public const COOKIE_STATE = 'olmnp_feedback';

    /**
     * Mise en forme servie quand plus aucune variante n'est en lice — le test a été
     * vidé par erreur. Mieux vaut la modale que rien : une configuration fautive ne
     * doit pas faire disparaître la fonctionnalité en silence.
     */
    public const VARIANT_FALLBACK = 'a';

    private function __construct(
        private readonly ?User $user,
        private readonly array $state,
        private readonly ?Carbon $firstSeen,
    ) {
    }

    public static function for(?User $user, array $cookies = []): self
    {
        return new self(
            $user,
            self::decodeState($cookies[self::COOKIE_STATE] ?? null),
            self::decodeDate($cookies[self::COOKIE_FIRST_SEEN] ?? null),
        );
    }

    /**
     * Peut-on proposer quelque chose à cette personne, maintenant ?
     */
    public function shouldOffer(): bool
    {
        if (! config('feedback.enabled') || ! $this->user) {
            return false;
        }

        if (! in_array($this->audience(), $this->audiences(), true)) {
            return false;
        }

        return ! $this->withinCooldown();
    }

    /**
     * « demo » pour un sandbox de démonstration, « user » sinon.
     *
     * ⚠️ Le compte de démonstration fixe (`demo.email`) porte `is_demo = false` alors que
     * ses identifiants sont publiés — même exception que dans `AiAccess`.
     */
    public function audience(): string
    {
        $isDemo = (bool) ($this->user?->is_demo)
            || ($this->user?->email !== null && $this->user->email === config('demo.email'));

        return $isDemo ? Feedback::AUDIENCE_DEMO : Feedback::AUDIENCE_USER;
    }

    /**
     * « return » si la personne était déjà venue il y a assez longtemps, « session » sinon.
     */
    public function trigger(): string
    {
        $days = (int) config('feedback.return_days');

        $reference = $this->audience() === Feedback::AUDIENCE_DEMO
            ? $this->firstSeen                 // entre deux sandboxes, par cookie
            : $this->user?->created_at;        // un compte normal, lui, dure

        if ($reference && $reference->lte(now()->subDays($days))) {
            return Feedback::TRIGGER_RETURN;
        }

        return Feedback::TRIGGER_SESSION;
    }

    /**
     * Mise en forme à présenter : celle déjà tirée pour cette personne si le cookie en
     * porte une (et qu'elle est toujours en lice), un tirage au sort sinon.
     *
     * Figer le choix est la condition pour que le test mesure quoi que ce soit : voir
     * une modale lundi et un bandeau jeudi ne dit rien de la modale ni du bandeau.
     */
    public function variant(): string
    {
        $inPlay = $this->variants();

        if ($inPlay === []) {
            return self::VARIANT_FALLBACK;
        }

        $assigned = $this->state['v'] ?? null;

        // Un cookie qui porte une variante retirée du test (FEEDBACK_VARIANTS réduit
        // après coup) est retiré au sort plutôt qu'honoré : sinon on continuerait à
        // servir une mise en forme qu'on a justement décidé d'arrêter.
        if (is_string($assigned) && in_array($assigned, $inPlay, true)) {
            return $assigned;
        }

        return $inPlay[random_int(0, count($inPlay) - 1)];
    }

    /**
     * @return list<string>
     */
    public function variants(): array
    {
        return self::splitList(config('feedback.variants'));
    }

    /**
     * Réglages transmis au navigateur, qui compte le temps et les actions.
     */
    public function thresholds(): array
    {
        return [
            'minSeconds' => max(0, (int) config('feedback.min_seconds')),
            'minActions' => max(0, (int) config('feedback.min_actions')),
            'actions' => $this->trackedActions(),
        ];
    }

    /**
     * @return list<string>
     */
    public function trackedActions(): array
    {
        return self::splitList(config('feedback.actions'));
    }

    /**
     * @return list<string>
     */
    public function audiences(): array
    {
        return self::splitList(config('feedback.audiences'));
    }

    /**
     * A-t-on déjà sollicité cette personne récemment ? On regarde les deux mémoires :
     * la base (durable, mais disparaît avec un compte de démonstration) et le cookie
     * (survit à la purge, mais se vide).
     */
    private function withinCooldown(): bool
    {
        $limit = now()->subDays((int) config('feedback.cooldown_days'));

        if ($this->user?->feedback_answered_at || $this->user?->feedback_prompted_at) {
            $last = $this->user->feedback_answered_at ?? $this->user->feedback_prompted_at;

            if ($last->gt($limit)) {
                return true;
            }
        }

        $seenAt = self::decodeDate($this->state['at'] ?? null);

        return $seenAt !== null && $seenAt->gt($limit);
    }

    /**
     * @return list<string>
     */
    private static function splitList(mixed $raw): array
    {
        return collect(Str::of((string) $raw)->explode(','))
            ->map(fn (string $item) => trim($item))
            ->filter()
            ->values()
            ->all();
    }

    private static function decodeState(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function decodeDate(mixed $raw): ?Carbon
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            // Un cookie trafiqué ou périmé ne doit pas casser une page : on retombe
            // simplement sur « on ne sait pas quand cette personne est venue ».
            return null;
        }
    }
}
