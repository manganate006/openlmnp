<?php

namespace App\Services\Ai\Assistant;

use App\Support\HelpContentRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Construit l'index documentaire de l'assistant : les vues d'aide contextuelle, la
 * documentation produit de `docs/` et les descriptions des outils MCP, découpées en
 * sections de la taille d'un extrait.
 *
 * L'index est un simple fichier JSON (aucune base vectorielle : le corpus tient en
 * quelques dizaines de milliers de caractères). Il est reconstruit au démarrage du
 * conteneur, donc il suit toujours la documentation embarquée.
 */
final class HelpIndexBuilder
{
    /**
     * Documents produit indexés, avec le niveau de titre qui délimite une section.
     *
     * @var array<string, array{title: string, level: int}>
     */
    private const DOCUMENTS = [
        'FAQ.md' => ['title' => 'Questions fréquentes', 'level' => 3],
        'FONCTIONNALITES.md' => ['title' => 'Fonctionnalités', 'level' => 2],
        'INSTALLATION.md' => ['title' => 'Installation et auto-hébergement', 'level' => 2],
        'DEMO.md' => ['title' => 'Mode démonstration', 'level' => 2],
        'fiscalite-lmnp-airbnb.md' => ['title' => 'Guide fiscal LMNP', 'level' => 2],
    ];

    /**
     * Longueur maximale d'une section : au-delà, un seul extrait mangerait tout le
     * budget de contexte (3 extraits ≈ 1 500 jetons).
     */
    private const MAX_SECTION_CHARS = 1800;

    /**
     * @return array<int, array{id: string, route: string|null, title: string, heading: string, text: string, tokens: int}>
     */
    public function build(): array
    {
        return $this->limitLength(array_merge(
            $this->fromHelpViews(),
            $this->fromDocuments(),
            $this->fromMcpTools(),
        ));
    }

