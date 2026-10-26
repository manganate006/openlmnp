<?php

use App\Models\Document;
use App\Models\Property;
use App\Models\User;
use App\Services\Ai\Analysis\DocumentFile;
use App\Services\Ai\DocumentAnalysisService;
use App\Services\Vault\DocumentCipher;
use App\Services\Vault\VaultKeyManager;
use App\Services\Vault\VaultMigrator;
use App\Support\Vault;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Coffre-fort × analyse IA
|--------------------------------------------------------------------------
|
| ⚠️ CE FICHIER EXISTE PARCE QUE RIEN NE COUVRAIT CETTE INTERACTION. Mesuré le
| 2026-09-21, juste après la fusion des deux chantiers : aucun test n'activait
| le coffre dans un scénario IA, aucun test IA ne connaissait le coffre, et
| `prefillAction()` n'était testé nulle part. 1 440 tests passaient au vert
| par-dessus une incompatibilité connue et non corrigée.
|
| Ce que ces tests épinglent, et qui n'est pas une implémentation :
|
| 1. Le coffre a UN SEUL point d'écriture. `DocumentAnalysisService` écrivait en
|    clair sous `documents/{user_id}/` sans passer par `DocumentStorage` : un
|    fichier nu posé au milieu de fichiers scellés, invisible, et qui rend la
|    promesse du coffre fausse sans rien casser d'observable.
| 2. Un dépôt réussit COFFRE VERROUILLÉ — c'est toute la raison d'être de la
|    refonte en paire de clés — et l'analyse au passage ne change rien à ça.
| 3. Un justificatif scellé qu'on soumet à l'analyse se refuse EN LE DISANT.
|    « Format non pris en charge » est un mensonge : le format est parfait,
|    c'est le serveur qui ne peut plus le lire, et c'est ce qu'on a promis.
| 4. Le clair de transit (`ai-staging/`) ne survit pas à l'analyse, et le
|    migrateur du coffre ne vient pas le chiffrer sous les pieds de
|    l'utilisateur pendant qu'il relit une proposition.
|
*/

const ENDPOINT_IA_COFFRE = 'https://openrouter.test/api/v1/chat/completions';

beforeEach(function () {
    config([
        'ai.enabled' => true,
        'ai.api_key' => 'sk-or-v1-factice-pour-les-tests',
        'ai.provider' => 'openrouter',
        'ai.base_url' => 'https://openrouter.test/api/v1',
        'ai.models.documents' => 'modele/rapide',
        'ai.pdf_engine' => 'native',
        'ai.pdf_text_fallback' => 'never',
        // Argon2id en profil « test » : 0,1 ms au lieu de 500 ms. Sans lui,
        // chaque création de coffre coûterait une demi-seconde par test.
        'vault.kdf' => 'test',
    ]);

    $this->user = User::factory()->create();
    $this->property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Studio Lilas',
        'address' => '12 rue des Lilas',
        'city' => 'Lyon',
        'postal_code' => '69003',
        'type' => 'apartment',
        'total_area' => 40,
        'rented_area' => 40,
        'acquisition_date' => '2021-01-01',
        'acquisition_price' => 15000000,
        'notary_fees' => 1000000,
        'land_percentage' => 15,
        'rental_start_date' => '2021-06-01',
        'rental_type' => 'seasonal',
        'tva_regime' => 'exempt',
        'is_primary_residence' => false,
    ]);
    $this->actingAs($this->user);
    $this->service = app(DocumentAnalysisService::class);
});

/** Dépose un PDF de travail dans le staging, comme le fait l'écran d'import. */
function deposerDansStaging(User $user, string $nom = 'facture.pdf'): string
{
    $chemin = "documents/{$user->id}/ai-staging/{$nom}";
    Storage::disk()->put($chemin, "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");

    return $chemin;
}

function reponseIaCoffre(string $fixture): array
{
    return [
        'id' => 'gen-test',
        'model' => 'modele/rapide',
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => (string) file_get_contents(base_path("tests/Fixtures/ai/{$fixture}.json"))],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 4000, 'completion_tokens' => 500, 'cost' => 0.0024],
    ];
}

// =============================================================================
// 1. Le point d'écriture unique
// =============================================================================

