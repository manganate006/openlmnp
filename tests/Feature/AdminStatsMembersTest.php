<?php

use App\Enums\AccountKind;
use App\Filament\Pages\AdminStats;
use App\Models\User;

/**
 * `AdminStats::getUserStats()` compte les comptes « membres » (App\Enums\
 * AccountKind::Member — lot « dossiers membre », décision de Jérémie du
 * 2026-10-15) À PART, pour ne pas gonfler artificiellement la croissance
 * payante de l'instance.
 */
it('counts member accounts separately from the total of real accounts', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    User::factory()->create(['account_kind' => AccountKind::Standard]);
    User::factory()->create(['account_kind' => AccountKind::Member]);
    User::factory()->create(['account_kind' => AccountKind::Member]);
    // Un dossier lié lui-même : jamais compté nulle part ici.
    User::factory()->create(['is_linked_dossier' => true]);

    $page = \Livewire\Livewire::actingAs($admin)->test(AdminStats::class);
    $data = $page->instance()->getStats();

    // admin + standard + 2 membres = 4 comptes réels (le dossier lié est exclu).
    expect($data['users']['total'])->toBe(4)
        ->and($data['users']['members'])->toBe(2);
});

it('shows the admin stats page for an admin, with a members card', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    User::factory()->create(['account_kind' => AccountKind::Member]);

    $this->actingAs($admin)
        ->get('/admin-stats')
        ->assertOk()
        ->assertSee('comptes membres', false);
});
