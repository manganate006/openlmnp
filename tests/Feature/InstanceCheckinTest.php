<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.telemetry.enabled' => true,
        'services.telemetry.url' => 'https://telemetry.test/api/instances/checkin',
        'services.telemetry.install_type' => 'selfhosted',
    ]);
});

it('sends an anonymous check-in with only a uuid, version and install type', function () {
    Http::fake();

    $this->artisan('app:instance-checkin')->assertSuccessful();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://telemetry.test/api/instances/checkin'
            && ! empty($request['instance_id'])
            && ! empty($request['version'])
            && $request['install_type'] === 'selfhosted'
            // Garantie de confidentialité : aucune donnée personnelle/fiscale.
            && ! isset($request['email'])
            && ! isset($request['users'])
            && ! isset($request['properties']);
    });
});

it('reuses a stable anonymous instance id across check-ins', function () {
    Http::fake();

    $this->artisan('app:instance-checkin')->assertSuccessful();
    $first = Setting::get('instance_id');
    expect($first)->not->toBeNull();

    $this->artisan('app:instance-checkin')->assertSuccessful();
    expect(Setting::get('instance_id'))->toBe($first);
});

it('sends nothing when telemetry is disabled', function () {
    Http::fake();
    config(['services.telemetry.enabled' => false]);

    $this->artisan('app:instance-checkin')->assertSuccessful();

    Http::assertNothingSent();
    expect(Setting::get('instance_id'))->toBeNull();
});

it('never fails the command when the endpoint is unreachable', function () {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));

    $this->artisan('app:instance-checkin')->assertSuccessful();
});
