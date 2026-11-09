<?php

// Version installée, dernière version publiée et notes de version.
//
// Trois défauts corrigés ici, chacun verrouillé par un test :
//   1. l'information n'existait que sur `AdminUpdate`, réservée aux administrateurs ;
//   2. elle n'apparaissait qu'après un clic, et le montage appelait GitHub à chaque
//      affichage (10 s d'attente hors ligne, 3 requêtes sur les 60/h de l'API) ;
//   3. les notes de release s'affichaient en Markdown BRUT (`###`, `**`).
//
// Plus deux « v0.1 » écrits en dur, dont un dans un PDF remis à l'administration.

use App\Filament\Pages\AdminUpdate;
use App\Filament\Pages\SystemStatus;
use App\Filament\Pages\WhatsNew;
use App\Models\FiscalYear;
use App\Models\Property;
use App\Models\User;
use App\Services\TaxReturnService;
use App\Services\UpdateService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    // Le cache est partagé par tout le processus : sans purge, un test hérite du relevé
    // du précédent et les assertions de comptage d'appels ne veulent plus rien dire.
    cache()->clear();
});

/**
 * Utilisateur ORDINAIRE.
 *
 * ⚠️ Passer `is_admin => false` à la fabrique ne suffit pas : sur une base de test vierge,
 * `AppServiceProvider::boot()` promeut le PREMIER utilisateur créé en administrateur
 * (`User::creating`). Il faut donc le rétrograder après coup.
 */
function ordinaryUser(): User
{
    $user = User::factory()->create();
    $user->update(['is_admin' => false]);

    return $user->refresh();
}

function administrator(): User
{
    $user = User::factory()->create();
    $user->update(['is_admin' => true]);

    return $user->refresh();
}

/** Réponses GitHub par défaut : une release plus récente que la version installée. */
function fakeGithubReleases(string $notes = "### Ajouté\n- **Une** nouveauté\n- Une autre"): void
{
    Http::fake([
        'api.github.com/repos/*/releases/latest' => Http::response([
            'tag_name' => 'v99.0.0',
            'name' => 'Version 99',
            'published_at' => '2026-09-04T10:00:00Z',
            'body' => $notes,
            'tarball_url' => 'https://api.github.com/tarball/v99.0.0',
        ], 200),
        'api.github.com/repos/*/releases*' => Http::response([[
            'tag_name' => 'v99.0.0',
            'name' => 'Version 99',
            'published_at' => '2026-09-04T10:00:00Z',
            'body' => $notes,
        ]], 200),
        'api.github.com/*' => Http::response(['ahead_by' => 0, 'commits' => []], 200),
    ]);
}

// =========================================================================================
// 1. Visible par tous — c'était la demande : la version et le changelog ne sont pas
//    réservés à l'administrateur de l'instance.
// =========================================================================================

it('opens the whats-new page to a non-admin user', function () {
    $this->actingAs(ordinaryUser());

    expect(WhatsNew::canAccess())->toBeTrue()
        ->and(AdminUpdate::canAccess())->toBeFalse();

    Livewire::test(WhatsNew::class)->assertOk();
});

it('shows the installed version to a non-admin user', function () {
    fakeGithubReleases();
    $this->actingAs(ordinaryUser());

    Livewire::test(WhatsNew::class)
        ->assertOk()
        ->assertSee('v'.(new UpdateService())->getCurrentVersion());
});

it('offers the update page only to an administrator', function () {
    fakeGithubReleases();

    $this->actingAs(ordinaryUser());
    Livewire::test(WhatsNew::class)->assertDontSee('Gérer les mises à jour');

    $this->actingAs(administrator());
    Livewire::test(WhatsNew::class)->assertSee('Gérer les mises à jour');
});

