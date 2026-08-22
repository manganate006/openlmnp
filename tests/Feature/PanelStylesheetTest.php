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
