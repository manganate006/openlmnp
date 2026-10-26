<?php

namespace App\Services\Ai\Assistant;

use Illuminate\Support\Str;

/**
 * Deuxième barrière du périmètre (la première étant le prompt système) : une réponse qui
 * ressemble à un conseil fiscal ou juridique individualisé est remplacée par le refus
 * standard, quelle que soit la formulation du modèle.
 *
 * Une phrase reprise mot pour mot de la documentation fournie n'est pas bloquée : le
 * corpus emploie légitimement « vous pouvez déduire… » en décrivant ce que fait le
 * logiciel.
 */
final class OutputGuard
{
    /**
     * Motifs de conseil individualisé. Comparés sur un texte sans accent ni casse.
     *
     * @var array<int, string>
     */
    private const RISKY_PATTERNS = [
        '/\bje vous (conseille|recommande|suggere)\b/',
        '/\bvous (devriez|feriez mieux de|auriez interet a)\b/',
        '/\bopte[rz]? pour le (reel|micro)\b/',
        '/\bvous (devez|pouvez) (opter|passer|basculer) (pour|au|en)\b/',
        '/\bdans votre (cas|situation)\b/',
        '/\bvous avez (le )?droit (de|a)\b/',
        '/\b(la loi|le code general des impots|le cgi) (prevoit|dispose|impose|autorise)\b/',
        '/\barticle [ldr]?\.? ?\d+[\w\-]* du (cgi|code general des impots)\b/',
        '/\bbofip\b/',
        '/\bjurisprudence\b/',
        '/\bvous risquez\b/',
        '/\bredressement fiscal\b/',
        '/\bje vous invite a (deduire|opter|declarer)\b/',
    ];

    /**
     * Affirmations fiscales **fausses**, bloquées même si un extrait de documentation les
     * contient (une aide périmée ne doit pas les rendre acceptables). Comparées sur la
     * réponse entière, la mention de l'indivision pouvant précéder la phrase fautive.
     *
     * Piège P13 (`_admin/docs/pieges-fiscaux.md`, réécrit le 2026-10-14, simplifié côté app le
     * 2026-10-15) : une indivision n'est exclue du micro-BIC que si elle forme une société de
     * fait (CE, 14/11/2025, n° 495516) — une question de fait que le logiciel ne tranche plus
     * (voir `App\Support\Indivision`). « Réel de plein droit » ou « d'office au réel » reste
     * faux sans condition, indépendamment de ce que l'app modélise ou non.
     *
     * @var array<int, array{0: string, 1: string}>  [contexte requis, affirmation fausse]
     */
    private const FALSE_CLAIMS = [
        ['/\bindivis/', '/\b(reel|regime reel) de plein droit\b/'],
        ['/\bindivis/', '/\bd office\b[^.]{0,20}\bau (regime )?reel\b/'],
    ];

    /**
     * Marqueurs de **doctrine fiscale** dans la question. Volontairement étroits et sans
     * ambiguïté : une question d'usage n'emploie jamais ces termes, alors qu'ils
     * signalent à coup sûr une demande d'interprétation de la loi.
     *
     * Ce filtre d'entrée a été ajouté après le jeu d'évaluation du 2026-08-21 : « Le
     * BOFiP autorise-t-il l'amortissement du terrain ? » recevait une réponse au lieu
     * d'un refus, parce que la documentation contient effectivement le fait (le terrain
     * n'est pas amortissable) — le modèle répondait donc de bonne foi à une question qui
     * porte pourtant sur la doctrine.
     *
     * @var array<int, string>
     */
    private const LEGAL_QUESTION_PATTERNS = [
        '/\bbofip\b/',
        '/\bcgi\b/',
        '/\bcode general des impots\b/',
        '/\bjurisprudence\b/',
        '/\bcontrole fiscal\b/',
        '/\bredressement\b/',
        '/\bque risqu\w+\b/',
        '/\bla loi (autorise|interdit|permet|prevoit|dit|impose)\b/',
        '/\b(est-ce|c est) legal\b/',
        '/\bl administration fiscale (accepte|refuse|autorise|exige)\b/',
    ];

    public function __construct(private readonly RouteLinks $links) {}

    /**
     * Refus opposé **avant** tout appel au modèle quand la question porte explicitement
     * sur la doctrine fiscale. Renvoie le texte du refus, ou null si la question peut
     * suivre son cours.
     */
    public function inspectQuestion(string $question): ?string
    {
        $normalized = $this->normalize($question);

        foreach (self::LEGAL_QUESTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return $this->refusal();
            }
        }

        return null;
    }

    /**
     * Renvoie le texte de refus si la réponse doit être remplacée, sinon null.
     *
     * @param  array<int, array<string, mixed>>  $retrieved  extraits fournis au modèle
     */
    public function inspect(string $answer, array $retrieved = []): ?string
    {
        $documented = $this->normalize(
            implode(' ', array_map(fn (array $section): string => (string) ($section['text'] ?? ''), $retrieved))
        );

        $whole = $this->normalize($answer);

        foreach (self::FALSE_CLAIMS as [$context, $claim]) {
            if (preg_match($context, $whole) === 1 && preg_match($claim, $whole) === 1) {
                return $this->refusal();
            }
        }

        foreach ($this->sentences($answer) as $sentence) {
            $normalized = $this->normalize($sentence);

            if (! $this->isRisky($normalized)) {
                continue;
            }

            // Toléré si la phrase provient de la documentation citée.
            if ($documented !== '' && str_contains($documented, $this->fingerprint($normalized))) {
                continue;
            }

            return $this->refusal();
        }

        return null;
    }

    /**
     * Le refus standard « conseil fiscal », avec sa porte de sortie.
     */
    public function refusal(): string
    {
        $simulator = $this->links->urlFor('Simulateur');

        $lien = $simulator !== null
            ? "le [simulateur micro-BIC vs réel]({$simulator}) intégré"
            : 'le simulateur micro-BIC vs réel intégré';

        return "Je ne peux pas vous conseiller sur votre situation fiscale. Pour comparer les régimes, "
            ."utilisez {$lien} ; pour une décision engageante, rapprochez-vous d'un expert-comptable.";
    }

    private function isRisky(string $normalized): bool
    {
        foreach (self::RISKY_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function sentences(string $answer): array
    {
        $parts = preg_split('/(?<=[.!?:])\s+|\n+/', $answer, -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [$answer] : $parts;
    }

    /**
     * Empreinte courte d'une phrase, pour la retrouver dans la documentation citée.
     */
    private function fingerprint(string $normalized): string
    {
        return mb_substr(trim($normalized), 0, 40);
    }

    private function normalize(string $text): string
    {
        $text = Str::lower(Str::ascii(strip_tags($text)));
        // L'apostrophe devient une espace : les motifs s'écrivent « l administration »,
        // « c est legal », « d office ». Sans cela, ils ne pouvaient jamais correspondre.
        $text = preg_replace(['/[\x27`]/', '/\s+/'], ' ', $text) ?? $text;

        return trim($text);
    }
}
