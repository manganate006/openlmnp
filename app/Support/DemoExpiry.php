<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Décide s'il faut prévenir quelqu'un que son bac à sable va s'effacer, et sous quelle forme.
 *
 * Toute la règle vit ici plutôt que dans le composant Livewire, comme pour
 * {@see FeedbackEligibility} : c'est la partie qu'on doit pouvoir vérifier sans monter
 * d'interface, et celle qu'on relira dans six mois en se demandant pourquoi la modale
 * s'ouvre (ou pas).
 *
 * Le parti pris central : les paliers sont exprimés en heures RESTANTES, jamais écoulées.
 * Un seuil « il reste 6 h » a le même sens pour un sandbox de 24 h et pour un sandbox
 * prolongé à 7 jours, alors qu'un seuil « 18 h écoulées » n'en aurait aucun pour le second.
 */
class DemoExpiry
{
    /** Formes d'affichage acceptées dans `demo.reminders`. */
    public const FORM_BANNER = 'banner';

    public const FORM_MODAL = 'modal';

    private function __construct(
        private readonly ?User $user,
        private readonly Carbon $now,
    ) {
    }

    public static function for(?User $user, ?Carbon $now = null): self
    {
        return new self($user, $now ?? Carbon::now());
    }

    /**
     * Y a-t-il quelque chose à afficher pour cette personne ?
     *
     * Le compte à rebours permanent n'a de sens que sur un bac à sable qui expire vraiment.
     */
    public function applies(): bool
    {
        return $this->user !== null
            && $this->user->is_demo
            && filled($this->user->demo_expires_at)
            && $this->remainingSeconds() > 0;
    }

    public function expiresAt(): ?Carbon
    {
        return $this->user?->demo_expires_at;
    }

    /**
     * ⚠️ La garde sur la date nulle est PORTEUSE, pas décorative.
     *
     * Sur une date nulle, `$this->now->diffInSeconds(null)` ne rend pas 0 : Carbon remplace
     * le null par l'heure courante, et l'écart vaut donc `maintenant - $this->now`. Il est
     * POSITIF dès que `$this->now` est dans le passé — précisément ce que fera la commande
     * planifiée, qui balaie des comptes avec un instant de référence figé. Sans cette garde,
     * un compte à l'état incohérent serait vu comme « il lui reste du temps » et la relance
     * partirait sur un signal inconnu : un e-mail qui AFFIRME exige un signal CONNU.
     */
    public function remainingSeconds(): int
    {
        if ($this->user === null || blank($this->user->demo_expires_at)) {
            return 0;
        }

        return max(0, (int) $this->now->diffInSeconds($this->user->demo_expires_at, false));
    }

    /**
     * La prolongation n'est offerte qu'une fois : c'est ce qui rend la relance du dernier
     * jour crédible, et ce qui empêche d'entretenir indéfiniment un sandbox gratuit.
     */
    public function canExtend(): bool
    {
        return $this->applies() && blank($this->user->demo_extended_at);
    }

    /**
     * L'offre commerciale n'existe que si une cible est configurée. Sur une instance
     * auto-hébergée, `demo.links.pro` est vide : il ne reste que la prolongation.
     */
    public function hasOffer(): bool
    {
        return filled(config('demo.links.pro'));
    }

    /**
     * Paliers configurés, normalisés et triés du plus lointain au plus proche.
     *
     * @return array<int, string> [heures restantes => forme]
     */
    public function thresholds(): array
    {
        $out = [];

        foreach (explode(',', (string) config('demo.reminders')) as $chunk) {
            $chunk = trim($chunk);

            if ($chunk === '' || ! Str::contains($chunk, ':')) {
                continue;
            }

            [$hours, $form] = explode(':', $chunk, 2);
            $hours = (int) trim($hours);
            $form = strtolower(trim($form));

            // Une forme inconnue est ignorée plutôt que traitée par défaut : un `modale`
            // français ou un `popup` inventé ne doit pas se transformer en bandeau muet.
            if ($hours <= 0 || ! in_array($form, [self::FORM_BANNER, self::FORM_MODAL], true)) {
                continue;
            }

            $out[$hours] = $form;
        }

        krsort($out);

        return $out;
    }

    /**
     * Durée de vie totale de CE bac à sable, en heures.
     */
    public function lifetimeHours(): int
    {
        return filled($this->user?->demo_extended_at)
            ? (int) config('demo.extended_ttl_days') * 24
            : (int) config('demo.ttl_hours');
    }