// -----------------------------------------------------------------------------------------
// … mais on ne lui annonce pas une mise à jour qu'il ne peut pas appliquer.
//
// Sur le cloud et sur l'image Docker officielle, `UPDATE_SELF_APPLY=false` : l'instance est
// immuable, c'est l'exploitant qui la met à jour. Un badge « v1.4.6 disponible » servi à un
// client décrit alors une action qui n'existe pas pour lui — au mieux il l'ignore, au pire
// il écrit au support. Ce qui reste, et qui répond à sa vraie question, c'est la version
// installée et les notes des dernières versions.
// -----------------------------------------------------------------------------------------

it('hides the available badge from a user who cannot apply the update', function () {
    config(['updater.self_apply' => false]);
    fakeGithubReleases();
    $this->actingAs(ordinaryUser());

    Livewire::test(WhatsNew::class)
        ->call('refreshVersionInfo')
        ->assertOk()
        ->assertDontSee('v99.0.0 disponible')
        // ⚠️ Le repli ne doit pas être « Version à jour » : ce serait un mensonge.
        ->assertDontSee('Version à jour')
        // On a retiré un badge, pas vidé la page.
        ->assertSee('v'.(new UpdateService())->getCurrentVersion())
        ->assertSee('Nouveautés des dernières versions')
        ->assertSee('Version 99');
});

it('keeps the available badge for the administrator of an immutable instance', function () {
    config(['updater.self_apply' => false]);
    fakeGithubReleases();

    // C'est lui qui lance le `docker pull` : le lui cacher le priverait de l'information.
    $this->actingAs(administrator());

    Livewire::test(WhatsNew::class)
        ->call('refreshVersionInfo')
        ->assertOk()
        ->assertSee('v99.0.0 disponible');
});

it('shows the available badge to anyone when the instance can update itself', function () {
    config(['updater.self_apply' => true]);
    fakeGithubReleases();
    $this->actingAs(ordinaryUser());

    Livewire::test(WhatsNew::class)
        ->call('refreshVersionInfo')
        ->assertOk()
        ->assertSee('v99.0.0 disponible');
});

// =========================================================================================
// 2. Le montage n'attend jamais le réseau
// =========================================================================================

it('never calls github while mounting a page', function () {
    fakeGithubReleases();
    $this->actingAs(administrator());

    Livewire::test(WhatsNew::class)->assertOk();
    Livewire::test(AdminUpdate::class)->assertOk();

    // ⚠️ Le test qui compte : avant ce lot, `AdminUpdate::mount()` appelait
    // `checkBranchUpdates()`, donc GitHub, à chaque affichage de la page.
    Http::assertNothingSent();
});

it('fetches on wire:init, then serves the cache without calling github again', function () {
    fakeGithubReleases();
    $this->actingAs(ordinaryUser());

    Livewire::test(WhatsNew::class)
        ->assertSet('updateInfo', null)
        ->call('refreshVersionInfo')
        ->assertSee('v99.0.0 disponible');

    $firstRound = count(Http::recorded());
    expect($firstRound)->toBeGreaterThan(0);

    // Cache chaud : le relevé est déjà là au montage, et rien ne repart sur le réseau.
    Livewire::test(WhatsNew::class)
        ->assertSee('v99.0.0 disponible')
        ->call('refreshVersionInfo');

    expect(Http::recorded())->toHaveCount($firstRound);
});

it('stops emitting wire:init once the cache is warm', function () {
    fakeGithubReleases();
    $this->actingAs(ordinaryUser());

    Livewire::test(WhatsNew::class)->call('refreshVersionInfo');

    // Cache chaud = aucun aller-retour Livewire au chargement suivant.
    Livewire::test(WhatsNew::class)
        ->assertOk()
        ->assertDontSee('wire:init="refreshVersionInfo"', escape: false);
});

it('lets the check button force a fresh call despite a warm cache', function () {
    fakeGithubReleases();
    $this->actingAs(administrator());

    Livewire::test(AdminUpdate::class)->call('refreshVersionInfo');
    $warm = count(Http::recorded());

    Livewire::test(AdminUpdate::class)->call('checkUpdate');

    expect(count(Http::recorded()))->toBeGreaterThan($warm);
});

