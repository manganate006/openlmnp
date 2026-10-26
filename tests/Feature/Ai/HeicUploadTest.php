<?php

use Filament\Support\Facades\FilamentAsset;

/**
 * Conversion HEIC → JPEG côté navigateur : le serveur ne décode jamais de HEIC,
 * il fournit seulement le script d'accroche et la bibliothèque à la demande.
 */
it('enregistre le convertisseur HEIC dans les assets du panel', function () {
    expect(FilamentAsset::getScriptSrc('heic-upload', 'openlmnp'))->toContain('heic-upload')
        ->and(FilamentAsset::getScriptSrc('heic2any', 'openlmnp'))->toContain('heic2any')
        // Le script d'accroche trouve l'adresse de la bibliothèque dans filamentData.
        ->and(FilamentAsset::getScriptData())->toHaveKey('heic2anySrc');
});

it('embarque la bibliothèque vendorisée et sa licence', function () {
    // ⚠️ Pas dans un dossier nommé « vendor » : les rsync de déploiement et de
    // test excluent tout répertoire de ce nom, où qu'il soit.
    expect(file_exists(resource_path('js/lib/heic2any/heic2any.min.js')))->toBeTrue()
        ->and(filesize(resource_path('js/lib/heic2any/heic2any.min.js')))->toBeGreaterThan(500_000)
        ->and(file_get_contents(resource_path('js/lib/heic2any/LICENSE.md')))->toContain('MIT');
});

it('remplace le HEIC par un JPEG avant téléversement, sans jamais envoyer l\'original', function () {
    $script = file_get_contents(resource_path('js/heic-upload.js'));

    expect($script)->toContain('beforeAddFile')
        ->and($script)->toContain("toType: 'image/jpeg'")
        ->and($script)->toContain('heic2anySrc')
        // La bibliothèque n'est chargée que lorsqu'un HEIC est déposé.
        ->and($script)->toContain('loadHeic2any')
        ->and($script)->toContain('return false');
});