it('scelle le justificatif rangé par l\'analyse quand le coffre est actif', function () {
    app(VaultKeyManager::class)->create($this->user, 'une phrase de passe solide');
    Http::fake([ENDPOINT_IA_COFFRE => Http::response(reponseIaCoffre('facture-energie'))]);

    $staging = deposerDansStaging($this->user);
    // ⚠️ `withFile()` n'est pas décoratif : sans lui, `attachDocument()` sort
    // aussitôt sur `blank($result->filePath)` et aucun justificatif n'est rangé.
    $result = $this->service->analyze($staging, $this->property)->withFile($staging, 'facture.pdf');
    $this->service->apply($result, $result->values, $this->user);

    $document = Document::first();
    expect($document)->not->toBeNull();

    $octets = Storage::disk()->get($document->file_path);

    // ⚠️ L'assertion qui compte. Avant le lot B, ce fichier était en clair :
    // `moveToFinalLocation()` faisait `$disk->put($path, $disk->get($staging))`
    // sans jamais passer par DocumentStorage. Tout marchait, et la promesse du
    // coffre était fausse pour ce fichier-là — le seul que l'utilisateur n'a pas
    // déposé lui-même, donc celui qu'il ne pensera jamais à vérifier.
    expect(app(DocumentCipher::class)->isEncryptedContent($octets))->toBeTrue()
        ->and($octets)->not->toContain('%PDF');
});

it('range un fichier en clair quand aucun coffre n\'est actif', function () {
    Http::fake([ENDPOINT_IA_COFFRE => Http::response(reponseIaCoffre('facture-energie'))]);

    $staging = deposerDansStaging($this->user);
    // ⚠️ `withFile()` n'est pas décoratif : sans lui, `attachDocument()` sort
    // aussitôt sur `blank($result->filePath)` et aucun justificatif n'est rangé.
    $result = $this->service->analyze($staging, $this->property)->withFile($staging, 'facture.pdf');
    $this->service->apply($result, $result->values, $this->user);

    $octets = Storage::disk()->get(Document::first()->file_path);

    // Non-régression : le chemin sans coffre ne doit rien gagner ni rien perdre.
    expect($octets)->toContain('%PDF')
        ->and(app(DocumentCipher::class)->isEncryptedContent($octets))->toBeFalse();
});

// =============================================================================
// 2. La promesse de la paire de clés : déposer sans pouvoir relire
// =============================================================================

it('analyse et scelle même coffre VERROUILLÉ', function () {
    app(VaultKeyManager::class)->create($this->user, 'une phrase de passe solide');

    // Aucune session déverrouillée : le serveur n'a que la clé publique.
    expect(Vault::isUnlockedFor($this->user))->toBeFalse()
        ->and(Vault::canWriteFor($this->user))->toBeTrue();

    Http::fake([ENDPOINT_IA_COFFRE => Http::response(reponseIaCoffre('facture-energie'))]);

    $staging = deposerDansStaging($this->user);
    // ⚠️ `withFile()` n'est pas décoratif : sans lui, `attachDocument()` sort
    // aussitôt sur `blank($result->filePath)` et aucun justificatif n'est rangé.
    $result = $this->service->analyze($staging, $this->property)->withFile($staging, 'facture.pdf');
    $this->service->apply($result, $result->values, $this->user);

    $document = Document::first();

    // C'est le point contre-intuitif du chantier, et il n'a aucun test ailleurs :
    // on vient d'écrire un fichier qu'on est désormais incapable de relire.
    expect($document)->not->toBeNull()
        ->and(app(DocumentCipher::class)->isEncryptedContent(Storage::disk()->get($document->file_path)))->toBeTrue();
});

// =============================================================================
// 3. Un fichier scellé se refuse en le disant
// =============================================================================

it('refuse d\'analyser un justificatif scellé en nommant le coffre', function () {
    app(VaultKeyManager::class)->create($this->user, 'une phrase de passe solide');

    // Un justificatif déjà rangé et scellé, tel qu'il est sur le disque.
    $cipher = app(DocumentCipher::class);
    $in = fopen('php://temp', 'w+b');
    fwrite($in, '%PDF-1.4 facture deja rangee');
    rewind($in);
    $out = fopen('php://temp', 'w+b');
    $cipher->encrypt($in, $out, sodium_crypto_secretstream_xchacha20poly1305_keygen(), 1, null, Vault::publicKeyFor($this->user));
    rewind($out);
    $scelle = stream_get_contents($out);

    // ⚠️ Le message doit nommer le coffre. « Format non pris en charge » — ce que
    // rendait le finfo sur des octets chiffrés — envoie l'utilisateur chercher un
    // problème de fichier qui n'existe pas, sur une application qui vient de faire
    // exactement ce qu'elle avait promis.
    expect(fn () => DocumentFile::fromContents($scelle, 'facture.pdf'))
        ->toThrow(RuntimeException::class, 'coffre');
});