it('keeps rendering when github is unreachable, and does not retry on every view', function () {
    Http::fake(['api.github.com/*' => Http::response([], 500)]);
    $this->actingAs(ordinaryUser());

    Livewire::test(WhatsNew::class)
        ->call('refreshVersionInfo')
        ->assertOk()
        ->assertSee('Vérification impossible')
        ->assertSee('v'.(new UpdateService())->getCurrentVersion());

    $afterFailure = count(Http::recorded());

    // L'échec est retenu brièvement : sans ça, chaque affichage repaie le délai HTTP.
    Livewire::test(WhatsNew::class)->call('refreshVersionInfo');

    expect(Http::recorded())->toHaveCount($afterFailure);
});

it('never leaves the network on an instance with the check disabled', function () {
    config(['updater.check_enabled' => false]);
    Http::fake();
    $this->actingAs(ordinaryUser());

    Livewire::test(WhatsNew::class)
        ->call('refreshVersionInfo')
        ->assertOk()
        ->assertSee('Vérification désactivée')
        // La version installée est locale : elle reste affichée dans tous les cas.
        ->assertSee('v'.(new UpdateService())->getCurrentVersion());

    Http::assertNothingSent();
});

// =========================================================================================
// 3. Les notes de version sont du Markdown, et il vient de l'extérieur
// =========================================================================================

it('renders the release notes as html instead of raw markdown', function () {
    fakeGithubReleases("### Ajouté\n- Une **nouveauté**\n- Une autre");
    $this->actingAs(ordinaryUser());

    $html = Livewire::test(WhatsNew::class)->call('refreshVersionInfo')->html();

    expect($html)->toContain('<li>')
        ->and($html)->toContain('<strong>nouveauté</strong>')
        // Le symptôme d'avant : les marqueurs Markdown affichés tels quels.
        ->and($html)->not->toContain('### Ajouté');
});

it('strips html injected into release notes', function () {
    fakeGithubReleases('<script>alert(1)</script><img src=x onerror=alert(1)> Notes');
    $this->actingAs(ordinaryUser());

    $html = Livewire::test(WhatsNew::class)->call('refreshVersionInfo')->html();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->not->toContain('onerror=');
});

it('marks which releases are newer than the installed version', function () {
    fakeGithubReleases();
    $this->actingAs(ordinaryUser());

    Livewire::test(WhatsNew::class)
        ->call('refreshVersionInfo')
        ->assertSee('plus récente');
});

// =========================================================================================
// 4. Plus aucun « v0.1 » en dur
// =========================================================================================

it('shows the real version on the system status page', function () {
    $this->actingAs(administrator());

    Livewire::test(SystemStatus::class)
        ->assertOk()
        ->assertSee('OpenLMNP v'.(new UpdateService())->getCurrentVersion())
        ->assertDontSee('OpenLMNP v0.1');
});

it('stamps the tax return pdf with the real version', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $this->actingAs($user);

    Property::forceCreate([
        'user_id' => $user->id,
        'name' => 'Bien liasse',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 50,
        'rented_area' => 50,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);

    $fiscalYear = FiscalYear::forceCreate([
        'user_id' => $user->id,
        'year' => 2025,
        'status' => FiscalYear::STATUS_DRAFT,
    ]);

    // La génération elle-même est l'assertion : `{{ $appVersion }}` est une variable de vue,
    // et DomPDF échoue si le service ne la passe pas. Le flux PDF étant compressé, on ne peut
    // pas y relire la chaîne — la source de la vue, si.
    // renderPdf() rend le PDF en mémoire : la liasse n'est plus écrite sur le disque.
    $pdf = app(TaxReturnService::class)->renderPdf($fiscalYear);

    expect($pdf)->toStartWith('%PDF')
        ->and(file_get_contents(resource_path('views/pdf/tax-return.blade.php')))
        ->toContain('OpenLMNP v{{ $appVersion }}');
});

it('leaves no hardcoded version anywhere in the views', function () {
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));

    foreach ($iterator as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        if (preg_match('/OpenLMNP v\d+\.\d+/', file_get_contents($file->getPathname()))) {
            $offenders[] = str_replace(resource_path('views').'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});
