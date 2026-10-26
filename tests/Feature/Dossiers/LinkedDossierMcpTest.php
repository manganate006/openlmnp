<?php

use App\Enums\AccountKind;
use App\Enums\DossierMemberRole;
use App\Mcp\OpenLmnpServer;
use App\Models\LinkedDossier;
use App\Models\McpAuditLog;
use App\Models\Property;
use App\Models\User;
use App\Support\McpDemo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Server\Transport\FakeTransporter;

/**
 * Lot 5 des dossiers liés : jeton MCP rattaché à un dossier (voir
 * `_admin/docs/plan-dossiers-lies-2026-10-14.md` § 3, option C retenue).
 *
 * `creerDossierLie()` : fonction globale Pest, voir LinkedDossierSwitchSecurityTest.php.
 * `appelMcpFrais()`/`resultatMcp()` : fonctions globales Pest, voir SecuriteSuiteTest.php.
 */
function proprieteMcpDe(User $user, string $suffixe): Property
{
    return Property::forceCreate([
        'user_id' => $user->id, 'name' => "Bien {$suffixe}", 'address' => '1 rue du Test',
        'city' => 'Lyon', 'postal_code' => '69003', 'type' => 'apartment',
        'total_area' => 45, 'rented_area' => 45, 'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000, 'land_percentage' => 15,
        'rental_start_date' => '2022-03-01', 'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
}

/** Jeton MCP du $principal, rattaché au dossier $dossier (bypass McpTokens, direct en base). */
function tokenDossier(User $principal, LinkedDossier $dossier, string $nom = 'jeton-dossier'): string
{
    $token = $principal->createToken($nom);
    $token->accessToken->update(['dossier_user_id' => $dossier->user_id]);

    return $token->plainTextToken;
}

/**
 * `appelMcpFrais()` simule une VRAIE requête HTTP, mais à l'intérieur d'un seul test
 * Pest, l'instance de garde Sanctum (`Illuminate\Auth\RequestGuard`) reste mise
 * en cache d'un appel à l'autre (même conteneur applicatif pour tout le test) —
 * alors qu'en production chaque requête est un script PHP neuf (pas d'Octane
 * ici, voir docker-entrypoint.sh). Sans `Auth::forgetGuards()` entre deux appels
 * simulés dans un même test, le second verrait l'identité BASCULÉE par McpGuard
 * à la fin du premier (`Auth::setUser($dossierUser)`) au lieu de revalider le
 * jeton Sanctum depuis l'en-tête Authorization — un faux négatif du HARNAIS de
 * test, pas un comportement de l'application (qui ne persiste jamais l'identité
 * d'une requête à l'autre).
 */
function appelMcpFrais(string $token, string $outil, array $arguments = []): Illuminate\Testing\TestResponse
{
    Auth::forgetGuards();

    return appelMcp($token, $outil, $arguments);
}

beforeEach(function () {
    config([
        'mcp.enabled' => true,
        'dossiers.enabled' => true,
        'services.provisioning.token' => null,
        'dossiers.quota' => null,
    ]);
});

// === ISOLATION : un jeton de dossier ne voit QUE ce dossier ===

it('un jeton du dossier A ne voit ni le dossier B ni le dossier personnel du principal', function () {
    $principal = User::factory()->create(['mcp_enabled' => true]);
    $dossierA = creerDossierLie($principal, '100000009');
    $dossierB = creerDossierLie($principal, '100000017');

    proprieteMcpDe($principal, 'du principal');
    proprieteMcpDe($dossierA->user, 'du dossier A');
    proprieteMcpDe($dossierB->user, 'du dossier B');

    $jeton = tokenDossier($principal, $dossierA);

    $reponse = appelMcpFrais($jeton, 'list_properties');
    $reponse->assertOk();
    $resultat = resultatMcp($reponse);

    expect($resultat['count'])->toBe(1)
        ->and($resultat['properties'][0]['name'])->toBe('Bien du dossier A');
});

it('un jeton personnel ne voit pas les biens d\'un dossier lié', function () {
    $principal = User::factory()->create(['mcp_enabled' => true]);
    $dossier = creerDossierLie($principal, '100000009');

    proprieteMcpDe($principal, 'du principal');
    proprieteMcpDe($dossier->user, 'du dossier');

    $jetonPersonnel = $principal->createToken('perso')->plainTextToken;

    $reponse = appelMcpFrais($jetonPersonnel, 'list_properties');
    $reponse->assertOk();
    $resultat = resultatMcp($reponse);

    expect($resultat['count'])->toBe(1)
        ->and($resultat['properties'][0]['name'])->toBe('Bien du principal');
});

// === REVÉRIFICATION À CHAQUE APPEL, ERREUR EXPLICITE, JAMAIS DE REPLI ===

it('un membre révoqué en cours de route coupe le jeton dès l\'appel suivant, avec une erreur explicite', function () {
    $principal = User::factory()->create(['mcp_enabled' => true]);
    $dossier = creerDossierLie($principal, '100000009');
    proprieteMcpDe($dossier->user, 'du dossier');
    proprieteMcpDe($principal, 'du principal');

    $jeton = tokenDossier($principal, $dossier);

    // Premier appel : encore membre, ça marche.
    appelMcpFrais($jeton, 'list_properties')->assertOk();

    $dossier->members()->where('user_id', $principal->id)->update(['revoked_at' => now()]);

    $reponse = appelMcpFrais($jeton, 'list_properties');
    $reponse->assertStatus(403);
    expect($reponse->json('error.message'))->toContain('plus membre')
        // Jamais un repli silencieux : aucune donnée du principal ne transparaît.
        ->and($reponse->json('result'))->toBeNull();
});

it('un dossier archivé coupe le jeton dès l\'appel suivant, avec une erreur explicite', function () {
    $principal = User::factory()->create(['mcp_enabled' => true]);
    $dossier = creerDossierLie($principal, '100000009');
    $jeton = tokenDossier($principal, $dossier);

    appelMcpFrais($jeton, 'list_properties')->assertOk();

    $dossier->update(['archived_at' => now()]);

    $reponse = appelMcpFrais($jeton, 'list_properties');
    $reponse->assertStatus(403);
    expect($reponse->json('error.message'))->toContain('archivé');
});

it('le journal d\'audit enregistre l\'échec, avec user_id = principal et dossier_user_id = dossier', function () {
    $principal = User::factory()->create(['mcp_enabled' => true]);
    $dossier = creerDossierLie($principal, '100000009');
    $jeton = tokenDossier($principal, $dossier);

    $dossier->update(['archived_at' => now()]);

    appelMcpFrais($jeton, 'list_properties')->assertStatus(403);

    $entree = McpAuditLog::latest('id')->first();

    expect($entree->user_id)->toBe($principal->id)
        ->and($entree->dossier_user_id)->toBe($dossier->user_id)
        ->and($entree->result_status)->toBe('error')
        ->and($entree->tool_name)->toBe('list_properties');
});

/**
 * Contre-épreuve sur la revérification d'appartenance et l'absence de repli,
 * jouée manuellement (`App\Http\Middleware\McpGuard::handle()` — commenter le
 * bloc "Jeton rattaché à un dossier lié" fait échouer CE test précis : sans la
 * garde, le PRINCIPAL reste l'identité authentifiée — `Auth::setUser()` n'a
 * jamais basculé vers le dossier — et `create_expense` s'exécute quand même,
 * silencieusement, dans le dossier PERSONNEL du principal plutôt que de
 * refuser. Vérifié, remis en place aussitôt après.
 * Conservé en suite comme garde-fou permanent, même patron que la contre-épreuve
 * du MCP local dans LinkedDossierIsolationTest.php.
 */
it('CONTRE-ÉPREUVE : un jeton de dossier archivé refuse explicitement, et n\'écrit JAMAIS dans le dossier personnel', function () {
    $principal = User::factory()->create(['mcp_enabled' => true]);
    $dossier = creerDossierLie($principal, '100000009');
    $bienDossier = proprieteMcpDe($dossier->user, 'du dossier');
    $jeton = tokenDossier($principal, $dossier);

    $dossier->update(['archived_at' => now()]);

    $reponse = appelMcpFrais($jeton, 'create_expense', [
        'property_id' => $bienDossier->id,
        'expense_date' => '2025-03-01',
        'amount' => 100,
        'category' => 'insurance',
        'description' => 'Tentative après archivage',
    ]);

    $reponse->assertStatus(403);
    expect($reponse->json('result'))->toBeNull()
        // Rien n'a été écrit nulle part — ni dans le dossier (refusé), ni,
        // surtout, dans le dossier PERSONNEL du principal (le repli interdit).
        ->and(\App\Models\Expense::withoutGlobalScopes()->count())->toBe(0);
});

// === COMPTE MEMBRE : anti-détournement, perte d'accès quand plus aucun abonné actif ===
// (c'est la manifestation concrète d'un dossier « fermé », LinkedDossier::isClosed(),
// pour un compte appelant : voir le commentaire de cette méthode — un compte STANDARD
// qui tient encore son propre jeton ne peut, par construction, jamais découvrir son
// dossier fermé puisqu'il en serait lui-même l'acting owner ; seul un compte MEMBRE
// peut perdre l'accès sans que SA PROPRE ligne n'ait bougé.)

it('un compte membre perd son jeton de dossier si plus aucun abonné actif ne reste (dossier fermé)', function () {
    $createur = User::factory()->create();
    $dossier = creerDossierLie($createur, '100000009');

    $membre = User::factory()->create(['mcp_enabled' => true, 'account_kind' => AccountKind::Member]);
    $dossier->members()->create([
        'user_id' => $membre->id,
        'role' => DossierMemberRole::Member,
        'accepted_at' => now(),
    ]);

    $jeton = tokenDossier($membre, $dossier);

    // Le créateur (seul backer non-membre) est encore actif : ça marche.
    appelMcpFrais($jeton, 'list_properties')->assertOk();
    expect($dossier->fresh()->isClosed())->toBeFalse();

    // Le créateur résilie : plus aucun backer non-membre actif → dossier FERMÉ.
    $createur->forceFill(['suspended_at' => now()])->save();
    expect($dossier->fresh()->isClosed())->toBeTrue();

    $reponse = appelMcpFrais($jeton, 'list_properties');
    $reponse->assertStatus(403);
    expect($reponse->json('error.message'))->toContain('abonné ou en compte standard actif');
});

// === SUSPENSION DU PRINCIPAL : refusée AVANT toute bascule ===

it('un principal suspendu est refusé avant toute bascule vers le dossier', function () {
    $principal = User::factory()->create(['mcp_enabled' => true]);
    $dossier = creerDossierLie($principal, '100000009');
    $jeton = tokenDossier($principal, $dossier);

    appelMcpFrais($jeton, 'list_properties')->assertOk();

    $principal->forceFill(['suspended_at' => now()])->save();

    // Le 403 de compte suspendu (contrôle EXISTANT, en tête de McpGuard) tombe
    // avant même de regarder `dossier_user_id` : jamais de bascule pour un
    // principal suspendu, quel que soit l'état du dossier par ailleurs. Une API
    // par jeton n'a pas de notion de « déconnexion » (contrairement au web) :
    // l'identité authentifiée RESTE le principal — ce qui compte est qu'elle
    // n'ait JAMAIS basculé vers le dossier.
    appelMcpFrais($jeton, 'list_properties')->assertForbidden();

    expect(Auth::id())->toBe($principal->id);
});

// === LIMITE DE DÉBIT : comptée sur le PRINCIPAL, pas sur le dossier ===

it('la limite de débit MCP est comptée sur le principal, même avec un jeton de dossier', function () {
    config(['mcp.rate_limit' => 2]);

    $principal = User::factory()->create(['mcp_enabled' => true]);
    $dossier = creerDossierLie($principal, '100000009');
    $jeton = tokenDossier($principal, $dossier);

    RateLimiter::clear('mcp-user:' . $principal->id);

    appelMcpFrais($jeton, 'list_properties')->assertOk();
    appelMcpFrais($jeton, 'list_properties')->assertOk();
    appelMcpFrais($jeton, 'list_properties')->assertStatus(429);
});

// === Défaut gênant n°9 (2026-10-15) : sélecteur « Pour » ouvert VIDE ===

it('présélectionne « Mon dossier personnel » dans le sélecteur « Pour »', function () {
    config(['mcp.enabled' => true]);
    $principal = User::factory()->create(['mcp_enabled' => true]);
    $dossier = creerDossierLie($principal);

    \Livewire\Livewire::actingAs($principal)
        ->test(\App\Filament\Pages\McpTokens::class)
        ->mountAction('createToken')
        ->assertActionDataSet(['dossier_public_id' => 'personal']);
});

// === DÉMO PUBLIQUE : jamais de jeton de dossier ===

it('la démo publique ne peut pas créer de jeton de dossier', function () {
    config(['mcp.demo.enabled' => true, 'mcp.demo.email' => 'demo@openlmnp.fr']);

    $demo = User::factory()->create(['email' => 'demo@openlmnp.fr', 'mcp_enabled' => true]);
    $dossier = creerDossierLie($demo, '100000009');

    expect(McpDemo::isDemoUser($demo))->toBeTrue();

    \Livewire\Livewire::actingAs($demo)
        ->test(\App\Filament\Pages\McpTokens::class)
        ->assertActionHidden('createToken');
});

it('même rattaché en base par erreur, un jeton démo ne bascule jamais vers un dossier (sécurité en profondeur)', function () {
    config(['mcp.demo.enabled' => true, 'mcp.demo.email' => 'demo@openlmnp.fr']);

    $demo = User::factory()->create(['email' => 'demo@openlmnp.fr', 'mcp_enabled' => true]);
    $dossier = creerDossierLie($demo, '100000009');
    proprieteMcpDe($demo, 'du principal démo');
    proprieteMcpDe($dossier->user, 'du dossier');

    // Un jeton démo rattaché malgré tout à un dossier (ne devrait jamais arriver
    // via l'UI, cf. test ci-dessus) : McpGuard doit l'ignorer purement et
    // simplement pour toute requête reconnue comme démo.
    $jeton = tokenDossier($demo, $dossier, 'demo-public-readonly');

    $reponse = appelMcpFrais($jeton, 'list_properties');
    $reponse->assertOk();
    $resultat = resultatMcp($reponse);

    expect($resultat['properties'][0]['name'])->toBe('Bien du principal démo');
});

// === LE MCP LOCAL CONTINUE DE RÉSOUDRE LE COMPTE RÉEL (non-régression) ===

it('le MCP local continue de résoudre le compte réel quand un dossier lié porte un jeton', function () {
    $principal = User::factory()->create(['is_demo' => false, 'mcp_enabled' => true]);
    $dossier = creerDossierLie($principal, '100000009');
    tokenDossier($principal, $dossier);

    expect(Auth::check())->toBeFalse();

    $server = new OpenLmnpServer(new FakeTransporter);
    $boot = new ReflectionMethod($server, 'boot');
    $boot->setAccessible(true);
    $boot->invoke($server);

    expect(Auth::check())->toBeTrue()
        ->and(Auth::id())->toBe($principal->id);
});
