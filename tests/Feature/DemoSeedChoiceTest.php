<?php

use App\Livewire\DemoSeedChoice;
use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Property;
use App\Models\User;
use App\Services\DemoSeedChoiceService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function promotedUser(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'is_demo' => false,
        'demo_expires_at' => null,
        'demo_promoted_at' => Carbon::now(),
        'demo_seed_choice' => null,
    ], $attrs));
}

function aProperty(User $user, string $name): Property
{
    return Property::create([
        'user_id' => $user->id, 'name' => $name,
        'address' => '1 rue du Test', 'city' => 'Lyon', 'postal_code' => '69003',
        'type' => 'apartment', 'total_area' => 45, 'rented_area' => 45,
        'acquisition_date' => '2022-01-01', 'acquisition_price' => 20000000,
        'land_percentage' => 15, 'rental_start_date' => '2022-03-01',
        'rental_type' => 'seasonal', 'is_primary_residence' => false,
    ]);
}

/** Un compte promu : un bien d'exemple + un exercice seedé, un bien saisi + un exercice à lui. */
function promotedWithBoth(): array
{
    $user = promotedUser();
    $sample = aProperty($user, 'Villa Les Oliviers');
    $mine = aProperty($user, 'Mon studio');

    $seededYear = FiscalYear::create(['user_id' => $user->id, 'year' => 2023, 'status' => 'closed']);
    $ownYear = FiscalYear::create(['user_id' => $user->id, 'year' => 2024, 'status' => 'draft']);

    $user->forceFill(['demo_seed' => [
        'property_id' => $sample->id,
        'fiscal_year_ids' => [$seededYear->id],
    ]])->save();

    test()->actingAs($user);

    return compact('user', 'sample', 'mine', 'seededYear', 'ownYear');
}

it('stays hidden for an account that was never promoted', function () {
    $this->actingAs(User::factory()->create(['demo_promoted_at' => null]));

    Livewire::test(DemoSeedChoice::class)->assertSet('applies', false)->assertDontSee('<style>', false);
});

it('stays hidden once the choice has been made', function () {
    $this->actingAs(promotedUser(['demo_seed_choice' => DemoSeedChoiceService::KEEP_ALL]));

    Livewire::test(DemoSeedChoice::class)->assertSet('applies', false);
});

it('keeps only what the visitor typed, and drops the sample', function () {
    ['user' => $user, 'sample' => $sample, 'mine' => $mine, 'seededYear' => $seeded, 'ownYear' => $own] = promotedWithBoth();

    Livewire::test(DemoSeedChoice::class)
        ->assertSet('applies', true)
        ->assertSet('choice', DemoSeedChoiceService::MINE_ONLY)   // présélection
        ->call('apply');

    expect(Property::withoutGlobalScopes()->find($sample->id))->toBeNull()
        ->and(Property::withoutGlobalScopes()->find($mine->id))->not->toBeNull()
        ->and(FiscalYear::withoutGlobalScopes()->find($seeded->id))->toBeNull()
        ->and(FiscalYear::withoutGlobalScopes()->find($own->id))->not->toBeNull()
        ->and($user->fresh()->demo_seed_choice)->toBe(DemoSeedChoiceService::MINE_ONLY);
});

it('recomputes the totals of the years that survive', function () {
    // ⚠️ Les exercices sont au niveau UTILISATEUR, pas du bien, et leurs totaux sont figés
    // en base. Supprimer le bien d'exemple sans recalculer laisserait l'exercice restant
    // sur des montants qui incluent encore ses recettes — des chiffres faux, dans une
    // comptabilité, présentés comme justes.
    ['user' => $user, 'sample' => $sample, 'ownYear' => $ownYear] = promotedWithBoth();

    Income::create([
        'property_id' => $sample->id, 'income_date' => '2024-06-01', 'amount' => 500000,
        'platform_fee' => 0, 'tourist_tax' => 0,
        'source' => 'airbnb', 'guest_name' => 'Exemple',
    ]);

    $ownYear->update(['total_income' => 500000]);

    Livewire::test(DemoSeedChoice::class)->call('apply');

    expect($ownYear->fresh()->total_income)->toBe(0);
});

it('wipes everything when the visitor asks to start over', function () {
    ['user' => $user, 'sample' => $sample, 'mine' => $mine] = promotedWithBoth();

    Livewire::test(DemoSeedChoice::class)
        ->set('choice', DemoSeedChoiceService::RESET)
        ->call('apply');

    expect(Property::withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe(0)
        ->and(FiscalYear::withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe(0);
});

it('touches nothing when the visitor keeps everything', function () {
    ['user' => $user, 'sample' => $sample, 'mine' => $mine] = promotedWithBoth();

    Livewire::test(DemoSeedChoice::class)
        ->set('choice', DemoSeedChoiceService::KEEP_ALL)
        ->call('apply');

    expect(Property::withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe(2)
        ->and($user->fresh()->demo_seed_choice)->toBe(DemoSeedChoiceService::KEEP_ALL);
});

it('refuses a second choice, and an unknown one', function () {
    ['user' => $user] = promotedWithBoth();
    $service = app(DemoSeedChoiceService::class);

    expect($service->apply($user, 'efface-tout'))->toBeFalse()
        ->and($user->fresh()->demo_seed_choice)->toBeNull();

    expect($service->apply($user, DemoSeedChoiceService::KEEP_ALL))->toBeTrue();

    // Le choix ne se fait qu'une fois : un second appel ne doit RIEN détruire.
    expect($service->apply($user->fresh(), DemoSeedChoiceService::RESET))->toBeFalse()
        ->and(Property::withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe(2);
});

it('emits the analytics event the GTM wiring expects', function () {
    // Même raison que dans DemoExpiryPromptTest : le tag `GA4 App - demo_seed_choice`
    // forwarde `demo_choice`, et un renommage silencieux viderait le rapport.
    promotedWithBoth();

    Livewire::test(DemoSeedChoice::class)
        ->set('choice', DemoSeedChoiceService::KEEP_ALL)
        ->call('apply')
        ->assertDispatched('analytics', fn ($e, $p) => $p[0]['event'] === 'demo_seed_choice'
            && $p[0]['demo_choice'] === 'keep_all');
});

it('announces the real counts on each option', function () {
    promotedWithBoth();

    $c = Livewire::test(DemoSeedChoice::class);

    expect($c->instance()->summaryFor(DemoSeedChoiceService::MINE_ONLY))
        ->toBe('Supprime 1 bien et 1 exercice · conserve 1 bien et 1 exercice')
        ->and($c->instance()->summaryFor(DemoSeedChoiceService::KEEP_ALL))
        ->toBe('Conserve 2 biens et 2 exercices')
        ->and($c->instance()->summaryFor(DemoSeedChoiceService::RESET))
        ->toBe('Supprime 2 biens et 2 exercices');
});
