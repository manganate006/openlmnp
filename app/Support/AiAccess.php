<?php

namespace App\Support;

use App\Models\User;
use App\Services\Ai\Exceptions\AiMisconfigured;
use App\Services\Ai\Providers\AiProvider;
use App\Services\Ai\Providers\AiProviders;

/**
 * Point UNIQUE de résolution de l'accès aux fonctions d'IA pour un utilisateur.
 *
 * Toute fonctionnalité IA (analyse de documents, assistant) passe par ici :
 * une seule règle de résolution de la clé, un seul endroit à auditer.
 *
 * Ordre de résolution :
 *   1. config('ai.enabled') faux                        -> indisponible (disabled)
 *   2. configuration de fournisseur inexploitable       -> indisponible (misconfigured)
 *   3. compte suspendu (users.suspended_at)             -> indisponible (suspended)
 *   4. compte de démonstration                          -> indisponible (demo)
 *   5. config('ai.api_key') renseignée                  -> clé d'INSTANCE
 *   6. config('ai.allow_user_keys') + $user->ai_api_key -> clé UTILISATEUR
 *   7. sinon                                            -> indisponible (no_key)
 *
 * La clé n'est exposée que par key(), destinée au seul en-tête d'autorisation
 * d'AiClient : elle ne doit jamais atteindre une vue, un log ou une exception.
 */
final class AiAccess
{
    public const SOURCE_INSTANCE = 'instance';
    public const SOURCE_USER = 'user';

    public const REASON_DISABLED = 'disabled';
    public const REASON_MISCONFIGURED = 'misconfigured';
    public const REASON_SUSPENDED = 'suspended';
    public const REASON_DEMO = 'demo';
    public const REASON_NO_KEY = 'no_key';

    private ?string $key = null;
    private ?string $source = null;
    private ?string $reason = null;
    private ?AiProvider $provider = null;
    private ?string $misconfiguredMessage = null;

    private function __construct(private readonly User $user)
    {
        $this->resolve();
    }

    /**
     * ⚠️ Résout TOUJOURS vers le PRINCIPAL si `$user` est un dossier lié (plan des
     * dossiers liés § 2.3 : « Clé IA, consentement, quota → Principal ») : c'est lui
     * qui paie l'instance et dont le consentement/la clé/le quota font foi, quel que
     * soit le dossier actif au moment de l'appel. `App\Support\AiQuota` applique la
     * même résolution — c'est ce qui garde `AiUsageLog.user_id` cohérent avec ce que
     * compte le quota (voir `AiClient::journal()`, qui journalise sous
     * `$this->access->user()->id`).
     */
    public static function for(User $user): self
    {
        return new self(DossierContext::principal($user) ?? $user);
    }

    private function resolve(): void
    {
        if (! config('ai.enabled')) {
            $this->reason = self::REASON_DISABLED;

            return;
        }

        // Fournisseur inconnu ou sans adresse : on ne devine pas, et surtout on
        // ne retombe pas en silence sur OpenRouter — ce serait envoyer les
        // justificatifs ailleurs que là où l'administrateur croit les envoyer.
        // L'erreur est visible dans l'interface plutôt que fatale pour le panel.
        try {
            $this->provider = AiProviders::resolve();
        } catch (AiMisconfigured $e) {
            $this->reason = self::REASON_MISCONFIGURED;
            $this->misconfiguredMessage = $e->getMessage();

            return;
        }

        // Les gardes du panel et du MCP bloquent déjà un compte suspendu ; on
        // le redit ici pour qu'aucun point d'entrée IA futur ne puisse l'oublier.
        if ($this->user->suspended_at !== null) {
            $this->reason = self::REASON_SUSPENDED;

            return;
        }

        if (self::isDemoAccount($this->user)) {
            $this->reason = self::REASON_DEMO;

            return;
        }

        // trim() : une clé collée avec un espace, une tabulation ou un \r de fin
        // (fichier .env en CRLF) rend l'en-tête Authorization invalide — l'appel
        // échoue alors en erreur 500 obscure au lieu d'un « clé invalide » net.
        $instanceKey = trim((string) config('ai.api_key'));

        if (filled($instanceKey)) {
            $this->key = $instanceKey;
            $this->source = self::SOURCE_INSTANCE;

            return;
        }

        $userKey = config('ai.allow_user_keys') ? trim((string) $this->user->ai_api_key) : '';

        if (filled($userKey)) {
            $this->key = $userKey;
            $this->source = self::SOURCE_USER;

            return;
        }

        $this->reason = self::REASON_NO_KEY;
    }

