<?php

// Laravel 11 a déplacé la racine du disque `local` de `storage/app` vers
// `storage/app/private`. Les `file_path` en base sont relatifs à cette racine : les
// fichiers déposés avant la montée de version sont restés à l'ancien emplacement et ont
// cessé d'être servis, sans erreur ni trace dans les journaux. Sur l'instance de
// référence, 14 justificatifs sur 97 étaient dans ce cas.
//
// Deux garanties à figer, et elles ne se remplacent pas :
//   - la LECTURE retombe sur l'ancien emplacement, pour les instances où la commande de
//     migration ne sera jamais lancée ;
//   - la COMMANDE rapatrie les fichiers, pour solder la dette là où on peut l'exécuter.

use App\Models\User;
use App\Support\DocumentStorage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

// `Storage::fake()` ne sert à rien ici : `legacyDisk()` est bâti sur `storage_path('app')`,
// un chemin réel qu'un disque simulé n'intercepte pas. On déplace donc TOUT le stockage de
// l'application dans un dossier temporaire — sans quoi les fichiers d'un test survivent au
// suivant, et un jour finiraient par toucher le `storage/` d'une vraie installation.
beforeEach(function () {
    $this->storageRoot = sys_get_temp_dir().'/olmnp-legacy-'.Str::random(12);
    File::makeDirectory($this->storageRoot.'/app/private', 0755, true);

    $this->app->useStoragePath($this->storageRoot);
    config(['filesystems.disks.local.root' => $this->storageRoot.'/app/private']);
    Storage::forgetDisk('local');
});

afterEach(function () {
    File::deleteDirectory($this->storageRoot);
});

/** Écrit un fichier à l'ANCIENNE racine, celle d'avant Laravel 11. */
function putLegacyFile(string $path, string $contents = 'ancien justificatif'): void
{
    DocumentStorage::legacyDisk()->put($path, $contents);
}

function signedDocumentUrl(string $path): string
{
    return URL::temporarySignedRoute('documents.show', now()->addMinutes(5), ['path' => $path]);
}

// === Repli de lecture ===

it('serves a document left at the pre-Laravel-11 location', function () {
    $user = User::factory()->create();
    $path = "documents/{$user->id}/expenses/facture.pdf";

    putLegacyFile($path, 'contenu hérité');

    // Le fichier n'existe PAS sous la racine courante : sans repli, c'est un 404.
    expect(Storage::disk('local')->exists($path))->toBeFalse();

    $response = $this->actingAs($user)->get(signedDocumentUrl($path));

    $response->assertOk();
    expect($response->streamedContent())->toBe('contenu hérité');
});

it('prefers the current location when a file exists on both sides', function () {
    $user = User::factory()->create();
    $path = "documents/{$user->id}/expenses/facture.pdf";

    putLegacyFile($path, 'version héritée');
    Storage::disk('local')->put($path, 'version courante');

    $response = $this->actingAs($user)->get(signedDocumentUrl($path));

    $response->assertOk();
    expect($response->streamedContent())->toBe('version courante');
});

it('still refuses a legacy document belonging to someone else', function () {
    // Le repli ne doit pas rouvrir ce que l'isolation ferme : le contrôle de propriété
    // se fait sur le chemin, avant toute résolution de disque.
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $path = "documents/{$owner->id}/expenses/facture.pdf";

    putLegacyFile($path);

    $this->actingAs($intruder)->get(signedDocumentUrl($path))->assertForbidden();
});

it('still 404s when the file exists at neither location', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(signedDocumentUrl("documents/{$user->id}/expenses/fantome.pdf"))
        ->assertNotFound();
});

// === Commande de migration ===

it('reports without moving anything until --fix is passed', function () {
    $path = 'documents/1/expenses/facture.pdf';
    putLegacyFile($path);

    $this->artisan('openlmnp:migrate-document-storage')
        ->expectsOutputToContain($path)
        ->expectsOutputToContain('Relancer avec --fix')
        ->assertExitCode(0);

    expect(DocumentStorage::legacyDisk()->exists($path))->toBeTrue()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('moves legacy files under the current root with --fix', function () {
    $path = 'documents/1/expenses/facture.pdf';
    putLegacyFile($path, 'contenu');

    $this->artisan('openlmnp:migrate-document-storage', ['--fix' => true])
        ->assertExitCode(0);

    expect(Storage::disk('local')->get($path))->toBe('contenu')
        ->and(DocumentStorage::legacyDisk()->exists($path))->toBeFalse();
});

it('is idempotent', function () {
    $path = 'documents/1/expenses/facture.pdf';
    putLegacyFile($path, 'contenu');

    $this->artisan('openlmnp:migrate-document-storage', ['--fix' => true])->assertExitCode(0);

    $this->artisan('openlmnp:migrate-document-storage', ['--fix' => true])
        ->expectsOutputToContain('Aucun justificatif')
        ->assertExitCode(0);

    expect(Storage::disk('local')->get($path))->toBe('contenu');
});

it('never overwrites a file that already exists under the current root', function () {
    // Le seul geste réellement destructeur que cette commande pourrait faire.
    $path = 'documents/1/expenses/facture.pdf';
    putLegacyFile($path, 'version héritée');
    Storage::disk('local')->put($path, 'version courante');

    $this->artisan('openlmnp:migrate-document-storage', ['--fix' => true])
        ->expectsOutputToContain('des DEUX côtés')
        ->assertExitCode(0);

    expect(Storage::disk('local')->get($path))->toBe('version courante')
        ->and(DocumentStorage::legacyDisk()->get($path))->toBe('version héritée');
});

it('leaves the rest of storage/app alone', function () {
    // L'ancienne racine CONTIENT la nouvelle : un parcours récursif naïf déplacerait
    // `private/` sur lui-même et emporterait la clé d'instance au passage.
    $legacy = DocumentStorage::legacyDisk();
    $legacy->put('.app_key', 'base64:cle-d-instance');
    $legacy->put('public/photo.jpg', 'photo publique');
    Storage::disk('local')->put('documents/1/expenses/deja-la.pdf', 'déjà migré');

    $this->artisan('openlmnp:migrate-document-storage', ['--fix' => true])->assertExitCode(0);

    expect($legacy->get('.app_key'))->toBe('base64:cle-d-instance')
        ->and($legacy->get('public/photo.jpg'))->toBe('photo publique')
        ->and($legacy->exists('private/documents/1/expenses/deja-la.pdf'))->toBeTrue();
});
