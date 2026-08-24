<?php

use App\Filament\Pages\SystemStatus;
use App\Models\User;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

// Pest est une dépendance de DÉVELOPPEMENT : les images Docker sont construites avec
// `composer install --no-dev`, donc `vendor/bin/pest` n'y existe pas. Le bouton « Lancer les
// tests » lançait quand même la commande et présentait le « sh: 1: vendor/bin/pest: not found »
// qui en sortait comme un échec des tests (issue #7, remontée par un utilisateur self-hosted).
// Ce n'est pas propre au build multi-étapes : l'ancien Dockerfile avait déjà `--no-dev`.

/** La même page, mais sans binaire Pest — ce que voit une installation Docker. */
function systemStatusWithoutPest(): SystemStatus
{
    return new class extends SystemStatus
    {
        protected function pestBinary(): string
        {
            return '/nonexistent/vendor/bin/pest';
        }
    };
}

it('explains why the suite cannot run when Pest is missing', function () {
    $reason = systemStatusWithoutPest()->testsBlockedReason();

    expect($reason)->toBeString()
        ->and($reason)->toContain('--no-dev')
        ->and($reason)->toContain('vendor/bin/pest');
});

it('never shells out to a Pest binary that is not there', function () {
    Process::fake();

    $page = systemStatusWithoutPest();
    $page->runTests();

    // Le cœur de l'issue : plus de « command not found » présenté comme un test en échec.
    Process::assertNothingRan();
    expect($page->testResults)->toBeNull();
});

it('considers the suite runnable on a development checkout', function () {
    // Le checkout qui exécute ces tests EST un checkout de développement : Pest y est présent.
    expect((new SystemStatus())->testsBlockedReason())->toBeNull();
});

it('renders the tests panel for an admin', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(SystemStatus::class)
        ->assertOk()
        ->assertSee('Tests automatisés');
});
