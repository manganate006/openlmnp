<?php

use App\Services\Ai\Assistant\HelpIndex;
use App\Services\Ai\Assistant\HelpIndexBuilder;
use App\Support\HelpContentRegistry;
use Illuminate\Support\Facades\File;

/**
 * Ancrage documentaire de l'assistant : l'index doit couvrir toute la documentation
 * embarquée et remonter la bonne section pour une question posée en français courant.
 */
beforeEach(function () {
    // On force la construction depuis le code (et non depuis un index déjà écrit)
    // pour que les tests décrivent la documentation réelle du dépôt.
    File::delete(HelpIndex::path());

    $this->index = new HelpIndex;
});

it('couvre toutes les routes documentées du registre d\'aide', function () {
    $sections = (new HelpIndexBuilder)->build();

    $routes = array_filter(array_column($sections, 'route'));

    // `toContain` accepte plusieurs valeurs : on passe la liste complète des routes.
    expect($routes)->toContain(...array_keys(HelpContentRegistry::all()));
});

it('produit des sections courtes et exploitables comme extraits', function () {
    $sections = (new HelpIndexBuilder)->build();

    expect($sections)->not->toBeEmpty();

    foreach ($sections as $section) {
        expect($section['text'])->not->toBeEmpty()
            ->and(mb_strlen($section['text']))->toBeLessThanOrEqual(2000)
            ->and($section['tokens'])->toBeGreaterThan(0);
    }
});

it('ne laisse ni balise HTML ni entité dans le texte indexé', function () {
    $sections = (new HelpIndexBuilder)->build();

    $help = array_values(array_filter($sections, fn ($s) => str_starts_with($s['id'], 'help.')));

    foreach ($help as $section) {
        expect($section['text'])->not->toContain('<div')
            ->and($section['text'])->not->toContain('&mdash;')
            ->and($section['text'])->not->toContain('&#x');
    }
});

it('remonte la section des charges pour une question sur la taxe foncière', function () {
    $sections = $this->index->retrieve('Où saisir ma taxe foncière ?');

    expect($sections)->not->toBeEmpty();

    $ids = array_column($sections, 'id');

    expect(implode(' ', $ids))->toContain('help.expenses');
});

it('ignore les accents et la casse', function () {
    $avec = array_column($this->index->retrieve('Comment amortir le MOBILIER ?'), 'id');
    $sans = array_column($this->index->retrieve('comment amortir le mobilier'), 'id');

    expect($avec)->toBe($sans)->and($avec)->not->toBeEmpty();
});

it('rapproche le pluriel du singulier', function () {
    $ids = array_column($this->index->retrieve('à quoi servent les composants'), 'id');

    expect(implode(' ', $ids))->toContain('help.property-components');
});

it('privilégie la page courante à score comparable', function () {
    $question = 'comment ça marche ?';

    $surCharges = $this->index->retrieve($question, 'filament.admin.resources.expenses.index');

    // La vue d'ensemble de la page courante est toujours jointe au contexte.
    expect(implode(' ', array_column($surCharges, 'route')))
        ->toContain('filament.admin.resources.expenses');
});

it('indexe la FAQ et les outils MCP en plus de l\'aide contextuelle', function () {
    $ids = array_column((new HelpIndexBuilder)->build(), 'id');
    $joined = implode(' ', $ids);

    expect($joined)->toContain('docs/FAQ.md')
        ->and($joined)->toContain('docs/FONCTIONNALITES.md')
        ->and($joined)->toContain('mcp/tools');
});

it('reconstruit l\'index à la volée quand le fichier est absent', function () {
    expect(File::exists(HelpIndex::path()))->toBeFalse();

    expect((new HelpIndex)->sections())->not->toBeEmpty();
});

it('écrit un index relisable', function () {
    $count = (new HelpIndexBuilder)->write();

    expect(File::exists(HelpIndex::path()))->toBeTrue()
        ->and($count)->toBeGreaterThan(50);

    $decoded = json_decode(File::get(HelpIndex::path()), true);

    expect($decoded['sections'])->toHaveCount($count);
});