    /**
     * Redécoupe les sections trop longues (liste des outils MCP, chapitres de `docs/`)
     * en morceaux exploitables comme extraits.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    private function limitLength(array $sections): array
    {
        $result = [];

        foreach ($sections as $section) {
            if (mb_strlen($section['text']) <= self::MAX_SECTION_CHARS) {
                $result[] = $section;

                continue;
            }

            foreach ($this->chunk($section['text']) as $index => $text) {
                $result[] = $this->section(
                    id: $section['id'].'/'.$index,
                    route: $section['route'],
                    title: $section['title'],
                    heading: $section['heading'],
                    text: $text,
                );
            }
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    private function chunk(string $text): array
    {
        $chunks = [];
        $current = '';

        foreach (explode("\n", $text) as $line) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($line) > self::MAX_SECTION_CHARS) {
                $chunks[] = trim($current);
                $current = '';
            }

            $current .= $line."\n";
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return $chunks;
    }

    /**
     * Écrit l'index sur le disque et renvoie le nombre de sections.
     */
    public function write(): int
    {
        $sections = $this->build();

        File::ensureDirectoryExists(dirname(HelpIndex::path()));
        File::put(HelpIndex::path(), json_encode(
            ['sections' => $sections],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return count($sections);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fromHelpViews(): array
    {
        $sections = [];

        $views = [];
        foreach (HelpContentRegistry::all() as $routeName => $entry) {
            $views[] = [$entry['view'], $entry['title'], $routeName];
        }

        $seen = [];

        foreach ($views as [$view, $title, $routeName]) {
            // Plusieurs routes peuvent partager une vue : on indexe la vue une fois,
            // rattachée à la première route rencontrée.
            if (isset($seen[$view])) {
                continue;
            }
            $seen[$view] = true;

            try {
                $html = view($view)->render();
            } catch (Throwable) {
                continue;
            }

            foreach ($this->splitHtmlByHeading($html) as $index => $chunk) {
                $sections[] = $this->section(
                    id: $view.'#'.$index,
                    route: $routeName,
                    title: $title,
                    heading: $chunk['heading'],
                    text: $chunk['text'],
                );
            }
        }

        return $sections;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fromDocuments(): array
    {
        $sections = [];

        foreach (self::DOCUMENTS as $file => $meta) {
            $path = base_path('docs/'.$file);

            if (! File::exists($path)) {
                continue;
            }

            foreach ($this->splitMarkdownByHeading(File::get($path), $meta['level']) as $index => $chunk) {
                $sections[] = $this->section(
                    id: 'docs/'.$file.'#'.$index,
                    route: null,
                    title: $meta['title'],
                    heading: $chunk['heading'],
                    text: $chunk['text'],
                );
            }
        }

        return $sections;
    }

    /**
     * Les descriptions des outils MCP répondent aux questions « que peut faire
     * l'assistant / Claude Desktop sur mes données ? ».
     *
     * @return array<int, array<string, mixed>>
     */
    private function fromMcpTools(): array
    {
        $directory = app_path('Mcp/Tools');

        if (! File::isDirectory($directory)) {
            return [];
        }

        $lines = [];

        foreach (File::files($directory) as $file) {
            $class = 'App\\Mcp\\Tools\\'.$file->getFilenameWithoutExtension();

            if (! class_exists($class)) {
                continue;
            }

            try {
                $tool = app($class);
                $lines[] = '- '.$tool->name().' : '.$tool->description();
            } catch (Throwable) {
                continue;
            }
        }

        if ($lines === []) {
            return [];
        }

        sort($lines);

        return [$this->section(
            id: 'mcp/tools',
            route: null,
            title: 'API MCP pour assistants IA',
            heading: 'Outils MCP disponibles',
            text: "OpenLMNP expose un serveur MCP (Model Context Protocol) que l'on peut brancher sur "
                ."Claude Desktop ou tout autre client compatible. Outils exposés :\n".implode("\n", $lines),
        )];
    }

    /**
     * @return array<string, mixed>
     */
    private function section(string $id, ?string $route, string $title, string $heading, string $text): array
    {
        return [
            'id' => $id,
            'route' => $route,
            'title' => $title,
            'heading' => $heading,
            'text' => $text,
            'tokens' => (int) ceil(mb_strlen($text) / 4),
        ];
    }

    /**
     * Découpe du HTML rendu sur les titres `h2`/`h3` et le convertit en texte.
     *
     * @return array<int, array{heading: string, text: string}>
     */
    private function splitHtmlByHeading(string $html): array
    {
        $parts = preg_split('/<h[23][^>]*>(.*?)<\/h[23]>/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return [];
        }

        $chunks = [];
        $intro = $this->htmlToText(array_shift($parts) ?? '');

        if ($intro !== '') {
            $chunks[] = ['heading' => 'Vue d\'ensemble', 'text' => $intro];
        }

        for ($i = 0; $i < count($parts); $i += 2) {
            $heading = $this->htmlToText($parts[$i]);
            $body = $this->htmlToText($parts[$i + 1] ?? '');

            if ($heading === '' && $body === '') {
                continue;
            }

            $chunks[] = ['heading' => $heading, 'text' => trim($heading."\n".$body)];
        }

        return $chunks;
    }

    /**
     * @return array<int, array{heading: string, text: string}>
     */
    private function splitMarkdownByHeading(string $markdown, int $level): array
    {
        $marker = str_repeat('#', $level);
        $pattern = '/^'.preg_quote($marker, '/').' +(.*)$/m';

        $parts = preg_split($pattern, $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return [];
        }

        array_shift($parts); // l'en-tête du document (titre, sommaire) n'apporte rien

        $chunks = [];

        for ($i = 0; $i < count($parts); $i += 2) {
            $heading = trim($parts[$i]);
            $body = $this->markdownToText($parts[$i + 1] ?? '');

            if ($body === '') {
                continue;
            }

            $chunks[] = ['heading' => $heading, 'text' => trim($heading."\n".$body)];
        }

        return $chunks;
    }

    private function htmlToText(string $html): string
    {
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = preg_replace('/<li\b[^>]*>/i', "\n- ", $text) ?? $text;
        $text = preg_replace('/<\/(p|div|li|ul|ol|h[1-6])>/i', "\n", $text) ?? $text;
        // Sans cela, « <span>1</span>Ajouter » donnerait « 1Ajouter ».
        $text = preg_replace('/<\/(span|strong|em|b|i|code)>/i', '$0 ', $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->squeeze($text);
    }

    private function markdownToText(string $markdown): string
    {
        // Les blocs de code et les tableaux restent lisibles en texte brut ; on retire
        // seulement le balisage qui parasiterait la recherche lexicale.
        $text = preg_replace('/^#{1,6} +/m', '', $markdown) ?? $markdown;
        $text = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text) ?? $text;
        $text = str_replace(['**', '`', '> '], '', $text);

        return $this->squeeze($text);
    }

    private function squeeze(string $text): string
    {
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Utilisé par les tests et le prompt : le libellé lisible d'une section.
     */
    public static function label(array $section): string
    {
        return Str::of($section['title'])
            ->when(filled($section['heading']), fn ($title) => $title->append(' › ', $section['heading']))
            ->toString();
    }
}
