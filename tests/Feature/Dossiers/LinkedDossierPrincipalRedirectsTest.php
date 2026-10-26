<?php

use App\Enums\DossierMemberRole;
use App\Filament\Pages\EditProfile;
use App\Filament\Pages\McpTokens;
use App\Models\AiUsageLog;
use App\Models\LinkedDossier;
use App\Models\User;
use App\Services\Ai\OpenRouter\OpenRouterOauth;
use App\Support\AiAccess;
use App\Support\AiQuota;
use App\Support\DossierContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Tableau § 2.3 du plan des dossiers liés : une dizaine d'endroits doivent viser le
 * PRINCIPAL plutôt que le dossier actif, bien que `Auth::user()` reste le dossier
 * pour tout le reste de l'application.
 */
function dossierSansSirene(User $owner, string $siren = '100000009'): LinkedDossier
{
    $dossierUser = new User;
    $dossierUser->forceFill([
        'name' => 'Indivision Redirects',
        'email' => 'dossier-'.Str::lower((string) Str::ulid()).'@dossiers.invalid',
        'password' => Hash::make(Str::random(32)),
        'is_linked_dossier' => true,
        'is_indivision' => true,
        'siren' => $siren,
    ])->save();

    $dossier = LinkedDossier::create([
        'public_id' => (string) Str::ulid(),
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

beforeEach(function () {
    config(['dossiers.enabled' => true, 'services.provisioning.token' => null, 'dossiers.quota' => null]);
});

it('AiAccess résout toujours la clé du PRINCIPAL, jamais celle du dossier actif', function () {
    config(['ai.enabled' => true, 'ai.api_key' => null, 'ai.allow_user_keys' => true]);

    $principal = User::factory()->create();
    $principal->forceFill(['ai_api_key' => 'sk-or-v1-du-principal'])->save();

    $dossier = dossierSansSirene($principal);
    $dossier->user->forceFill(['ai_api_key' => 'sk-or-v1-du-dossier'])->save();

    // La résolution du principal dépend de la session posée par une VRAIE bascule
    // (App\Support\DossierContext::principal()) : sans elle, `is_linked_dossier` seul
    // ne suffit pas à retrouver QUI est le principal.
    $this->actingAs($principal);
    $this->post(route('filament.admin.dossiers.open', $dossier->public_id));

    $access = AiAccess::for(Auth::user());

    expect($access->key())->toBe('sk-or-v1-du-principal')
        ->and($access->user()->id)->toBe($principal->id);
});

it('AiQuota compte les usages sous le même compte, PRINCIPAL, quel que soit le dossier actif', function () {
    $principal = User::factory()->create();
    $dossier = dossierSansSirene($principal);

    AiUsageLog::create([
        'user_id' => $principal->id, 'feature' => 'documents', 'status' => 'ok',
        'prompt_tokens' => 10, 'completion_tokens' => 10, 'created_at' => now(),
    ]);

    config(['ai.docs_monthly_quota' => 1]);

    $this->actingAs($principal);
    $this->post(route('filament.admin.dossiers.open', $dossier->public_id));

    // Vu depuis le dossier : le quota du PRINCIPAL est déjà consommé.
    expect(AiQuota::for(Auth::user(), AiQuota::FEATURE_DOCUMENTS)->allowed())->toBeFalse()
        ->and(AiQuota::for($principal, AiQuota::FEATURE_DOCUMENTS)->allowed())->toBeFalse();
});

it('ferme la page des jetons MCP à un dossier lié', function () {
    config(['mcp.enabled' => true]);
    $principal = User::factory()->create();
    $dossier = dossierSansSirene($principal);
    $dossier->user->forceFill(['mcp_enabled' => true])->save();

    $this->actingAs($dossier->user->fresh());

    expect(McpTokens::canAccess())->toBeFalse();
});

it('refuse la connexion OpenRouter OAuth à un dossier lié', function () {
    config(['ai.enabled' => true, 'ai.oauth_enabled' => true, 'ai.allow_user_keys' => true, 'ai.api_key' => null]);

    $principal = User::factory()->create();
    $dossier = dossierSansSirene($principal);

    expect(OpenRouterOauth::isAvailableFor($dossier->user))->toBeFalse();
});

it('verrouille le SIREN et le caractère indivision du dossier, côté FORMULAIRE (disabled)', function () {
    $principal = User::factory()->create();
    $dossier = dossierSansSirene($principal, '100000009');

    // `disabled()` empêche déjà Filament de déshydrater ces deux champs : une valeur
    // posée par fillForm() n'atteint même pas mutateFormDataBeforeSave(). C'est la
    // PREMIÈRE ligne de défense (confort, voir le commentaire de Filament
    // `CanBeDisabled::disabled()` : contournable côté client JS, d'où la seconde
    // ligne testée séparément ci-dessous).
    Livewire::actingAs($dossier->user->fresh())
        ->test(EditProfile::class)
        ->fillForm([
            'name' => 'Nouveau nom du dossier',
            'siren' => '999999998',
            'is_indivision' => false,
            'timezone' => 'Europe/Paris',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $dossierUser = $dossier->user->fresh();
    expect($dossierUser->siren)->toBe('100000009')
        ->and($dossierUser->is_indivision)->toBeTrue()
        ->and($dossierUser->name)->toBe('Nouveau nom du dossier');
});

/**
 * Seconde ligne de défense, CÔTÉ SERVEUR : `EditProfile::mutateFormDataBeforeSave()`
 * ignore `siren`/`is_indivision` d'un dossier lié quel que soit le contenu de `$data`
 * — y compris si un navigateur manipulé (JS) parvenait à faire parvenir ces clés
 * malgré l'attribut HTML `disabled`, ce que le test précédent ne peut pas simuler
 * (fillForm() ne contourne pas la DÉSHYDRATATION de Filament, seulement l'attribut
 * HTML). On appelle donc la méthode protégée directement, avec un `$data` FORGÉ.
 */
it('verrouille le SIREN et le caractère indivision du dossier, CÔTÉ SERVEUR même si $data est forgé', function () {
    $principal = User::factory()->create();
    $dossier = dossierSansSirene($principal, '100000009');

    $page = Livewire::actingAs($dossier->user->fresh())->test(EditProfile::class)->instance();

    $method = new \ReflectionMethod($page, 'mutateFormDataBeforeSave');
    $method->setAccessible(true);

    $cleaned = $method->invoke($page, [
        'name' => 'Nom forgé',
        'siren' => '999999998',
        'is_indivision' => false,
        'email' => 'attaquant@exemple.test',
        'password' => 'nouveau-mot-de-passe',
        'co_owners' => [],
    ]);

    expect($cleaned)->not->toHaveKey('siren')
        ->not->toHaveKey('is_indivision')
        ->not->toHaveKey('email')
        ->not->toHaveKey('password');
});

// === Contre-épreuve : retirer le bloc `if ($this->getUser()->is_linked_dossier) { unset(...) }`
// de EditProfile::mutateFormDataBeforeSave() fait échouer le test ci-dessus (siren et
// is_indivision changeraient) — vérifié à la main en le commentant puis en relançant ce
// seul test (rouge), avant de le remettre (vert).

it('le mode de navigation se propage au PRINCIPAL et reste identique après une bascule', function () {
    $principal = User::factory()->create(['nav_mode' => \App\Enums\NavMode::Simple]);
    $dossier = dossierSansSirene($principal);

    $this->actingAs($principal);
    $this->post(route('filament.admin.dossiers.open', $dossier->public_id));

    Livewire::test(\App\Livewire\NavModeToggle::class)
        ->call('setMode', 'advanced');

    expect($principal->fresh()->nav_mode)->toBe(\App\Enums\NavMode::Advanced);
});

it('la limite de débit Sirene se partage entre le principal et ses dossiers', function () {
    $principal = User::factory()->create();
    $dossier = dossierSansSirene($principal);

    config(['opendata.sirene.rate_limit' => 1]);
    \Illuminate\Support\Facades\RateLimiter::clear('sirene:'.$principal->id);

    \Illuminate\Support\Facades\Http::fake([
        'recherche-entreprises.api.gouv.fr/*' => \Illuminate\Support\Facades\Http::response([
            'results' => [], 'total_results' => 0,
        ]),
    ]);

    $this->actingAs($principal);
    app(\App\Services\OpenData\SireneClient::class)->lookup('100000033');

    $this->post(route('filament.admin.dossiers.open', $dossier->public_id));

    expect(fn () => app(\App\Services\OpenData\SireneClient::class)->lookup('100000041'))
        ->toThrow(\App\Services\OpenData\SireneUnavailable::class, 'Trop de vérifications');
});
