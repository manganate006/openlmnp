<?php

use Dotenv\Dotenv;
use Illuminate\Support\Str;

/**
 * L'entrypoint recopie les variables `-e` vers `.env`. Écrites NUES, celles qui contiennent
 * un espace cassent le parsing de Dotenv — « Failed to parse dotenv file. Encountered
 * unexpected whitespace » — et l'application ne démarre plus du tout.
 *
 * Ce n'est pas un cas tordu : `APP_NAME`, `MAIL_FROM_NAME` et `AI_APP_TITLE` contiennent
 * naturellement des espaces. Constaté le 2026-09-21 sur un déploiement de préproduction avec
 * `APP_NAME="OpenLMNP (préprod)"` : conteneur en boucle de redémarrage.
 *
 * ⚠️ Le test EXTRAIT la boucle du vrai fichier et la fait tourner pour de bon, plutôt que de
 * réimplémenter sa logique : une copie ici se périmerait à la première retouche, et
 * validerait alors un entrypoint qu'elle ne décrit plus. Et il juge avec le VRAI phpdotenv —
 * un décodeur artisanal accepte des échappements que le parseur refuse (vérifié : l'accent
 * grave échappé passe un test maison et casse Dotenv).
 */
function ecrireEnvParEntrypoint(array $variables): string
{
    $dossier = sys_get_temp_dir() . '/entrypoint-env-' . Str::random(8);
    mkdir($dossier);
    touch($dossier . '/.env');

    $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));

    // Le corps de la boucle, entre le `; do` et le `done` qui la referment.
    preg_match('/for var in .*?; do\n(.*?)\ndone/s', $entrypoint, $m);
    expect($m[1] ?? null)->not->toBeNull('boucle de propagation introuvable dans docker-entrypoint.sh');

    $exports = '';
    foreach ($variables as $nom => $valeur) {
        $exports .= sprintf("export %s=%s\n", $nom, escapeshellarg($valeur));
    }

    $noms = implode(' ', array_keys($variables));
    $script = "set -eu\ncd " . escapeshellarg($dossier) . "\n{$exports}for var in {$noms}; do\n{$m[1]}\ndone\n";

    file_put_contents($dossier . '/run.sh', $script);
    exec('bash ' . escapeshellarg($dossier . '/run.sh') . ' 2>&1', $sortie, $code);

    expect($code)->toBe(0, 'la boucle a échoué : ' . implode("\n", $sortie));

    return $dossier;
}

it('écrit un .env que phpdotenv sait relire, espaces compris', function () {
    $attendu = [
        'APP_NAME' => 'OpenLMNP (préprod)',
        'MAIL_FROM_NAME' => 'OpenLMNP Support',
        'AI_APP_TITLE' => 'Simple',
        // Tout ce qui a un sens dans un shell, dans une regex de sed, ou entre guillemets
        // doubles pour Dotenv. L'accent grave est là exprès : l'échapper casse le fichier.
        'MAIL_PASSWORD' => 'mot"de$passe`bizarre\\avec|barre&et',
        'APP_URL' => 'https://appfutur.openlmnp.fr',
    ];

    $dossier = ecrireEnvParEntrypoint($attendu);

    $lu = Dotenv::createArrayBacked($dossier)->load();

    foreach ($attendu as $nom => $valeur) {
        expect($lu[$nom] ?? null)->toBe($valeur, "aller-retour cassé pour {$nom}");
    }
});

it('met à jour une ligne existante sans la dupliquer ni la casser', function () {
    // Le chemin `sed -i` du remplacement, distinct de l'ajout : c'est lui qui portait
    // l'échappement supplémentaire, et celui qu'on exerce le moins.
    $dossier = ecrireEnvParEntrypoint(['APP_NAME' => 'Premier nom']);

    file_put_contents($dossier . '/run2.sh', "set -eu\ncd " . escapeshellarg($dossier) . "\n"
        . "export APP_NAME='Deuxième nom (avec parenthèses)'\n"
        . preg_replace('/^.*?for var in/s', 'for var in', file_get_contents($dossier . '/run.sh')));

    // On rejoue la même boucle avec une nouvelle valeur.
    $script = file_get_contents($dossier . '/run.sh');
    $script = str_replace("export APP_NAME='Premier nom'", "export APP_NAME='Deuxième nom (avec parenthèses)'", $script);
    file_put_contents($dossier . '/run.sh', $script);
    exec('bash ' . escapeshellarg($dossier . '/run.sh') . ' 2>&1', $sortie, $code);

    expect($code)->toBe(0);

    $contenu = file_get_contents($dossier . '/.env');

    expect(substr_count($contenu, 'APP_NAME='))->toBe(1)
        ->and(Dotenv::createArrayBacked($dossier)->load()['APP_NAME'])->toBe('Deuxième nom (avec parenthèses)');
});
