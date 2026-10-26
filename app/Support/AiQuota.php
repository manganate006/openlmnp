<?php

namespace App\Support;

use App\Models\AiUsageLog;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Plafonds d'usage des fonctions d'IA, par utilisateur.
 *
 * Compte les appels RÉUSSIS (status = ok) du journal sur la fenêtre courante :
 * mois civil pour les documents, jour civil pour l'assistant. Les fenêtres sont
 * calculées dans le fuseau horaire de l'utilisateur (users.timezone), pas celui
 * du serveur.
 *
 * Plafond 0 = illimité. Un utilisateur qui fournit SA clé n'est pas plafonné
 * (c'est sa clé, son coût), sauf si l'administrateur force le contraire avec
 * AI_QUOTA_APPLIES_TO_USER_KEYS=true. Un administrateur n'est PAS exempté :
 * pas de surprise de coût.
 *
 * ⚠️ AiClient n'applique pas les quotas : chaque fonctionnalité doit appeler
 * AiQuota::check() avant de lancer un appel.
 */
final class AiQuota
{
    public const FEATURE_DOCUMENTS = 'documents';
    public const FEATURE_CHAT = 'chat';

    private function __construct(
        private readonly User $user,
        private readonly string $feature,
    ) {}

    /**
     * ⚠️ Même résolution que `AiAccess::for()` : un dossier lié est plafonné sur le
     * quota de son PRINCIPAL (qui paie l'instance), jamais sur le sien propre.
     */
    public static function for(User $user, string $feature): self
    {
        return new self(DossierContext::principal($user) ?? $user, $feature);
    }

    /** Raccourci : l'utilisateur peut-il encore utiliser cette fonction ? */
    public static function check(User $user, string $feature): bool
    {
        return self::for($user, $feature)->allowed();
    }

    public function allowed(): bool
    {
        if ($this->budgetReached()) {
            return false;
        }

        $limit = $this->limit();

        if ($limit <= 0) {
            return true;
        }

        return $this->used() < $limit;
    }

    /** Plafond effectif (0 = illimité, y compris quand la clé est celle de l'utilisateur). */
    public function limit(): int
    {
        if ($this->isExemptUserKey()) {
            return 0;
        }

        return match ($this->feature) {
            self::FEATURE_DOCUMENTS => (int) config('ai.docs_monthly_quota', 0),
            self::FEATURE_CHAT => (int) config('ai.chat_daily_quota', 0),
            // chat_tool / key_check ne consomment aucun quota.
            default => 0,
        };
    }

    /** Appels réussis déjà consommés sur la fenêtre courante. */
    public function used(): int
    {
        return AiUsageLog::withoutGlobalScopes()
            ->where('user_id', $this->user->id)
            ->where('feature', $this->feature)
            ->where('status', 'ok')
            // `created_at` est stocké dans le fuseau applicatif (UTC) : la borne,
            // calculée dans celui de l'utilisateur, doit y être convertie — la
            // liaison SQL ne convertit pas, elle formate la date telle quelle.
            ->where('created_at', '>=', $this->windowStart()->setTimezone(config('app.timezone', 'UTC')))
            ->count();
    }

    /** Appels restants, ou null si illimité. */
    public function remaining(): ?int
    {
        $limit = $this->limit();

        if ($limit <= 0) {
            return null;
        }

        return max(0, $limit - $this->used());
    }

    /**
     * Début de la fenêtre courante, dans le fuseau de l'utilisateur.
     */
    public function windowStart(): CarbonImmutable
    {
        $timezone = $this->user->timezone ?: config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($timezone);

        return $this->feature === self::FEATURE_DOCUMENTS
            ? $now->startOfMonth()
            : $now->startOfDay();
    }

    /**
     * Message d'épuisement, en français, identique partout (CDC § 3.5).
     */
    public function exhaustedMessage(): string
    {
        if ($this->budgetReached()) {
            return "Le budget d'intelligence artificielle de cette instance est atteint pour aujourd'hui : réessayez demain.";
        }

        $limit = $this->limit();

        if ($this->feature === self::FEATURE_DOCUMENTS) {
            return "Quota mensuel atteint ({$this->used()}/{$limit}). Réinitialisation le 1ᵉʳ du mois.";
        }

        return "Vous avez atteint la limite de {$limit} messages aujourd'hui.";
    }

    /**
     * La dépense du jour de l'instance, sur SA clé, a-t-elle atteint AI_DAILY_BUDGET_USD ?
     * Tous les comptes confondus : c'est la facture qui est bornée, pas un compte.
     */
    public function budgetReached(): bool
    {
        $budget = (float) config('ai.daily_budget_usd', 0);

        if ($budget <= 0 || AiAccess::for($this->user)->source() !== AiAccess::SOURCE_INSTANCE) {
            return false;
        }

        $spent = (float) AiUsageLog::withoutGlobalScopes()
            ->where('key_source', AiAccess::SOURCE_INSTANCE)
            ->where('created_at', '>=', now('UTC')->startOfDay())
            ->sum('cost_usd');

        return $spent >= $budget;
    }

    /**
     * L'utilisateur paie avec sa propre clé et n'est donc pas plafonné.
     */
    private function isExemptUserKey(): bool
    {
        if (config('ai.quota_applies_to_user_keys')) {
            return false;
        }

        return AiAccess::for($this->user)->source() === AiAccess::SOURCE_USER;
    }
}
