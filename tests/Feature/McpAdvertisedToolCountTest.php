<?php

use App\Mcp\OpenLmnpServer;

/**
 * Le nombre d'outils MCP annoncé dans les README doit être celui du REGISTRE du serveur.
 *
 * Ce chiffre a été faux à plusieurs reprises (« 44 » affiché alors que le serveur en
 * enregistrait 45, puis 46) : chaque nouvel outil le périme en silence, et aucun test ne le
 * lisait. Le seul comptage qui fait foi est `$tools` du serveur — pas le nombre de fichiers
 * de `app/Mcp/Tools/`, un fichier n'étant pas un outil enregistré.
 *
 * Partout ailleurs (commentaires, docs internes), on n'écrit PAS de nombre : il n'y a
 * qu'ici qu'un test peut le tenir à jour.
 */
function registeredMcpToolCount(): int
{
    $property = (new ReflectionClass(OpenLmnpServer::class))->getProperty('tools');

    return count($property->getDefaultValue());
}

it('announces in the README exactly the number of tools the server registers', function (string $file, string $pattern) {
    $readme = file_get_contents(base_path($file));

    expect(preg_match($pattern, $readme, $m))->toBe(1, "Aucun nombre d'outils trouvé dans {$file}");
    expect((int) $m[1])->toBe(registeredMcpToolCount());
})->with([
    'README.md'    => ['README.md', '/API MCP \((\d+) outils\)/u'],
    'README.en.md' => ['README.en.md', '/MCP API \((\d+) tools\)/u'],
]);

it('writes no tool count anywhere else in the app code and internal docs', function () {
    $offenders = [];
    $paths = ['app', 'config', 'docs', 'resources/views'];

    foreach ($paths as $path) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($path)));
        foreach ($files as $file) {
            if (! $file->isFile() || ! preg_match('/\.(php|md)$/', $file->getFilename())) {
                continue;
            }
            if (preg_match('/\b\d{2}\s+(outils|tools)\b(?!\s+(de lecture|lecture))/u', file_get_contents($file->getPathname()), $m)) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).' : '.$m[0];
            }
        }
    }

    expect($offenders)->toBe([]);
});
