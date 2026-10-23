<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\SecurityAdvisoryService;
use Illuminate\Support\Facades\Http;

// Canal d'avis de sécurité porté par la réponse du check-in de télémétrie (1.6.11).

const CHECKIN_URL = 'https://telemetry.test/api/instances/checkin';

beforeEach(function () {
    config([
        'services.telemetry.enabled' => true,
        'services.telemetry.url' => CHECKIN_URL,
        'services.telemetry.install_type' => 'selfhosted',
    ]);
});

function advisory(array $overrides = []): array
{
    return array_merge([
        'id' => 'php-cve-2026-109618',
        'severity' => 'critical',
        'title' => 'Faille critique de PHP',
        'message' => 'Mettez à jour votre instance.',
        'url' => 'https://openlmnp.fr/blog/faille-php',
        'affected_from' => '0',
        'fixed_in' => '1.6.11',
    ], $overrides);
}

function storedAdvisories(): ?array
{
    $raw = Setting::get(SecurityAdvisoryService::SETTING_KEY);

    return $raw === null ? null : json_decode($raw, true);
}

function seedAdvisories(array $advisories): void
{
    Setting::set(SecurityAdvisoryService::SETTING_KEY, json_encode([
        'received_at' => now()->toIso8601String(),
        'advisories' => $advisories,
    ]));
}

// === RÉCEPTION ===

it('stores a valid advisory with its reception date', function () {
    Http::fake([CHECKIN_URL => Http::response(['status' => 'ok', 'advisories' => [advisory()]])]);

    $this->artisan('app:instance-checkin')->assertSuccessful();

    $stored = storedAdvisories();
    expect($stored['received_at'])->not->toBeEmpty()
        ->and($stored['advisories'])->toHaveCount(1)
        ->and($stored['advisories'][0])->toBe(advisory());
});

it('defaults affected_from to 0 when missing', function () {
    $entry = advisory();
    unset($entry['affected_from']);
    Http::fake([CHECKIN_URL => Http::response(['status' => 'ok', 'advisories' => [$entry]])]);

    $this->artisan('app:instance-checkin')->assertSuccessful();

    expect(storedAdvisories()['advisories'][0]['affected_from'])->toBe('0');
});

it('ignores invalid entries', function () {
    Http::fake([CHECKIN_URL => Http::response(['status' => 'ok', 'advisories' => [
        advisory(['id' => 'Invalid_ID']),
        advisory(['id' => str_repeat('a', 65)]),
        advisory(['title' => str_repeat('t', 151)]),
        advisory(['message' => str_repeat('m', 601)]),
        advisory(['severity' => 'urgent']),
        advisory(['fixed_in' => 'v1.6.11']),
        advisory(['fixed_in' => null]),
        advisory(['affected_from' => '1.x']),
        advisory(['title' => ['array']]),
        'not-an-object',
        advisory(['id' => 'valide']),
    ]])]);

    $this->artisan('app:instance-checkin')->assertSuccessful();

    $advisories = storedAdvisories()['advisories'];
    expect($advisories)->toHaveCount(1)
        ->and($advisories[0]['id'])->toBe('valide');
});

it('keeps the advisory but drops a link that is not https on a trusted host', function (string $url) {
    Http::fake([CHECKIN_URL => Http::response(['advisories' => [advisory(['url' => $url])]])]);

    $this->artisan('app:instance-checkin')->assertSuccessful();

    $advisories = storedAdvisories()['advisories'];
    expect($advisories)->toHaveCount(1)
        ->and($advisories[0]['url'])->toBeNull();
})->with([
    'http' => 'http://openlmnp.fr/blog/faille-php',
    'hôte hors liste' => 'https://evil.example/blog',
    'sous-domaine piège' => 'https://openlmnp.fr.evil.example/blog',
    'identifiants' => 'https://openlmnp.fr@evil.example/blog',
    'javascript' => 'javascript:alert(1)',
]);

it('accepts github.com and www.openlmnp.fr links', function () {
    Http::fake([CHECKIN_URL => Http::response(['advisories' => [
        advisory(['id' => 'a', 'url' => 'https://github.com/manganate006/openlmnp/security/advisories/GHSA-x']),
        advisory(['id' => 'b', 'url' => 'https://www.openlmnp.fr/blog/x']),
    ]])]);

    $this->artisan('app:instance-checkin')->assertSuccessful();

    expect(collect(storedAdvisories()['advisories'])->pluck('url')->filter())->toHaveCount(2);
});

it('keeps at most ten advisories', function () {
    $many = array_map(fn ($i) => advisory(['id' => "avis-{$i}"]), range(1, 15));
    Http::fake([CHECKIN_URL => Http::response(['advisories' => $many])]);

    $this->artisan('app:instance-checkin')->assertSuccessful();

    expect(storedAdvisories()['advisories'])->toHaveCount(10);
});

