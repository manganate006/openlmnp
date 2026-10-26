<?php

namespace App\Services\Ai\Assistant;

/**
 * Historique d'une conversation, réduit à ce qui repart vers le modèle.
 *
 * Volontairement sérialisable : il vit dans l'état du composant Livewire et n'est
 * jamais écrit en base — la conversation disparaît au rechargement de la page.
 */
final class Conversation
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function __construct(public readonly array $messages = []) {}

    /**
     * @param  array<int, array<string, mixed>>  $messages  messages d'affichage du composant
     */
    public static function fromDisplayed(array $messages): self
    {
        $kept = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? null;
            $text = trim((string) ($message['text'] ?? ''));

            if (! in_array($role, ['user', 'assistant'], true) || $text === '') {
                continue;
            }

            $kept[] = ['role' => $role, 'content' => $text];
        }

        return new self($kept);
    }

    /**
     * Nombre de messages échangés (une question et sa réponse comptent pour deux).
     */
    public function turns(): int
    {
        return count($this->messages);
    }

    /**
     * Ne garde que les derniers messages, pour borner le coût et rester précis.
     */
    public function truncated(int $max): self
    {
        if ($max <= 0 || $this->turns() <= $max) {
            return $this;
        }

        return new self(array_slice($this->messages, -$max));
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function toArray(): array
    {
        return $this->messages;
    }
}