// =============================================================================
// 4. Le clair de transit a une durée de vie
// =============================================================================

it('ne laisse rien dans le staging après une analyse appliquée', function () {
    app(VaultKeyManager::class)->create($this->user, 'une phrase de passe solide');
    Http::fake([ENDPOINT_IA_COFFRE => Http::response(reponseIaCoffre('facture-energie'))]);

    $staging = deposerDansStaging($this->user);
    $result = $this->service->analyze($staging, $this->property)->withFile($staging, 'facture.pdf');
    $this->service->apply($result, $result->values, $this->user);

    // Le clair a transité — c'est assumé, l'IA doit bien lire quelque chose —
    // mais il ne doit pas rester sur le disque à côté du scellé.
    expect(Storage::disk()->exists($staging))->toBeFalse();
});

it('n\'encapsule pas un fichier de transit quand le coffre se met en route', function () {
    // L'utilisateur dépose un document pour analyse, part relire la proposition,
    // et active son coffre entre-temps. Sans exclusion, le migrateur chiffre le
    // fichier de staging sous ses pieds : l'aperçu tombe, et « Enregistrer »
    // range du chiffré déjà chiffré.
    $staging = deposerDansStaging($this->user, 'en-cours-de-relecture.pdf');
    $imports = "documents/{$this->user->id}/imports/airbnb.csv";
    Storage::disk()->put($imports, "date,montant\n2026-01-01,100");

    app(VaultKeyManager::class)->create($this->user, 'une phrase de passe solide');
    $cle = app(VaultKeyManager::class)->unlock($this->user, 'une phrase de passe solide');
    app(VaultMigrator::class)->process($this->user, $cle, VaultMigrator::DIRECTION_ENCRYPT);

    $cipher = app(DocumentCipher::class);

    expect($cipher->isEncryptedContent(Storage::disk()->get($staging)))->toBeFalse()
        ->and($cipher->isEncryptedContent(Storage::disk()->get($imports)))->toBeFalse();
});

// =============================================================================
// 5. Le garde-fou qui survit à ce lot
// =============================================================================

it('n\'écrit sous documents/ que par le point d\'écriture unique', function () {
    // ⚠️ Test d'architecture, et le seul qui empêche ce défaut de revenir dans six
    // mois. Deux appelants avaient déjà contourné `DocumentStorage` : l'outil MCP,
    // en RECOPIANT la logique de chiffrement, et le service d'analyse, en ne la
    // faisant pas du tout. Ni l'un ni l'autre ne rougissait — un fichier en clair
    // au milieu de fichiers scellés n'a aucun symptôme.
    //
    // On interdit donc l'écriture brute sur un chemin `documents/`, pas l'écriture
    // en général : `putProtected()` est le seul endroit qui a le droit de décider
    // si un fichier est scellé ou non.
    $offenders = [];

    foreach (rglob(app_path()) as $file) {
        $relative = str_replace(base_path() . '/', '', $file);

        // Le point d'écriture lui-même, et le coffre qui manipule des fichiers
        // déjà chiffrés (migration, déchiffrement en place).
        if (str_contains($relative, 'Support/DocumentStorage.php') || str_contains($relative, 'Services/Vault/')) {
            continue;
        }

        $code = (string) file_get_contents($file);

        // `Storage::put(` / `->put(` suivi, dans la même ligne, d'un chemin documents/
        if (preg_match('/(Storage::|->)put\s*\(\s*\$?\w*[\'"]?documents\//', $code)) {
            $offenders[] = $relative;
        }

        // Ou une variable de chemin manifestement construite sur documents/ et écrite brute.
        if (preg_match('/(Storage::|\$disk->)put\s*\(\s*\$(storagePath|path)\b/', $code)) {
            $offenders[] = $relative;
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([]);
});

/** Parcours récursif : le projet n'a pas de helper maison pour ça. */
function rglob(string $dir): array
{
    $out = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $out[] = $file->getPathname();
        }
    }

    return $out;
}
