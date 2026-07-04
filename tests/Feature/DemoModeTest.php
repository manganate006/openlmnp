<?php

use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Loan;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

// === MODE DÉSACTIVÉ ===

it('returns 404 on /demo when demo mode is disabled', function () {
    config(['demo.enabled' => false]);

    $this->get('/demo')->assertNotFound();
});

// === MODE ACTIVÉ : création d'un compte éphémère ===

it('creates an ephemeral demo user, logs in and redirects when enabled', function () {
    config(['demo.enabled' => true, 'demo.ttl_hours' => 24, 'demo.max_accounts' => 200]);

    expect(User::query()->where('is_demo', true)->count())->toBe(0);

    $response = $this->get('/demo');

    $response->assertRedirect('/');

    $demoUser = User::query()->where('is_demo', true)->first();
    expect($demoUser)->not->toBeNull();
    expect($demoUser->is_demo)->toBeTrue();
    expect($demoUser->demo_expires_at)->not->toBeNull();
    expect(Auth::check())->toBeTrue();
    expect(Auth::id())->toBe($demoUser->id);
});

it('creates two distinct isolated demo users for two visits', function () {
    config(['demo.enabled' => true]);

    // Premier visiteur
    $this->get('/demo')->assertRedirect('/');
    $this->flushSession();
    Auth::logout();

    // Second visiteur (nouvelle session)
    $this->get('/demo')->assertRedirect('/');

    $demoUsers = User::query()->where('is_demo', true)->get();
    expect($demoUsers)->toHaveCount(2);
    expect($demoUsers[0]->id)->not->toBe($demoUsers[1]->id);
    expect($demoUsers[0]->email)->not->toBe($demoUsers[1]->email);
});

it('seeds isolated demo data visible only to its own demo user', function () {
    config(['demo.enabled' => true]);

    $this->get('/demo')->assertRedirect('/');

    $userA = User::query()->where('is_demo', true)->first();

    // Le visiteur A voit son propre bien fictif (Villa Les Oliviers).
    $countA = Property::withoutGlobalScopes()->where('user_id', $userA->id)->count();
    expect($countA)->toBe(1);

    // Un second visiteur obtient sa PROPRE copie, indépendante.
    $this->flushSession();
    Auth::logout();
    $this->get('/demo')->assertRedirect('/');

    $userB = User::query()->where('is_demo', true)->where('id', '!=', $userA->id)->first();
    $countB = Property::withoutGlobalScopes()->where('user_id', $userB->id)->count();
    expect($countB)->toBe(1);

    // Isolation : le bien de A n'appartient pas à B (et vice-versa).
    $propA = Property::withoutGlobalScopes()->where('user_id', $userA->id)->first();
    expect($propA->user_id)->toBe($userA->id);
    expect($propA->user_id)->not->toBe($userB->id);

    // Chaque visiteur a exactement 1 bien : aucun mélange des données.
    expect(Property::withoutGlobalScopes()->count())->toBe(2);
});

