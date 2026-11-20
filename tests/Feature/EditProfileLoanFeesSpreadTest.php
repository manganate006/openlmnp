<?php

use App\Filament\Pages\EditProfile;
use App\Models\User;
use Livewire\Livewire;

/**
 * L'interrupteur « Étaler les frais d'emprunt » n'a pas de colonne : il se déduit de
 * `loan_fees_spread_from`. Filament n'appliquant pas les default() d'une page d'édition,
 * il s'affichait éteint et le premier enregistrement du profil effaçait l'option (18/11).
 */
it('shows the spread option as enabled and keeps it when the profile is saved for another reason', function () {
    $user = User::factory()->create(['timezone' => 'Europe/Paris', 'loan_fees_spread_from' => 2025]);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->assertSchemaStateSet([
            'loan_fees_spread_enabled' => true,
            'loan_fees_spread_from' => 2025,
        ])
        ->fillForm(['name' => 'Nouveau nom'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->loan_fees_spread_from)->toBe(2025)
        ->and($user->fresh()->name)->toBe('Nouveau nom');
});

it('proposes the current year when the option is switched on', function () {
    $user = User::factory()->create(['timezone' => 'Europe/Paris', 'loan_fees_spread_from' => null]);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->fillForm(['loan_fees_spread_enabled' => true])
        ->assertSchemaStateSet(['loan_fees_spread_from' => now()->year])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->loan_fees_spread_from)->toBe(now()->year);
});

it('never stores year 0 when the year field is left empty', function () {
    $user = User::factory()->create(['timezone' => 'Europe/Paris', 'loan_fees_spread_from' => null]);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->fillForm(['loan_fees_spread_enabled' => true, 'loan_fees_spread_from' => ''])
        ->call('save');

    expect($user->fresh()->loan_fees_spread_from)->toBe(now()->year);
});

it('clears the option when it is switched off', function () {
    $user = User::factory()->create(['timezone' => 'Europe/Paris', 'loan_fees_spread_from' => 2025]);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->fillForm(['loan_fees_spread_enabled' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->loan_fees_spread_from)->toBeNull();
});
