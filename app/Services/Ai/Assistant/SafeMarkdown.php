<?php

namespace App\Services\Ai\Assistant;

use Illuminate\Support\Str;

/**
 * Rendu Markdown du texte produit par le modèle, côté serveur, avec deux garde-fous :
 * aucun HTML brut n'est conservé, et seuls les liens internes de la table `RouteLinks`
 * restent cliquables. Un lien inventé (ou injecté via un extrait) devient du texte.
 */
final class SafeMarkdown
{
    /**
     * Balises conservées après rendu. Tout le reste est retiré (le texte, lui, reste).
     *
     * @var array<int, string>
     */
    private const ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'ul', 'ol', 'li', 'code', 'a', 'blockquote'];

    public function __construct(private readonly RouteLinks $links) {}

    public function render(string $markdown): string
    {
        // Le texte est échappé AVANT le rendu : sans cela, CommonMark traite un
        // « <script>… » comme un bloc HTML et supprime la ligne entière — texte
        // légitime compris. Échappé, le balisage devient du texte visible (les
        // entités sont redécodées puis ré-échappées par CommonMark) et rien ne
        // s'exécute.
        $html = Str::markdown(e($markdown), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $html = $this->keepInternalLinks($html);

        return strip_tags($html, self::ALLOWED_TAGS);
    }

    /**
     * Remplace tout `<a href="…">` dont l'adresse n'est pas dans la table des pages par
     * son libellé. Les liens autorisés sont réécrits proprement (pas d'attribut hérité
     * du modèle : `target`, `onclick`…).
     */
    private function keepInternalLinks(string $html): string
    {
        return preg_replace_callback(
            '/<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is',
            function (array $matches): string {
                $url = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = $matches[3];
                $internal = $this->links->internalUrl($url);

                if ($internal === null) {
                    return $label;
                }

                return '<a href="'.e($internal).'">'.$label.'</a>';
            },
            $html,
        ) ?? $html;
    }
}
