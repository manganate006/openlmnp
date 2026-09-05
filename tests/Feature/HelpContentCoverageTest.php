<?php

use App\Support\HelpContentRegistry;

/**
 * Couverture de l'aide contextuelle : toute fiche d'aide doit être RECENSÉE.
 *
 * ⚠️ Le registre n'est pas seulement ce qui décide de la fiche affichée dans le panneau
 * latéral. C'est aussi l'ÉNUMÉRATION dont part l'index documentaire de l'assistant IA
 * (`HelpIndexBuilder::fromHelpViews()` parcourt `HelpContentRegistry::all()`, et non le
 * dossier des vues). Une fiche présente sur le disque mais absente du registre est donc
 * invisible deux fois, et sans le moindre message : l'écran retombe sur `help._fallback`,
 * et l'assistant répond « je ne sais pas » sur une fonctionnalité pourtant documentée.
 *
 * C'est exactement ce qui s'est produit pour les fiches de reprise de dossier : elles
 * existaient, l'assistant les ignorait. Le cas typique qui la rejouera est un MERGE entre
 * deux branches qui ont chacune ajouté des entrées au même tableau — ne garder qu'un côté
 * ne casse rien de visible.
 *
 * @return list<string> Noms de vues (sans le préfixe `help.`), partiels exclus.
 */
function helpViewNames(): array
{
    $names = [];

    foreach (glob(resource_path('views/help/*.blade.php')) ?: [] as $path) {
        $name = basename($path, '.blade.php');

        // `_styles` et `_fallback` sont des partiels : ils n'ont pas d'écran à documenter.
        if (str_starts_with($name, '_')) {
            continue;
        }

        $names[] = $name;
    }

    sort($names);

    return $names;
}

/** @return list<string> Noms de vues recensés par le registre, dédoublonnés. */
function registeredHelpViewNames(): array
{
    $names = [];

    foreach ((new ReflectionClass(HelpContentRegistry::class))->getStaticProperties() as $group) {
        if (! is_array($group)) {
            continue;
        }

        foreach ($group as $entry) {
            if (is_array($entry) && isset($entry['view'])) {
                $names[] = $entry['view'];
            }
        }
    }

    $names = array_values(array_unique($names));
    sort($names);

    return $names;
}

it('recense toutes les fiches d\'aide présentes sur le disque', function () {
    $orphans = array_values(array_diff(helpViewNames(), registeredHelpViewNames()));

    expect($orphans)->toBe([], 'Fiches d\'aide absentes de HelpContentRegistry (invisibles '
        ."de l'écran ET de l'index de l'assistant) : ".implode(', ', $orphans));
});

it('ne recense aucune fiche d\'aide qui n\'existe pas', function () {
    $missing = array_values(array_diff(registeredHelpViewNames(), helpViewNames()));

    expect($missing)->toBe([], 'Entrées du registre sans vue correspondante (repli muet sur '
        .'help._fallback) : '.implode(', ', $missing));
});

it('recense les fiches du chantier de reprise', function () {
    // Ancrage explicite : ces deux-là sont les fiches que l'assistant ignorait.
    expect(registeredHelpViewNames())
        ->toContain('reprise-dossier')
        ->toContain('import-csv');
});

it('résout la fiche de reprise depuis sa route, sans retomber sur le repli', function () {
    expect(HelpContentRegistry::resolve('filament.admin.pages.reprise-dossier'))
        ->toBe(['view' => 'help.reprise-dossier', 'title' => 'Reprendre un dossier existant']);
});

/**
 * Les fiches mises à jour pour la reprise décrivent des champs qui existent VRAIMENT à
 * l'écran. Une aide qui documente un champ inexistant coûte plus cher qu'une aide absente ;
 * ces ancres échouent si l'écran correspondant est renommé sans que l'aide suive.
 */
