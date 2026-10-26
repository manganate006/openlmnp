<?php

use App\Enums\DossierMemberRole;
use App\Filament\Pages\LinkedDossiers;
use App\Http\Middleware\VerifyDossierContext;
use App\Models\LinkedDossier;
use App\Models\User;
use App\Support\DossierContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

/**
 * D1 du test bêta du 2026-10-15 (bloquant) : le bouton « Ouvrir le dossier » de la page
 * Dossiers liés répondait 419 à CHAQUE clic, dans un seul onglet. Son formulaire n'avait que
 * `@csrf`, sans le champ caché `openlmnp_context` qu'exige App\Http\Middleware\VerifyDossierContext.
 *
 * Le trou des tests précédents : `Tests\TestCase::call()` injecte lui-même l'en-tête de
 * contexte sur chaque requête, si bien qu'un `$this->post(route(...))` passait toujours, quel
 * que soit le contenu du formulaire réellement rendu. Ici, les requêtes de bascule sont faites
 * SANS cette injection (`withoutDossierContextHeader()`), avec les seuls champs que la page
 * rendue contient : exactement ce que fait le navigateur.
 */
function creerDossierPourFormulaires(User $owner, string $siren = '100000009'): LinkedDossier
{
    $dossierUser = new User;
    $dossierUser->forceFill([
        'name' => 'Indivision Formulaires',
        'email' => 'dossier-'.\Illuminate\Support\Str::lower((string) \Illuminate\Support\Str::ulid()).'@dossiers.invalid',
        'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(32)),
        'is_linked_dossier' => true,
        'is_indivision' => true,
        'siren' => $siren,
    ])->save();

    $dossier = LinkedDossier::create([
        'public_id' => (string) \Illuminate\Support\Str::ulid(),
        'user_id' => $dossierUser->id,
        'owner_id' => $owner->id,
        'kind' => 'indivision',
        'siren' => $siren,
    ]);

    $dossier->members()->create([
        'user_id' => $owner->id,
        'role' => DossierMemberRole::Owner,
        'accepted_at' => now(),
    ]);

    return $dossier;
}

/**
 * Extrait, du HTML rendu, le formulaire POST dont l'action vaut `$action`, et renvoie ses
 * champs cachés tels quels (nom => valeur) — ce que le navigateur enverrait au clic.
 * `$boutonClasse` restreint au formulaire dont le bouton porte cette classe : la page
 * Dossiers liés rend AUSSI le sélecteur du menu utilisateur, qui vise la même action, et
 * c'est le bouton de la carte qu'on veut éprouver, pas celui du menu.
 *
 * @return array<string, string>|null  null si aucun formulaire ne vise cette action
 */
function champsDuFormulaire(string $html, string $action, ?string $boutonClasse = null): ?array
{
    $doc = new DOMDocument;
    @$doc->loadHTML('<?xml encoding="utf-8">'.$html);
    $xpath = new DOMXPath($doc);

    foreach ($xpath->query('//form') as $form) {
        /** @var DOMElement $form */
        if (strtoupper($form->getAttribute('method')) !== 'POST' || $form->getAttribute('action') !== $action) {
            continue;
        }

        if ($boutonClasse !== null
            && $xpath->query('.//button[contains(concat(" ", normalize-space(@class), " "), " '.$boutonClasse.' ")]', $form)->length === 0) {
            continue;
        }

        $fields = [];
        foreach ($xpath->query('.//input[@type="hidden"]', $form) as $input) {
            /** @var DOMElement $input */
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        return $fields;
    }

    return null;
}

beforeEach(function () {
    config(['dossiers.enabled' => true, 'services.provisioning.token' => null, 'dossiers.quota' => null]);
});

it('garde-fou : tout formulaire POST d\'une vue qui vise une route du panel inclut le champ de contexte', function () {
    $fautifs = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php') || str_contains($file->getPathname(), '/vendor/')) {
            continue;
        }

        $contenu = $file->getContents();

        // Chaque <form …> … </form>, méthode POST, action vers une route nommée du panel.
        preg_match_all('#<form\b[^>]*>.*?</form>#si', $contenu, $formulaires);

        foreach ($formulaires[0] as $formulaire) {
            if (! preg_match('#<form\b[^>]*method=["\']post["\']#i', $formulaire)) {
                continue;
            }
            if (! preg_match('#<form\b[^>]*action=["\']\{\{\s*route\(\s*[\'"]filament\.admin\.#i', $formulaire)) {
                continue;
            }
            if (! str_contains($formulaire, "@include('filament.partials.dossier-context-field')")) {
                $fautifs[] = $file->getRelativePathname();
            }
        }
    }

    expect($fautifs)->toBe([]);
});