    /**
     * Les fonctions d'IA sont-elles indisponibles parce qu'il s'agit d'un
     * compte de démonstration ?
     *
     * Deux cas, et pas seulement `is_demo` : le compte démo FIXE
     * (config('demo.email')) porte `is_demo = false` pour ne jamais être purgé,
     * alors que ses identifiants sont publiés. C'est justement celui sur lequel
     * un coût non maîtrisé serait le plus probable — même détection par email
     * que App\Support\McpDemo.
     */
    public static function isDemoAccount(User $user): bool
    {
        // ⚠️ DÉLÉGUÉ, et surtout pas redupliqué. Ce prédicat existait ici en double
        // de `User::isDemo()`, avec une nuance dans la garde sur `demo.email`. Deux
        // définitions de « compte de démonstration » qui dérivent chacune de leur
        // côté finissent par ne plus dire la même chose — et le jour où l'une
        // couvre le bac à sable public et l'autre non, c'est l'IA qui s'ouvre à une
        // session partagée dont les identifiants sont publiés.
        return $user->isDemo();
    }

    public function available(): bool
    {
        return $this->key !== null;
    }

    /** Clé d'API résolue — réservée à l'en-tête Authorization d'AiClient. */
    public function key(): ?string
    {
        return $this->key;
    }

    /** 'instance' | 'user' | null */
    public function source(): ?string
    {
        return $this->source;
    }

    /** 'disabled' | 'misconfigured' | 'suspended' | 'demo' | 'no_key' | null */
    public function unavailableReason(): ?string
    {
        return $this->reason;
    }

    public function user(): User
    {
        return $this->user;
    }

    /**
     * Fournisseur configuré sur cette instance.
     *
     * ⚠️ Peut lever AiMisconfigured. Les appelants qui ne sont pas dans un
     * chemin d'appel d'IA (une vue, un écran d'administration) passent par
     * available() d'abord, qui a déjà absorbé le cas.
     */
    public function provider(): AiProvider
    {
        return $this->provider ??= AiProviders::resolve();
    }

    public function baseUrl(): string
    {
        return $this->provider()->baseUrl();
    }

    /**
     * Slug du modèle à utiliser pour une fonction donnée.
     *
     * Quatre étages, du plus précis au plus général :
     *   1. surcharge de l'utilisateur (Mon profil) ;
     *   2. AI_MODEL_* si l'administrateur en a posé un ;
     *   3. modèle par défaut du fournisseur ;
     *   4. vide — l'appelant lèvera AiModelUnavailable.
     *
     * ⚠️ `none` est un refus EXPLICITE, et le seul moyen de désactiver la
     * cascade : depuis que le défaut du fournisseur comble une valeur vide,
     * `AI_MODEL_DOCUMENTS_FALLBACK=` ne suffit plus à la couper.
     *
     * Le slug est ensuite normalisé par le pilote : les identifiants ne sont pas
     * portables (`google/gemini-3.7-flash` chez OpenRouter, `gemini-3.7-flash`
     * chez Google). La correction reste minimale et ne traduit JAMAIS d'un
     * éditeur à l'autre.
     *
     * @param  string  $feature  documents | chat | documents_fallback
     */
    public function model(string $feature): string
    {
        $override = match ($feature) {
            'documents' => $this->user->ai_model_documents,
            'chat' => $this->user->ai_model_chat,
            default => null,
        };

        $slug = trim(filled($override)
            ? (string) $override
            : (string) config("ai.models.{$feature}", ''));

        if (strtolower($slug) === 'none') {
            return '';
        }

        if ($slug === '') {
            $slug = $this->provider()->defaultModel($feature);
        }

        return $slug === '' ? '' : $this->provider()->normalizeModel($slug);
    }

    /**
     * Nom du fournisseur, précédé d'une espace, à coller après « votre clé ».
     *
     * Chez OpenRouter la phrase reste identique au mot près à celle d'avant le
     * multi-fournisseur ; un serveur local, qui n'a pas de nom à donner, la
     * laisse simplement se terminer par « votre clé ».
     */
    private function keyLabelSuffix(): string
    {
        try {
            $label = $this->provider()->keyLabel();
        } catch (\Throwable) {
            return '';
        }

        return $label === '' ? '' : ' ' . $label;
    }

    /**
     * Message d'aide en français correspondant à l'indisponibilité courante.
     */
    public function unavailableMessage(): ?string
    {
        return match ($this->reason) {
            self::REASON_DISABLED => "Les fonctions d'intelligence artificielle ne sont pas activées sur cette instance.",
            self::REASON_MISCONFIGURED => $this->misconfiguredMessage,
            self::REASON_SUSPENDED => "Ce compte est suspendu : les fonctions d'intelligence artificielle sont désactivées.",
            self::REASON_DEMO => "Fonction indisponible dans la démonstration.",
            self::REASON_NO_KEY => config('ai.allow_user_keys')
                ? "Renseignez votre clé{$this->keyLabelSuffix()} dans Mon profil pour activer les fonctions d'intelligence artificielle."
                : "Aucune clé d'intelligence artificielle n'est configurée sur cette instance.",
            default => null,
        };
    }
}
