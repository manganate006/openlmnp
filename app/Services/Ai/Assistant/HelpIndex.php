<?php

namespace App\Services\Ai\Assistant;

use App\Support\HelpContentRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Récupération documentaire lexicale (TF-IDF simplifié) sur l'index construit par
 * `HelpIndexBuilder`. Le corpus est petit et rédigé en français : une base vectorielle
 * et sa dépendance ne seraient pas justifiées.
 */
final class HelpIndex
{
    /**
     * Mots vides français : trop fréquents pour discriminer une section.
     *
     * @var array<int, string>
     */
    private const STOP_WORDS = [
        'ai', 'aux', 'avec', 'avoir', 'car', 'ce', 'cela', 'ces', 'cet', 'cette', 'comment',
        'dans', 'des', 'donc', 'dois', 'doit', 'du', 'elle', 'elles', 'en', 'est', 'et', 'etre',
        'faire', 'fait', 'faut', 'il', 'ils', 'je', 'la', 'le', 'les', 'leur', 'lui', 'ma', 'mais',
        'me', 'mes', 'moi', 'mon', 'ne', 'nos', 'notre', 'nous', 'on', 'ou', 'par', 'pas', 'peut',
        'peux', 'plus', 'pour', 'pourquoi', 'puis', 'qu', 'quand', 'que', 'quel', 'quelle',
        'quelles', 'quels', 'qui', 'quoi', 'sa', 'sans', 'se', 'ses', 'son', 'sont', 'sur', 'ta',
        'tes', 'toi', 'ton', 'tous', 'tout', 'toute', 'toutes', 'tu', 'un', 'une', 'vos', 'votre',
        'vous', 'y', 'a', 'au', 'comme', 'dont', 'sert', 'servent', 'quelque', 'quelques',
    ];

    /** @var array<int, array<string, mixed>>|null */
    private ?array $sections = null;

    /** @var array<int, array<string, int>>|null */
    private ?array $documents = null;

    public static function path(): string
    {
        return storage_path('app/ai/help-index.json');
    }

    public function isBuilt(): bool
    {
        return File::exists(self::path());
    }

    /**
     * Les sections de l'index. Si le fichier manque (première requête après un
     * déploiement, volume `storage/` fraîchement monté), il est construit à la volée.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sections(): array
    {
        if ($this->sections !== null) {
            return $this->sections;
        }

        if (! $this->isBuilt()) {
            return $this->sections = app(HelpIndexBuilder::class)->build();
        }

        $decoded = json_decode((string) File::get(self::path()), true);

        return $this->sections = is_array($decoded['sections'] ?? null) ? $decoded['sections'] : [];
    }

    /**
     * Les `k` sections les plus proches de la question, plus la vue d'ensemble de la
     * page courante.
     *
     * @return array<int, array<string, mixed>>
     */
    public function retrieve(string $question, ?string $routeName = null, int $k = 3): array
    {
        $sections = $this->sections();

        if ($sections === []) {
            return [];
        }

        $route = HelpContentRegistry::normalize($routeName);
        $terms = $this->tokenize($question);

        $documents = $this->documents ??= array_map(
            fn (array $section): array => array_count_values($this->tokenize($section['text'] ?? '')),
            $sections,
        );

        $scored = [];

        foreach ($sections as $position => $section) {
            $score = 0.0;

            foreach (array_unique($terms) as $term) {
                $frequency = $documents[$position][$term] ?? 0;

                if ($frequency === 0) {
                    continue;
                }

                $score += (1 + log($frequency)) * $this->inverseFrequency($term, $documents);
            }

            if ($score <= 0.0) {
                continue;
            }

            // Une section de la page courante répond plus souvent à la question posée.
            if ($route !== null && ($section['route'] ?? null) === $route) {
                $score *= 1.5;
            }

            $scored[$position] = $score;
        }

        arsort($scored);

        $selected = [];

        foreach (array_slice(array_keys($scored), 0, max($k, 0)) as $position) {
            $selected[$position] = $sections[$position];
        }

        foreach ($this->overview($sections, $route) as $position => $section) {
            $selected[$position] ??= $section;
        }

        return array_values($selected);
    }

    /**
     * La première section de la page courante, toujours jointe au contexte.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    private function overview(array $sections, ?string $route): array
    {
        if ($route === null) {
            return [];
        }

        foreach ($sections as $position => $section) {
            if (($section['route'] ?? null) === $route) {
                return [$position => $section];
            }
        }

        return [];
    }

    /**
     * @param  array<int, array<string, int>>  $documents
     */
    private function inverseFrequency(string $term, array $documents): float
    {
        $matches = 0;

        foreach ($documents as $counts) {
            if (isset($counts[$term])) {
                $matches++;
            }
        }

        if ($matches === 0) {
            return 0.0;
        }

        return log(1 + count($documents) / $matches);
    }

    /**
     * Découpe en mots normalisés : sans accent, sans casse, sans mot vide, avec un
     * pluriel naïf retiré (« charges » et « charge » doivent se rejoindre).
     *
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        $normalized = Str::lower(Str::ascii($text));
        $words = preg_split('/[^a-z0-9]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = [];

        foreach ($words as $word) {
            if (mb_strlen($word) < 3 || in_array($word, self::STOP_WORDS, true)) {
                continue;
            }

            $tokens[] = $this->singular($word);
        }

        return $tokens;
    }

    private function singular(string $word): string
    {
        if (mb_strlen($word) > 4 && (str_ends_with($word, 's') || str_ends_with($word, 'x'))) {
            return mb_substr($word, 0, -1);
        }

        return $word;
    }
}