it('seeds a realistic and complete demo dataset', function () {
    config(['demo.enabled' => true]);

    $this->get('/demo')->assertRedirect('/');

    $user = User::query()->where('is_demo', true)->first();
    $property = Property::withoutGlobalScopes()->where('user_id', $user->id)->first();
    $currentYear = (int) date('Y');

    // Un seul emprunt, rattaché au bien copié (pas de property_id orphelin).
    $loans = Loan::withoutGlobalScopes()->where('property_id', $property->id)->get();
    expect($loans)->toHaveCount(1)
        ->and(Loan::withoutGlobalScopes()->count())->toBe(1)
        ->and($loans->first()->property_id)->toBe($property->id);

    // Recettes de 2022 à l'année en cours, charges chaque année.
    $incomeYears = Income::withoutGlobalScopes()
        ->where('property_id', $property->id)
        ->selectRaw("DISTINCT strftime('%Y', income_date) as y")
        ->pluck('y')->map(fn ($y) => (int) $y)->sort()->values();
    expect($incomeYears->first())->toBe(2022);

    $expenseYears = Expense::withoutGlobalScopes()
        ->where('property_id', $property->id)
        ->selectRaw("DISTINCT strftime('%Y', expense_date) as y")
        ->pluck('y')->map(fn ($y) => (int) $y)->sort()->values();
    expect($expenseYears->all())->toBe(range(2022, $currentYear));

    // Chaîne d'exercices fiscaux 2022 → N-1, tous clôturés.
    $fiscalYears = FiscalYear::withoutGlobalScopes()
        ->where('user_id', $user->id)
        ->orderBy('year')
        ->get();
    expect($fiscalYears->pluck('year')->all())->toBe(range(2022, $currentYear - 1))
        ->and($fiscalYears->every(fn ($fy) => $fy->status === FiscalYear::STATUS_CLOSED))->toBeTrue();

    // Les reports d'amortissements se propagent le long de la chaîne.
    $chained = $fiscalYears->skip(1)->every(
        fn ($fy) => (int) $fy->previous_deferred === (int) $fiscalYears->firstWhere('year', $fy->year - 1)->deferred_depreciation
    );
    expect($chained)->toBeTrue();
});

// === LIMITE DE COMPTES ===

it('returns 503 when max demo accounts is reached and none expired', function () {
    config(['demo.enabled' => true, 'demo.max_accounts' => 1, 'demo.ttl_hours' => 24]);

    // Un compte démo actif occupe déjà la seule place disponible.
    User::factory()->create([
        'is_demo' => true,
        'demo_expires_at' => now()->addHours(24),
    ]);

    $this->get('/demo')->assertStatus(503);
});

// === NETTOYAGE ===

it('cleanup command removes expired demo users and their properties but keeps real users', function () {
    // Compte réel : ne doit JAMAIS être supprimé.
    $realUser = User::factory()->create([
        'is_demo' => false,
        'demo_expires_at' => null,
    ]);
    Property::withoutGlobalScopes()->create([
        'user_id' => $realUser->id,
        'name' => 'Bien réel',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 50,
        'rented_area' => 50,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 1600000,
        'land_percentage' => 15,
        'rental_start_date' => '2020-06-01',
        'rental_type' => 'long_term',
        'is_primary_residence' => false,
    ]);

    // Compte démo expiré : doit être supprimé avec ses données.
    $expiredDemo = User::factory()->create([
        'is_demo' => true,
        'demo_expires_at' => now()->subHour(),
    ]);
    $expiredProperty = Property::withoutGlobalScopes()->create([
        'user_id' => $expiredDemo->id,
        'name' => 'Bien démo expiré',
        'address' => '2 rue Demo',
        'city' => 'Lyon',
        'postal_code' => '69001',
        'type' => 'studio',
        'total_area' => 30,
        'rented_area' => 30,
        'acquisition_date' => '2021-01-01',
        'acquisition_price' => 15000000,
        'notary_fees' => 1200000,
        'land_percentage' => 15,
        'rental_start_date' => '2021-06-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);

    // Compte démo encore actif : doit être conservé.
    $activeDemo = User::factory()->create([
        'is_demo' => true,
        'demo_expires_at' => now()->addHours(5),
    ]);

    $this->artisan('openlmnp:demo-cleanup')->assertSuccessful();

    // Le compte démo expiré et son bien ont disparu.
    expect(User::query()->find($expiredDemo->id))->toBeNull();
    expect(Property::withoutGlobalScopes()->find($expiredProperty->id))->toBeNull();

    // Le compte réel et son bien sont intacts.
    expect(User::query()->find($realUser->id))->not->toBeNull();
    expect(Property::withoutGlobalScopes()->where('user_id', $realUser->id)->count())->toBe(1);

    // Le compte démo actif est conservé.
    expect(User::query()->find($activeDemo->id))->not->toBeNull();
});