it('le partiel du champ porte le nom attendu par le middleware et le contexte courant', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $html = view('filament.partials.dossier-context-field')->render();

    expect($html)->toContain('name="'.VerifyDossierContext::INPUT.'"')
        ->and($html)->toContain('value="'.DossierContext::contextId().'"');
});

it('page Dossiers liés : le formulaire « Ouvrir le dossier » porte le champ de contexte', function () {
    $principal = User::factory()->create();
    $dossier = creerDossierPourFormulaires($principal);
    $this->actingAs($principal);

    $html = $this->get(LinkedDossiers::getUrl())->assertOk()->getContent();
    $champs = champsDuFormulaire($html, route('filament.admin.dossiers.open', $dossier->public_id), 'ld-btn-open');

    expect($champs)->not->toBeNull()
        ->and($champs)->toHaveKey(VerifyDossierContext::INPUT)
        ->and($champs[VerifyDossierContext::INPUT])->toBe(DossierContext::contextId());
});

it('sélecteur du menu utilisateur : chaque formulaire de bascule porte le champ de contexte', function () {
    $principal = User::factory()->create();
    $dossier = creerDossierPourFormulaires($principal);
    $this->actingAs($principal);

    $html = view('filament.partials.dossier-selector')->render();
    $champs = champsDuFormulaire($html, route('filament.admin.dossiers.open', $dossier->public_id), 'dossier-selector-item');

    expect($champs)->not->toBeNull()
        ->and($champs[VerifyDossierContext::INPUT] ?? null)->toBe(DossierContext::contextId());
});

it('bandeau du dossier : le formulaire « Revenir à mon dossier » porte le champ de contexte', function () {
    $principal = User::factory()->create();
    $dossier = creerDossierPourFormulaires($principal);
    $this->actingAs($principal);
    DossierContext::switchTo($dossier);

    $html = view('filament.partials.dossier-banner', ['slot' => 'topbar'])->render();
    $champs = champsDuFormulaire($html, route('filament.admin.dossiers.leave'), 'db-back');

    expect($champs)->not->toBeNull()
        ->and($champs[VerifyDossierContext::INPUT] ?? null)->toBe(DossierContext::contextId());
});

it('vraie bascule HTTP depuis la page Dossiers liés, avec les seuls champs du formulaire rendu', function () {
    $principal = User::factory()->create();
    $dossier = creerDossierPourFormulaires($principal);
    $this->actingAs($principal);

    $action = route('filament.admin.dossiers.open', $dossier->public_id);
    $champs = champsDuFormulaire($this->get(LinkedDossiers::getUrl())->getContent(), $action, 'ld-btn-open');
    expect($champs)->not->toBeNull();

    // Ni en-tête injecté par TestCase, ni champ ajouté à la main : le navigateur n'envoie que ça.
    $this->withoutDossierContextHeader()
        ->post($action, $champs)
        ->assertRedirect('/');

    expect(Auth::id())->toBe($dossier->user_id);
});

it('vrai retour HTTP depuis le bandeau du dossier, avec les seuls champs du formulaire rendu', function () {
    $principal = User::factory()->create();
    $dossier = creerDossierPourFormulaires($principal);
    $this->actingAs($principal);
    $this->post(route('filament.admin.dossiers.open', $dossier->public_id))->assertRedirect('/');

    $action = route('filament.admin.dossiers.leave');
    $champs = champsDuFormulaire($this->get('/profile')->getContent(), $action, 'db-back');
    expect($champs)->not->toBeNull();

    $this->withoutDossierContextHeader()
        ->post($action, $champs)
        ->assertRedirect('/');

    expect(Auth::id())->toBe($principal->id);
});
