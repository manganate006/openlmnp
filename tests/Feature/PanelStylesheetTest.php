<?php

// Le CSS servi par le panel Filament ne contient AUCUN utilitaire Tailwind : seules les
// classes composants `fi-*` existent (`->viteTheme()` retiré par c10a7c6f, il cassait le
// CSS global). Une classe Tailwind écrite dans une vue du panel ne produit donc RIEN, en
// silence — et une classe `fi-*` mal orthographiée non plus.
//
// Ce piège a déjà frappé trois fois : le wizard d'exercice (mai 2026), la carte démo de
// /login (juillet, icônes de 800 px), puis `fi-header-actions` et `fi-simple-page-footer`
// (classes inventées, corrigées le 2026-08-21). Aucun test ne l'avait vu passer.
//
// Ce test balaie toutes les vues Blade du panel et refuse toute classe qui n'existe nulle
// part. Pour la corriger : soit la définir dans un `<style>` scopé de la vue (pattern
// `wz-*`, `bp-*`, `olmnp-login-*`…), soit utiliser la vraie classe `fi-*`.

/** Répertoires de vues rendus à l'intérieur du panel Filament. */
const PANEL_VIEW_DIRS = ['filament', 'livewire', 'components', 'help'];

/** CSS publié par `php artisan filament:assets` (gitignoré, présent en CI). */
const PANEL_CSS = 'css/filament/filament/app.css';

/**
 * Jetons de couleur du panel : seule vue autorisée à écrire une couleur en dur.
 *
 * @see resources/views/filament/partials/theme-tokens.blade.php
 */
const THEME_TOKENS_VIEW = 'filament/partials/theme-tokens.blade.php';

/**
 * Custom properties injectées à l'EXÉCUTION par `->colors()` — absentes du CSS publié.
 *
 * Filament génère `--{couleur}-{ton}` pour chaque palette déclarée dans le panel, plus la
 * palette `gray` (Zinc par défaut, cf. `ColorManager::$colors`).
 */
const RUNTIME_COLOR_PALETTES = ['primary', 'danger', 'warning', 'success', 'info', 'gray'];

