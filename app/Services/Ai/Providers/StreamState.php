<?php

namespace App\Services\Ai\Providers;

/**
 * État d'accumulation d'une réponse diffusée.
 *
 * Le transport SSE (découpage en lignes, commentaires, fin de flux) appartient à
 * AiClient ; le DÉCODAGE d'un événement appartient au pilote, qui vient remplir
 * cet objet. La recomposition des appels d'outils est mise en commun ici : elle
 * est identique partout, seuls diffèrent les noms des champs qui l'alimentent.
 */
final class StreamState
{
    public string $text = '';

    /** @var array<int, array<mixed>> */
    public array $toolCalls = [];

    /** @var array<mixed> */
    public array $usage = [];

    /** Coût rendu par le fournisseur, brut ; null s'il n'en rend pas. */
    public mixed $reportedCost = null;

    public ?string $finishReason = null;

    public ?string $requestId = null;

    public bool $done = false;

    public function __construct(
        public string $model,
        private readonly mixed $onDelta = null,
    ) {}

    /** Ajoute un fragment de texte et le pousse au navigateur. */
    public function appendText(string $delta): void
    {
        if ($delta === '') {
            return;
        }

        $this->text .= $delta;

        if (is_callable($this->onDelta)) {
            ($this->onDelta)($delta);
        }
    }

    /**
     * Recompose les appels d'outils diffusés fragment par fragment : les
     * arguments arrivent en morceaux, indexés par `index`.
     *
     * @param  array<mixed>  $deltas
     */
    public function mergeToolCallDeltas(array $deltas): void
    {
        foreach ($deltas as $delta) {
            $index = (int) ($delta['index'] ?? 0);

            $this->toolCalls[$index] ??= [
                'id' => '',
                'type' => 'function',
                'function' => ['name' => '', 'arguments' => ''],
            ];

            if (isset($delta['id'])) {
                $this->toolCalls[$index]['id'] = (string) $delta['id'];
            }

            if (isset($delta['function']['name'])) {
                $this->toolCalls[$index]['function']['name'] = (string) $delta['function']['name'];
            }

            if (isset($delta['function']['arguments'])) {
                $this->toolCalls[$index]['function']['arguments'] .= (string) $delta['function']['arguments'];
            }
        }
    }

    /** Appels d'outils remis dans l'ordre, prêts à être exécutés. */
    public function orderedToolCalls(): array
    {
        $toolCalls = $this->toolCalls;
        ksort($toolCalls);

        return array_values($toolCalls);
    }
}