    /**
     * Paliers réellement applicables à ce bac à sable.
     *
     * Un palier n'a de sens que s'il est STRICTEMENT sous la durée de vie du sandbox.
     * Sans ce filtre, le palier « il reste 24 h » se déclencherait dès la première seconde
     * d'un sandbox de 24 h — qui n'a jamais 24 h pleines devant lui, mais 23 h 59 — et la
     * modale s'ouvrirait à l'accueil, exactement ce qu'on cherche à éviter. Le même filtre
     * écarte 96 h, et laisse les deux s'appliquer sur un sandbox prolongé à 7 jours.
     *
     * @return array<int, string>
     */
    public function applicableThresholds(): array
    {
        $lifetime = $this->lifetimeHours();

        return array_filter(
            $this->thresholds(),
            fn (int $hours) => $hours < $lifetime,
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Paliers déjà servis, lus en base. Le navigateur n'en est pas la source de vérité :
     * vider son stockage local ne doit pas rejouer les relances.
     *
     * @return array<int, int>
     */
    public function seen(): array
    {
        return array_map('intval', $this->user?->demo_reminders_seen ?? []);
    }

    /**
     * Le palier à servir maintenant, ou null.
     *
     * On retient le plus BAS des paliers franchis et non encore servis : quelqu'un qui
     * revient après une longue absence a pu en franchir plusieurs d'un coup, et c'est
     * l'urgence réelle qu'il faut lui montrer, pas celle d'il y a six heures.
     *
     * @return array{hours: int, form: string}|null
     */
    public function due(): ?array
    {
        if (! $this->applies()) {
            return null;
        }

        $remainingHours = $this->remainingSeconds() / 3600;
        $seen = $this->seen();
        $due = null;

        // Un palier déjà servi rend caducs tous ceux qui le précèdent : après avoir montré
        // « il reste 6 h », revenir à « il reste 12 h » serait faux, et rassurant à tort.
        $mostUrgentSeen = $seen === [] ? PHP_INT_MAX : min($seen);

        foreach ($this->applicableThresholds() as $hours => $form) {
            if ($remainingHours <= $hours && $hours < $mostUrgentSeen) {
                $due = ['hours' => $hours, 'form' => $form];
            }
        }

        return $due;
    }

    /**
     * Le palier réclamé par le navigateur est-il réellement franchi ?
     *
     * La minuterie tourne côté client, mais c'est le serveur qui décide : sans cette
     * revalidation, n'importe qui pourrait faire enregistrer un palier qu'il n'a pas
     * atteint et se soustraire aux relances suivantes.
     */
    public function isReached(int $hours): bool
    {
        return $this->applies()
            && array_key_exists($hours, $this->applicableThresholds())
            && ($this->remainingSeconds() / 3600) <= $hours;
    }

    /**
     * Enregistre un palier servi. Idempotent.
     */
    public function markSeen(int $hours): void
    {
        if ($this->user === null || ! $this->isReached($hours)) {
            return;
        }

        $seen = $this->seen();
        $remainingHours = $this->remainingSeconds() / 3600;

        // Deux familles de paliers deviennent caduques en servant celui-ci :
        //
        //  - tous les MOINS urgents déjà franchis — les laisser en attente ferait
        //    réapparaître une relance plus molle juste après en avoir servi une grave ;
        //  - tous les PLUS urgents situés à moins de `min_gap_hours` en dessous — sinon,
        //    sur un sandbox prolongé, la modale de l'offre à 24 h serait suivie une heure
        //    plus tard du bandeau à 23 h, qui n'existe que pour le sandbox de 24 h.
        $gap = max(0, (int) config('demo.min_gap_hours'));

        foreach (array_keys($this->applicableThresholds()) as $candidate) {
            $obsolete = ($candidate >= $hours && $remainingHours <= $candidate)
                || ($candidate < $hours && $candidate >= $hours - $gap);

            if ($obsolete) {
                $seen[] = $candidate;
            }
        }

        $seen = array_values(array_unique($seen));
        sort($seen);

        if ($seen === $this->seen()) {
            return;
        }

        $this->user->forceFill(['demo_reminders_seen' => $seen])->save();
    }

    /**
     * Remet les paliers à zéro : appelé à la prolongation, parce que les heures restantes
     * repartent de 7 jours et que les paliers hauts (96 h) n'ont jamais été servis.
     */
    public static function resetSeen(User $user): void
    {
        $user->forceFill(['demo_reminders_seen' => []])->save();
    }
}