it('décrit les écrans du bac à sable avec leurs libellés réels', function () {
    // L'expiration du bac à sable n'a pas de route à elle : ses deux composants sont montés
    // par `renderHook` PAR-DESSUS n'importe quel écran. Le registre indexant par nom de
    // route, l'aide vit donc dans une section conditionnelle de `help/dashboard.blade.php`
    // — le seul écran que tout visiteur de démo traverse, et celui où atterrit un compte
    // promu. Sans cette ancre, rien ne relierait cette aide aux écrans qu'elle décrit, et
    // renommer un bouton la périmerait EN SILENCE.
    $anchors = [
        'resources/views/livewire/demo-seed-choice.blade.php' => [
            'Ne garder que mes saisies', 'Tout garder', 'Repartir de zéro',
        ],
        'resources/views/livewire/demo-expiry-prompt.blade.php' => [
            'Garder mes données', 'Continuer la démonstration',
        ],
    ];

    $help = file_get_contents(resource_path('views/help/dashboard.blade.php'));
    $missing = [];

    foreach ($anchors as $screen => $labels) {
        $source = file_get_contents(base_path($screen));

        foreach ($labels as $label) {
            if (! str_contains($help, $label)) {
                $missing[] = "l'aide du tableau de bord ne cite plus « {$label} »";
            }

            if (! str_contains($source, $label)) {
                $missing[] = "{$screen} ne porte plus « {$label} » — l'aide décrit un bouton inexistant";
            }
        }
    }

    expect($missing)->toBe([], implode(' | ', $missing));
});

it('avertit du caractère irréversible du choix des données d\'exemple', function () {
    // Le seul écran irréversible de tout le chantier : une aide qui ne le DIT pas est pire
    // qu'absente, puisqu'elle laisse croire que le sujet est couvert.
    $help = file_get_contents(resource_path('views/help/dashboard.blade.php'));

    expect($help)->toContain('ne se fait qu\'une fois');
    expect($help)->toContain('rien n\'est récupérable');
});

it('décrit des libellés réellement présents à l\'écran', function () {
    $anchors = [
        // Éditeur d'amortissements : bascule de mode, colonnes du tableau, bouton d'ajout.
        'help/property-components.blade.php' => [
            'resources/views/filament/partials/depreciation-editor-core.blade.php',
            ['Montants', 'Ligne 2033-C', 'Début', '+ Ajouter un composant'],
        ],
        // Travaux et mobilier : section de reprise repliée, avec ses deux champs.
        // ⚠️ L'intitulé est cherché sans son apostrophe : la source PHP l'échappe
        // (`'Reprise d\\'une comptabilité existante'`), le Blade non.
        'help/property-works.blade.php' => [
            'app/Filament/Resources/PropertyWorks/Schemas/PropertyWorkForm.php',
            ['une comptabilité existante', 'Dotation annuelle recopiée', 'Amortissements déjà pratiqués'],
        ],
        'help/furniture.blade.php' => [
            'app/Filament/Resources/Furniture/Schemas/FurnitureForm.php',
            ['une comptabilité existante', 'Dotation annuelle recopiée', 'Amortissements déjà pratiqués'],
        ],
        // Exercices : badge de reprise et action de recalcul de la chaîne.
        'help/fiscal-years.blade.php' => [
            'app/Filament/Resources/FiscalYears/Tables/FiscalYearsTable.php',
            ['Reprise', 'Recalculer la chaîne'],
        ],
    ];

    // `toContain()` traite ses arguments suivants comme d'AUTRES aiguilles, pas comme un
    // message : on accumule les manques et on tranche une seule fois, message compris.
    $missing = [];

    foreach ($anchors as $helpView => [$screen, $labels]) {
        $help = file_get_contents(resource_path('views/'.$helpView));
        $source = file_get_contents(base_path($screen));

        foreach ($labels as $label) {
            if (! str_contains($help, $label)) {
                $missing[] = "{$helpView} ne cite plus « {$label} »";
            }

            if (! str_contains($source, $label)) {
                $missing[] = "{$screen} ne porte plus le libellé « {$label} » — l'aide décrit un champ inexistant";
            }
        }
    }

    expect($missing)->toBe([], implode(' | ', $missing));
});

/**
 * Le 2033-D : 982/983/984 suivent les DÉFICITS, 870 les amortissements différés.
 * Les confondre était le défaut de conformité corrigé en v1.4.0 — l'aide doit dire
 * lequel est lequel, et le dire comme le code le calcule.
 */
it('explique le 2033-D comme le code le calcule', function () {
    $help = file_get_contents(resource_path('views/help/teledeclaration.blade.php'));

    expect($help)
        ->toContain('982')
        ->toContain('984')
        ->toContain('870')
        ->toContain('dix ans')
        ->toContain('sans limite de durée');

    // La case de déficit du 2042-C-PRO est bien celle que produit TaxReturnService.
    $cases = app(App\Services\TaxReturnService::class)->compute2042(new App\Models\FiscalYear([
        'fiscal_result' => -100,
    ]));

    expect($help)->toContain($cases['case_benefice'])->toContain($cases['case_deficit']);
});
