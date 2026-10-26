<?php

use App\Models\User;
use App\Support\DocumentStorage;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * Service des justificatifs.
 *
 * Ces fichiers sont fournis par l'utilisateur et servis depuis NOTRE origine :
 * s'ils s'exécutaient, ce serait un XSS stocké avec sa propre session. Ces
 * tests verrouillent les deux garde-fous, dans l'ordre où ils opèrent :
 * le Content-Type imposé d'abord, la CSP ensuite.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    actingAs($this->user);
});

/** Dépose un fichier dans l'arborescence de l'utilisateur et renvoie son URL signée. */
function urlDuJustificatif(User $user, string $nom, string $contenu): string
{
    $chemin = "documents/{$user->id}/pieces-comptables/{$nom}";
    Storage::disk('local')->put($chemin, $contenu);

    return DocumentStorage::temporaryUrl($chemin);
}

/** Un PDF minimal, réellement reconnu comme tel. */
function pdfMinimal(): string
{
    return "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF";
}

// =============================================================================
// Le contenu ne décide jamais de son propre type
// =============================================================================

it('sert un HTML déguisé en PDF comme un PDF, jamais comme du HTML', function () {
    // LE scénario dangereux. Laissé à lui-même, Laravel détecte le type sur les
    // OCTETS (finfo) et servirait ce fichier en text/html : il s'exécuterait
    // alors dans notre origine, avec la session de son propriétaire.
    $url = urlDuJustificatif($this->user, 'facture.pdf', '<html><body><script>alert(1)</script></body></html>');

    $response = get($url);

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Type'))->not->toContain('text/html')
        // nosniff : le navigateur ne doit pas non plus deviner tout seul.
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('impose le type d\'après l\'extension pour chaque format affichable', function (string $nom, string $attendu) {
    $url = urlDuJustificatif($this->user, $nom, pdfMinimal());

    $response = get($url);

    expect($response->headers->get('Content-Type'))->toBe($attendu)
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline');
})->with([
    'pdf' => ['facture.pdf', 'application/pdf'],
    'jpeg' => ['photo.jpg', 'image/jpeg'],
    'jpeg long' => ['photo.jpeg', 'image/jpeg'],
    'png' => ['capture.png', 'image/png'],
    'webp' => ['scan.webp', 'image/webp'],
]);

it('force le téléchargement de tout ce qui n\'est pas affichable', function (string $nom) {
    // Une archive, ou une extension inconnue, ne doit jamais être rendue dans
    // notre origine : elle part en pièce jointe.
    $url = urlDuJustificatif($this->user, $nom, 'contenu quelconque');

    $response = get($url);

    expect($response->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment');
})->with([
    'archive' => ['justificatifs.zip'],
    'extension inconnue' => ['fichier.xyz'],
    'sans extension' => ['fichier'],
]);

// =============================================================================
// Politique de sécurité du contenu
// =============================================================================

it('interdit script, cadre étranger et formulaire sans bloquer la visionneuse PDF', function () {
    $url = urlDuJustificatif($this->user, 'facture.pdf', pdfMinimal());

    $csp = get($url)->headers->get('Content-Security-Policy');

    expect($csp)->toContain("default-src 'none'")
        ->and($csp)->toContain("frame-ancestors 'self'")
        ->and($csp)->toContain("form-action 'none'")
        // ⚠️ La directive `sandbox` NUE donnait au document une origine opaque :
        // elle écrasait l'allow-same-origin du cadre et empêchait la visionneuse
        // PDF de Chrome de s'ouvrir (« Cette page a été bloquée par Chrome »).
        ->and($csp)->not->toMatch('/(^|;)\s*sandbox\s*(;|$)/');
});

it('ne met jamais un justificatif en cache', function () {
    $url = urlDuJustificatif($this->user, 'facture.pdf', pdfMinimal());

    expect(get($url)->headers->get('Cache-Control'))->toContain('no-store');
});

// =============================================================================
// Isolation
// =============================================================================

it('refuse le justificatif d\'un autre utilisateur, même avec une URL signée valable', function () {
    $autre = User::factory()->create();
    $url = urlDuJustificatif($autre, 'facture.pdf', pdfMinimal());

    // L'URL est signée et non expirée : seule la vérification du propriétaire
    // dans le chemin empêche la lecture.
    get($url)->assertForbidden();
});

it('refuse une URL non signée', function () {
    $chemin = "documents/{$this->user->id}/pieces-comptables/facture.pdf";
    Storage::disk('local')->put($chemin, pdfMinimal());

    get('/d/' . $chemin)->assertForbidden();
});

it('refuse un visiteur non connecté', function () {
    $url = urlDuJustificatif($this->user, 'facture.pdf', pdfMinimal());

    auth()->logout();

    get($url)->assertForbidden();
});