it('leaves stored advisories untouched when the response has no advisories key', function () {
    seedAdvisories([advisory()]);
    Http::fake([CHECKIN_URL => Http::response(['status' => 'ok'])]);

    $this->artisan('app:instance-checkin')->assertSuccessful();

    expect(storedAdvisories()['advisories'])->toHaveCount(1);
});

it('clears stored advisories when the key is present but empty', function () {
    seedAdvisories([advisory()]);
    Http::fake([CHECKIN_URL => Http::response(['status' => 'ok', 'advisories' => []])]);

    $this->artisan('app:instance-checkin')->assertSuccessful();

    expect(storedAdvisories()['advisories'])->toBe([]);
});

it('keeps stored advisories on an HTTP error', function () {
    seedAdvisories([advisory()]);
    Http::fake([CHECKIN_URL => Http::response(['advisories' => []], 500)]);

    $this->artisan('app:instance-checkin')->assertSuccessful();

    expect(storedAdvisories()['advisories'])->toHaveCount(1);
});

it('keeps stored advisories on invalid JSON', function () {
    seedAdvisories([advisory()]);
    Http::fake([CHECKIN_URL => Http::response('<html>pas du JSON</html>', 200)]);

    $this->artisan('app:instance-checkin')->assertSuccessful();

    expect(storedAdvisories()['advisories'])->toHaveCount(1);
});

it('keeps stored advisories when the endpoint is unreachable', function () {
    seedAdvisories([advisory()]);
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));

    $this->artisan('app:instance-checkin')->assertSuccessful();

    expect(storedAdvisories()['advisories'])->toHaveCount(1);
});

it('sends nothing and receives no advisory when telemetry is disabled', function () {
    Http::fake();
    config(['services.telemetry.enabled' => false]);

    $this->artisan('app:instance-checkin')->assertSuccessful();

    Http::assertNothingSent();
    expect(storedAdvisories())->toBeNull();
});

// === APPLICABILITÉ ===

it('applies an advisory from affected_from (included) up to fixed_in (excluded)', function () {
    seedAdvisories([advisory(['affected_from' => '1.5.0', 'fixed_in' => '1.6.11'])]);
    $service = new SecurityAdvisoryService();

    expect($service->applicable('1.4.9'))->toBe([])
        ->and($service->applicable('1.5.0'))->toHaveCount(1)
        ->and($service->applicable('1.6.10'))->toHaveCount(1)
        ->and($service->applicable('1.6.11'))->toBe([])
        ->and($service->applicable('2.0.0'))->toBe([]);
});

// === BANDEAU ===

it('shows the banner to an admin while the installed version is affected', function () {
    seedAdvisories([advisory(['title' => 'Titre visible', 'fixed_in' => '999.0'])]);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get('/properties')
        ->assertOk()
        ->assertSee('data-testid="security-advisories"', false)
        ->assertSee('Titre visible')
        ->assertSee('https://openlmnp.fr/blog/faille-php', false)
        ->assertSee("Lire l'avis", false);
});

it('hides the banner once the installed version reaches fixed_in', function () {
    seedAdvisories([advisory(['title' => 'Titre visible', 'fixed_in' => '1.0'])]);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get('/properties')
        ->assertOk()
        ->assertDontSee('data-testid="security-advisories"', false);
});

it('hides the banner when the installed version is below affected_from', function () {
    seedAdvisories([advisory(['affected_from' => '998.0', 'fixed_in' => '999.0'])]);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get('/properties')
        ->assertOk()
        ->assertDontSee('data-testid="security-advisories"', false);
});

it('never shows the banner to a non-admin user', function () {
    seedAdvisories([advisory(['title' => 'Titre visible', 'fixed_in' => '999.0'])]);
    // Le premier compte créé devient administrateur (AppServiceProvider) : on le consomme.
    User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    expect($user->fresh()->isAdmin())->toBeFalse();

    $this->actingAs($user)
        ->get('/properties')
        ->assertOk()
        ->assertDontSee('data-testid="security-advisories"', false)
        ->assertDontSee('Titre visible');
});

it('escapes the title and the message', function () {
    seedAdvisories([advisory([
        'title' => '<script>alert("t")</script>',
        'message' => '<img src=x onerror=alert(1)>',
        'fixed_in' => '999.0',
    ])]);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get('/properties')
        ->assertOk()
        ->assertDontSee('<script>alert("t")</script>', false)
        ->assertDontSee('<img src=x onerror=alert(1)>', false)
        ->assertSee('&lt;script&gt;', false)
        ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false);
});

it('does not render a stored link that fails validation', function () {
    seedAdvisories([advisory(['url' => 'javascript:alert(1)', 'fixed_in' => '999.0'])]);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get('/properties')
        ->assertOk()
        ->assertSee('data-testid="security-advisories"', false)
        ->assertDontSee('javascript:alert(1)', false);
});