/** @return list<string> */
function panelBladeFiles(): array
{
    $files = [];

    foreach (PANEL_VIEW_DIRS as $dir) {
        $path = resource_path("views/{$dir}");

        if (! is_dir($path)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * Classes définies dans les `<style>` des vues du panel.
 *
 * Le pool est GLOBAL et non par fichier : une fiche `help/*` est stylée par le `<style>`
 * de `livewire/contextual-help.blade.php`, qui l'englobe.
 *
 * @param  list<string>  $files
 * @return array<string, true>
 */
function scopedClassPool(array $files): array
{
    $pool = [];

    foreach ($files as $file) {
        preg_match_all('/<style>(.*?)<\/style>/s', file_get_contents($file), $blocks);

        foreach ($blocks[1] as $block) {
            preg_match_all('/\.([a-zA-Z][\w-]*)/', $block, $selectors);

            foreach ($selectors[1] as $class) {
                $pool[$class] = true;
            }
        }
    }

    return $pool;
}

/**
 * Jetons des attributs `class="…"` STATIQUES d'une vue.
 *
 * Exclut les `:class="…"` d'Alpine (lookbehind sur `:`) et toute valeur interpolée par
 * Blade (`{{ }}`, `{!! !!}`, directive `@`) dont les jetons ne sont pas connus à la lecture.
 *
 * @return list<string>
 */
function staticClassTokens(string $contents): array
{
    preg_match_all('/(?<![:\w-])class="([^"]*)"/', $contents, $matches);

    $tokens = [];

    foreach ($matches[1] as $value) {
        if (str_contains($value, '{{') || str_contains($value, '{!!') || str_contains($value, '@')) {
            continue;
        }

        foreach (preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $tokens[] = $token;
        }
    }

    return array_values(array_unique($tokens));
}

it('never styles the panel with classes that do not exist', function () {
    $files = panelBladeFiles();

    expect($files)->not->toBeEmpty();

    $pool = scopedClassPool($files);
    $dead = [];

    foreach ($files as $file) {
        foreach (staticClassTokens(file_get_contents($file)) as $token) {
            // Une classe est légitime si elle est définie dans un `<style>` du panel,
            // ou si c'est une classe composant Filament (vérifiée par le test suivant).
            if (isset($pool[$token]) || str_starts_with($token, 'fi-')) {
                continue;
            }

            $dead[] = str_replace(resource_path('views').'/', '', $file)." → {$token}";
        }
    }

    expect($dead)->toBe([]);
});

it('only uses Filament component classes that the published stylesheet defines', function () {
    $stylesheet = public_path(PANEL_CSS);

    // Le fichier est gitignoré : il est publié par `filament:assets` (déclenché en CI via
    // composer post-autoload-dump → filament:upgrade). Sur un checkout sans assets publiés,
    // on ne peut rien vérifier — mieux vaut passer que d'échouer pour une mauvaise raison.
    if (! file_exists($stylesheet)) {
        test()->markTestSkipped('CSS du panel absent — lancer `php artisan filament:assets`.');
    }

    $css = file_get_contents($stylesheet);
    $unknown = [];

    foreach (panelBladeFiles() as $file) {
        foreach (staticClassTokens(file_get_contents($file)) as $token) {
            if (! str_starts_with($token, 'fi-')) {
                continue;
            }

            // Tailwind échappe `:` et `/` dans les sélecteurs générés.
            $escaped = str_replace([':', '/'], ['\\\\:', '\\\\/'], preg_quote($token, '/'));

            if (preg_match('/\.'.$escaped.'[\s{,:.>~+\\\\]/', $css)) {
                continue;
            }

            $unknown[] = str_replace(resource_path('views').'/', '', $file)." → {$token}";
        }
    }

    expect($unknown)->toBe([]);
});

// ---------------------------------------------------------------------------------------
// Couleurs : même piège que les classes, en pire — il est INVISIBLE en thème clair.
//
// Vingt vues stylaient leurs cartes avec `var(--fi-body-bg, white)`, `var(--fi-fg-muted, …)`,
// `var(--fi-border-color, …)` — 173 occurrences. Or Filament 5 (Tailwind 4) n'expose AUCUNE
// variable `--fi-*` : chaque `var()` retombait toujours sur son repli clair. En thème sombre
// la carte restait donc blanche, avec le texte clair de Filament par-dessus : blanc sur blanc
// (issue #5, signalée par un utilisateur self-hosted, jamais vue par un test).
//
// Les couleurs vivent désormais dans `theme-tokens.blade.php`, décliné clair/sombre.
// ---------------------------------------------------------------------------------------

/**
 * Contenu des `<style>` d'une vue, blocs `<script>` exclus.
 *
 * Chart.js peint dans un canvas et ne résout pas les custom properties : les palettes de
 * séries restent légitimement en hexadécimal, elles ne concernent pas ce test.
 */
function panelStyleBlocks(string $contents): string
{
    $withoutScripts = preg_replace('/<script[^>]*>.*?<\/script>/s', '', $contents) ?? $contents;

    preg_match_all('/<style>(.*?)<\/style>/s', $withoutScripts, $blocks);

    return implode("\n", $blocks[1]);
}

/**
 * Contenu d'une vue, commentaires et blocs `<script>` retirés.
 *
 * - Les commentaires Blade et CSS citent des noms et des couleurs à titre d'explication
 *   (le partial de jetons documente justement le bug des `--fi-*`) : les scanner
 *   transformerait la documentation en échec.
 * - Chart.js peint dans un canvas et ne résout pas les custom properties : les palettes de
 *   séries restent légitimement en hexadécimal dans le JS.
 */
function scannableMarkup(string $contents): string
{
    foreach (['/<script[^>]*>.*?<\/script>/s', '/\{\{--.*?--\}\}/s', '/\/\*.*?\*\//s'] as $pattern) {
        $contents = preg_replace($pattern, '', $contents) ?? $contents;
    }

    return $contents;
}

it('never reads a CSS custom property that nothing defines', function () {
    $files = panelBladeFiles();

    // Tout ce que les `<style>` du panel définissent (`--x: …`), pool global comme les classes.
    $defined = [];

    foreach ($files as $file) {
        preg_match_all('/(--[\w-]+)\s*:/', panelStyleBlocks(scannableMarkup(file_get_contents($file))), $matches);

        foreach ($matches[1] as $property) {
            $defined[$property] = true;
        }
    }

    $stylesheet = public_path(PANEL_CSS);
    $css = file_exists($stylesheet) ? file_get_contents($stylesheet) : '';

    $dead = [];

    foreach ($files as $file) {
        // Les `var()` des attributs `style="…"` comptent autant que celles des `<style>` :
        // c'est là que vivaient les icônes de badges, grises depuis toujours.
        $scanned = scannableMarkup(file_get_contents($file));

        preg_match_all('/var\(\s*(--[\w-]+)/', $scanned, $matches);

        foreach (array_unique($matches[1]) as $property) {
            if (isset($defined[$property])) {
                continue;
            }

            // Palettes générées à l'exécution : `--primary-500`, `--gray-900`…
            if (preg_match('/^--('.implode('|', RUNTIME_COLOR_PALETTES).')-\d+$/', $property)) {
                continue;
            }

            if ($css !== '' && str_contains($css, $property.':')) {
                continue;
            }

            $dead[] = str_replace(resource_path('views').'/', '', $file)." → var({$property})";
        }
    }

    expect($dead)->toBe([]);
});

it('never reads a CSS custom property from JavaScript that nothing defines', function () {
    $files = panelBladeFiles();

    // Même pool que le test précédent : ce que les `<style>` du panel déclarent.
    $defined = [];

    foreach ($files as $file) {
        preg_match_all('/(--[\w-]+)\s*:/', panelStyleBlocks(scannableMarkup(file_get_contents($file))), $matches);

        foreach ($matches[1] as $property) {
            $defined[$property] = true;
        }
    }

    $stylesheet = public_path(PANEL_CSS);
    $css = file_exists($stylesheet) ? file_get_contents($stylesheet) : '';

    $dead = [];

    foreach ($files as $file) {
        // `scannableMarkup()` retire les `<script>`, à raison : les palettes Chart.js y restent
        // légitimement en hexadécimal. Mais un `getPropertyValue('--x')` reste du CSS lu depuis
        // JS — si le jeton n'existe pas, la valeur est la chaîne vide et le repli s'applique en
        // silence, identiquement sur les deux thèmes. C'est ce trou qui a laissé passer
        // `--fi-body-bg` sur la bordure du donut de l'éditeur d'amortissements, alors même que
        // le test des `var()` avait nettoyé les 173 autres occurrences.
        preg_match_all('/<script[^>]*>.*?<\/script>/s', file_get_contents($file), $scripts);

        foreach ($scripts[0] as $script) {
            preg_match_all('/getPropertyValue\(\s*[\'"](--[\w-]+)[\'"]/', $script, $matches);

            foreach (array_unique($matches[1]) as $property) {
                if (isset($defined[$property])) {
                    continue;
                }

                if (preg_match('/^--('.implode('|', RUNTIME_COLOR_PALETTES).')-\d+$/', $property)) {
                    continue;
                }

                if ($css !== '' && str_contains($css, $property.':')) {
                    continue;
                }

                $dead[] = str_replace(resource_path('views').'/', '', $file)." → getPropertyValue({$property})";
            }
        }
    }

    expect($dead)->toBe([]);
});

it('only spells out colours in the theme token view', function () {
    $offenders = [];

    foreach (panelBladeFiles() as $file) {
        $relative = str_replace(resource_path('views').'/', '', $file);

        if ($relative === THEME_TOKENS_VIEW) {
            continue;
        }

        $scanned = scannableMarkup(file_get_contents($file));

        // Une couleur en dur ne peut pas se décliner en thème sombre : elle doit passer par
        // un jeton. Seules exceptions : les rgba NEUTRES (ombres, voiles blancs des règles
        // `.dark`), qui se comportent correctement sur les deux fonds.
        preg_match_all('/#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b/', $scanned, $hex);

        foreach (array_unique($hex[0]) as $colour) {
            $offenders[] = "{$relative} → {$colour}";
        }

        preg_match_all('/rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/', $scanned, $rgb, PREG_SET_ORDER);

        foreach ($rgb as $match) {
            [, $r, $g, $b] = $match;

            if ($r === $g && $g === $b) {
                continue;
            }

            $offenders[] = "{$relative} → rgb({$r},{$g},{$b})";
        }
    }

    expect($offenders)->toBe([]);
});
