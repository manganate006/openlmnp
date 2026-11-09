<?php

namespace App\Services\Ai\Assistant;

/**
 * Résultat d'une question posée à l'assistant : soit une réponse à afficher, soit un
 * état d'indisponibilité rendu tel quel par le composant.
 */
final class AssistantAnswer
{
    public const STATUS_OK = 'ok';

    public const STATUS_QUOTA = 'quota';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUS_LIMIT = 'limit';

    public const STATUS_ERROR = 'error';

    /**
     * @param  array<int, string>  $tools  noms des outils consultés, dans l'ordre d'appel
     */
    private function __construct(
        public readonly string $status,
        public readonly string $text,
        public readonly array $tools = [],
        public readonly bool $refused = false,
        public readonly ?string $reason = null,
    ) {}

    /**
     * @param  array<int, string>  $tools
     */
    public static function ok(string $text, array $tools = [], bool $refused = false): self
    {
        return new self(self::STATUS_OK, $text, $tools, $refused);
    }

    public static function unavailable(string $text, ?string $reason = null): self
    {
        return new self(self::STATUS_UNAVAILABLE, $text, reason: $reason);
    }

    public static function quota(string $text): self
    {
        return new self(self::STATUS_QUOTA, $text, reason: 'quota');
    }

    public static function limit(string $text): self
    {
        return new self(self::STATUS_LIMIT, $text, reason: 'max_turns');
    }

    public static function error(string $text, ?string $reason = null): self
    {
        return new self(self::STATUS_ERROR, $text, reason: $reason);
    }

    /**
     * Vrai quand la réponse doit être ajoutée à la conversation.
     */
    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_OK;
    }
}
