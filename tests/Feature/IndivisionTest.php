<?php

use App\Filament\Pages\EditProfile;
use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Property;
use App\Models\User;
use App\Services\FiscalYearService;
use App\Services\TaxReturnService;
use App\Support\Indivision;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/**
 * Dossier d'indivision : une liasse au nom de l'indivision, résultat réparti entre
 * indivisaires sur la 2031 bis-SD (cadre E), report de SA quote-part par chacun.
 *
 * Le test qui compte : la somme des quotes-parts retombe EXACTEMENT sur le résultat de la
 * 2031-SD, y compris sur une clé qui ne tombe pas juste (1/3 – 2/3) et sur un déficit.
 */
function indivisionUser(?array $coOwners = null, bool $enabled = true): User
{
    return User::factory()->create([
        'timezone' => 'Europe/Paris',
        'is_indivision' => $enabled,
        'co_owners' => $coOwners ?? [
            ['name' => 'Geoffrey Martin', 'share_bp' => 5000, 'address' => '1 rue A, Lyon'],
            ['name' => 'Claire Durand', 'share_bp' => 5000],
        ],
    ]);
}

function indivisionYear(User $user, int $result): FiscalYear
{
    return FiscalYear::forceCreate([
        'user_id' => $user->id,
        'year' => 2025,
        'status' => FiscalYear::STATUS_CLOSED,
        'total_income' => 1200000,
        'fiscal_result' => $result,
    ]);
}

// === Répartition ===

it('splits a profit 50/50 to the cent', function () {
    expect(Indivision::split(100001, [['share_bp' => 5000], ['share_bp' => 5000]]))->toBe([50000, 50001]);
});

it('keeps the sum exact on a 1/3 – 2/3 key', function () {
    $parts = Indivision::split(100000, [['share_bp' => 3333], ['share_bp' => 6667]]);

    expect($parts)->toBe([33330, 66670])
        ->and(array_sum($parts))->toBe(100000);

    $odd = Indivision::split(77777, [['share_bp' => 3333], ['share_bp' => 3333], ['share_bp' => 3334]]);
    expect(array_sum($odd))->toBe(77777);
});

it('splits a deficit with its sign', function () {
    $parts = Indivision::split(-100001, [['share_bp' => 5000], ['share_bp' => 5000]]);

    expect($parts)->toBe([-50000, -50001])
        ->and(array_sum($parts))->toBe(-100001);
});

it('reads percentages typed the French way', function () {
    expect(Indivision::percentToBasisPoints('50'))->toBe(5000)
        ->and(Indivision::percentToBasisPoints('33,33'))->toBe(3333)
        ->and(Indivision::percentToBasisPoints('12.5 %'))->toBe(1250)
        ->and(Indivision::percentToBasisPoints('abc'))->toBeNull()
        ->and(Indivision::percentToBasisPoints('1,234'))->toBeNull()
        ->and(Indivision::basisPointsToPercent(3333))->toBe('33,33')
        ->and(Indivision::basisPointsToPercent(5000))->toBe('50');
});

it('requires two co-owners whose shares total 100 %', function () {
    expect(Indivision::isComplete([['share_bp' => 10000]]))->toBeFalse()
        ->and(Indivision::isComplete([['share_bp' => 5000], ['share_bp' => 4000]]))->toBeFalse()
        ->and(Indivision::isComplete([['share_bp' => 10000], ['share_bp' => 0]]))->toBeFalse()
        ->and(Indivision::isComplete([['share_bp' => 6000], ['share_bp' => 4000]]))->toBeTrue();
});

// === Liasse ===

it('fills the 2031 bis-SD with each co-owner share of the 2031-SD result', function () {
    $user = indivisionUser();
    $form = app(TaxReturnService::class)->compute2031bis(indivisionYear($user, 812345), $user);

    expect($form['complete'])->toBeTrue()
        ->and(array_column($form['rows'], 'benefice'))->toBe([406172, 406173])
        ->and(array_column($form['rows'], 'deficit'))->toBe([0, 0])
        ->and($form['rows'][0]['bic_type'])->toBe('M')
        ->and($form['rows'][0]['quality'])->toBe('Indivisaire');
});

it('puts a deficit in the deficit column', function () {
    $user = indivisionUser();
    $form = app(TaxReturnService::class)->compute2031bis(indivisionYear($user, -300000), $user);

    expect(array_column($form['rows'], 'deficit'))->toBe([150000, 150000])
        ->and(array_column($form['rows'], 'benefice'))->toBe([0, 0]);
});

it('adds no 2031 bis-SD to an individual file', function () {
    $user = User::factory()->create();

    expect(app(TaxReturnService::class)->compute2031bis(indivisionYear($user, 100000), $user))->toBeNull();
});

it('prints the 2031 bis-SD and one 2042-C-PRO line per co-owner', function () {
    $user = indivisionUser();
    $html = view('pdf.tax-return', app(TaxReturnService::class)->pdfData(indivisionYear($user, 812345)))->render();

    expect($html)->toContain('Formulaire 2031-bis-SD')
        ->and($html)->toContain('Geoffrey Martin')
        ->and($html)->toContain('Claire Durand')
        ->and($html)->toContain('4 061 €')
        ->and($html)->toContain('4 062 €');
});

/**
 * Maquette écran 3, variante P2 : la ligne de total du cadre E, aussi dans le PDF — pas
 * seulement à l'écran — pour prouver que la somme des parts égale le résultat de la 2031-SD.
 */
it('adds a total line to the cadre E of the printed PDF', function () {
    $user = indivisionUser();
    $html = view('pdf.tax-return', app(TaxReturnService::class)->pdfData(indivisionYear($user, 812345)))->render();

    expect($html)->toContain('Total (= 2031-SD, case CB/CC)')
        // 100 % et 8 123 € : la somme des quotes-parts et des montants répartis, à l'euro
        // comme tout formulaire (issue #21).
        ->and($html)->toContain('100 %')
        ->and($html)->toContain('8 123 €');
});

it('leaves the 2031 bis-SD out of an individual file document', function () {
    $user = User::factory()->create();
    $html = view('pdf.tax-return', app(TaxReturnService::class)->pdfData(indivisionYear($user, 812345)))->render();

    expect($html)->not->toContain('2031-bis-SD');
});

it('flags an incomplete split instead of printing wrong amounts', function () {
    $user = indivisionUser([
        ['name' => 'Geoffrey Martin', 'share_bp' => 5000],
        ['name' => 'Claire Durand', 'share_bp' => 4000],
    ]);
    $data = app(TaxReturnService::class)->pdfData(indivisionYear($user, 812345));
    $check = collect($data['checks'])->firstWhere('id', 'indivision');

    expect($data['form2031bis']['complete'])->toBeFalse()
        ->and(array_column($data['form2031bis']['rows'], 'benefice'))->toBe([0, 0])
        ->and($check['status'])->toBe(TaxReturnService::CHECK_ERROR)
        ->and($check['message'])->toContain('90 %');
});

/**
 * Contrôle « orange » : à la différence d'une répartition fausse, une identité incomplète
 * (adresse, naissance) ne fait mentir aucun montant — elle manque seulement à la case
 * « identité » que l'indivisaire recopie à la main sur la 2031 bis-SD. Même logique à trois
 * états que `checkImmobilisations()` : la tolérance d'un reliquat accepté n'est pas une
 * absence de contrôle, c'est un contrôle moins grave qu'une erreur.
 */
it('flags missing address or birth with a warning, not an error, once the split is complete', function () {
    // Fixture par défaut : Geoffrey a une adresse mais pas de naissance, Claire n'a ni l'une
    // ni l'autre — la répartition, elle, est correcte (50/50).
    $user = indivisionUser();
    $data = app(TaxReturnService::class)->pdfData(indivisionYear($user, 812345));
    $check = collect($data['checks'])->firstWhere('id', 'indivision');

    expect($data['form2031bis']['complete'])->toBeTrue()
        ->and($check['status'])->toBe(TaxReturnService::CHECK_WARNING)
        ->and($check['message'])->toContain('2031 bis');
});

/**
 * Libellé de la maquette écran 3, variante B : nommer CHAQUE indivisaire incomplet et ce qui
 * lui manque précisément (adresse, naissance ou les deux), pas seulement un compte — c'est ce
 * qu'il faut aller corriger, et où.
 */
it('names each incomplete co-owner and what is missing for them, in the warning message', function () {
    $user = indivisionUser(); // Geoffrey : naissance manquante. Claire : adresse ET naissance.
    $data = app(TaxReturnService::class)->pdfData(indivisionYear($user, 812345));
    $check = collect($data['checks'])->firstWhere('id', 'indivision');

    expect($check['message'])
        ->toContain('Geoffrey Martin (date et lieu de naissance)')
        ->toContain('Claire Durand (adresse, date et lieu de naissance)');
});

it('clears the warning once every co-owner has an address and a date of birth', function () {
    $user = indivisionUser([
        ['name' => 'Geoffrey Martin', 'share_bp' => 5000, 'address' => '1 rue A, Lyon', 'birth' => 'le 12/03/1985 à Lyon'],
        ['name' => 'Claire Durand', 'share_bp' => 5000, 'address' => '2 rue B, Paris', 'birth' => 'le 01/01/1990 à Paris'],
    ]);
    $data = app(TaxReturnService::class)->pdfData(indivisionYear($user, 812345));
    $check = collect($data['checks'])->firstWhere('id', 'indivision');

    expect($check['status'])->toBe(TaxReturnService::CHECK_OK);
});

it('never lets a missing (optional) tax id trigger the identity warning on its own', function () {
    $user = indivisionUser([
        ['name' => 'Geoffrey Martin', 'share_bp' => 5000, 'address' => '1 rue A, Lyon', 'birth' => 'le 12/03/1985 à Lyon'],
        ['name' => 'Claire Durand', 'share_bp' => 5000, 'address' => '2 rue B, Paris', 'birth' => 'le 01/01/1990 à Paris', 'tax_id' => null],
    ]);
    $data = app(TaxReturnService::class)->pdfData(indivisionYear($user, 812345));
    $check = collect($data['checks'])->firstWhere('id', 'indivision');

    expect($check['status'])->toBe(TaxReturnService::CHECK_OK);
});

// === Micro-BIC ===

/**
 * Simplification du 2026-10-15 (piège P13, réécrit le 2026-10-14) : la question « Chaque
 * indivisaire participe-t-il à la gestion ? » a disparu du profil. Trancher entre « société de
 * fait » (micro-BIC exclu) et simple indivision (micro-BIC avec mise en garde) demandait des
 * faits — qui signe les baux, qui décide des travaux — qu'aucun champ ne peut capturer sans se
 * tromper. Le micro-BIC d'une indivision suit désormais les MÊMES règles que celui d'un dossier
 * individuel, partout dans l'application : ni exclusion, ni mise en garde spécifique.
 */
function microBicComparisonFor(User $user): array
{
    $property = Property::forceCreate([
        'user_id' => $user->id, 'name' => 'Appartement indivis', 'address' => '1 rue A',
        'city' => 'Lyon', 'postal_code' => '69001', 'type' => 'apartment',
        'total_area' => 50, 'rented_area' => 50, 'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000, 'notary_fees' => 0, 'agency_fees' => 0,
        'land_percentage' => 20, 'rental_start_date' => '2023-01-01', 'is_primary_residence' => false,
    ]);
    Income::create([
        'property_id' => $property->id, 'income_date' => '2025-03-01', 'amount' => 1000000,
        'tva_rate' => 0, 'platform_fee' => 0, 'tourist_tax' => 0, 'source' => 'airbnb',
    ]);
    test()->actingAs($user);

    return app(FiscalYearService::class)->compareMicroBicVsReal($user, 2025, '50');
}

it('treats an indivision exactly like an individual file for the micro-BIC', function () {
    $indivision = microBicComparisonFor(indivisionUser());
    $individual = microBicComparisonFor(User::factory()->create(['timezone' => 'Europe/Paris']));

    expect($indivision['available'])->toBe($individual['available'])
        ->and($indivision['unavailable_reason'])->toBe($individual['unavailable_reason'])
        ->and($indivision['warning'])->toBeNull()
        ->and($individual['warning'])->toBeNull();
});

it('never writes the old absolute rule back into the app', function () {
    // Le prompt de l'assistant, lui, CITE la formule pour l'interdire explicitement
    // (`OutputGuardTest` verrouille le refus côté modèle) : seules les fiches d'aide, lues
    // par un humain, ne doivent jamais l'afficher comme un fait.
    expect(file_get_contents(resource_path('views/help/simulator.blade.php')))->not->toContain('plein droit')
        ->and(file_get_contents(resource_path('views/help/assistant.blade.php')))->not->toContain('plein droit');
});

// === Profil ===

it('no longer asks who manages the indivision, nor stores an answer', function () {
    $user = indivisionUser();
    $this->actingAs($user);

    expect(Schema::hasColumn('users', 'indivision_management'))->toBeFalse();

    Livewire::test(EditProfile::class)
        ->assertDontSee('Chaque indivisaire participe-t-il à la gestion');
});

it('explains why address, birth and tax id are asked, on the profile form', function () {
    $user = indivisionUser();
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->assertSee('Demandé sur la 2031 bis');
});

it('shows a live running total of the shares while the split is incomplete', function () {
    $user = User::factory()->create(['timezone' => 'Europe/Paris']);
    $this->actingAs($user);
    $undo = Repeater::fake();

    Livewire::test(EditProfile::class)
        ->fillForm([
            'is_indivision' => true,
            'co_owners' => [
                ['name' => 'Geoffrey Martin', 'share' => '50'],
                ['name' => 'Claire Durand', 'share' => '40'],
            ],
        ])
        ->assertSee('il manque 10 %');

    $undo();
});

it('shows the live total as complete once the shares reach 100 %', function () {
    $user = User::factory()->create(['timezone' => 'Europe/Paris']);
    $this->actingAs($user);
    $undo = Repeater::fake();

    Livewire::test(EditProfile::class)
        ->fillForm([
            'is_indivision' => true,
            'co_owners' => [
                ['name' => 'Geoffrey Martin', 'share' => '50'],
                ['name' => 'Claire Durand', 'share' => '50'],
            ],
        ])
        ->assertSee('Total : 100 %');

    $undo();
});

/**
 * Maquette écran 1, variante B : chaque fiche d'indivisaire porte un badge qui dit ce qui
 * manque pour la 2031 bis (adresse et/ou naissance), jamais le numéro fiscal qui est
 * facultatif — même règle que `TaxReturnService::checkIndivision()`.
 */
it('shows a completion badge counting what is missing for a co-owner, on the profile form', function () {
    $user = User::factory()->create(['timezone' => 'Europe/Paris']);
    $this->actingAs($user);
    $undo = Repeater::fake();

    Livewire::test(EditProfile::class)
        ->fillForm([
            'is_indivision' => true,
            'co_owners' => [
                ['name' => 'Geoffrey Martin', 'share' => '50', 'address' => null, 'birth' => null],
                ['name' => 'Claire Durand', 'share' => '50', 'address' => '2 rue B, Paris', 'birth' => 'le 01/01/1990 à Paris'],
            ],
        ])
        ->assertSee('2 champs à compléter')
        ->assertSee('Complet pour la 2031 bis');

    $undo();
});

it('saves the co-owners from the profile in basis points', function () {
    $user = User::factory()->create(['timezone' => 'Europe/Paris']);
    $this->actingAs($user);
    $undo = Repeater::fake();

    Livewire::test(EditProfile::class)
        ->fillForm([
            'is_indivision' => true,
            'co_owners' => [
                ['name' => 'Geoffrey Martin', 'share' => '33,33', 'address' => null, 'birth' => null, 'tax_id' => null],
                ['name' => 'Claire Durand', 'share' => '66,67', 'address' => null, 'birth' => null, 'tax_id' => '1234 567 890 123'],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $undo();
    $fresh = $user->fresh();

    expect($fresh->is_indivision)->toBeTrue()
        ->and(array_column($fresh->co_owners, 'share_bp'))->toBe([3333, 6667])
        ->and($fresh->co_owners[1]['tax_id'])->toBe('1234567890123');
});

it('refuses shares that do not total 100 %', function () {
    $user = User::factory()->create(['timezone' => 'Europe/Paris']);
    $this->actingAs($user);
    $undo = Repeater::fake();

    Livewire::test(EditProfile::class)
        ->fillForm([
            'is_indivision' => true,
            'co_owners' => [
                ['name' => 'Geoffrey Martin', 'share' => '50'],
                ['name' => 'Claire Durand', 'share' => '40'],
            ],
        ])
        ->call('save')
        ->assertHasFormErrors(['co_owners']);

    $undo();
    expect($user->fresh()->is_indivision)->toBeFalse();
});

it('drops the co-owners when the indivision is switched off', function () {
    $user = indivisionUser();
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->fillForm(['is_indivision' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->is_indivision)->toBeFalse()
        ->and($user->fresh()->co_owners)->toBeNull();
});

// === Écran Télédéclaration ===

it('shows the 2031 bis-SD and each co-owner 2042-C-PRO line on the e-filing screen', function () {
    $user = indivisionUser();
    Property::forceCreate([
        'user_id' => $user->id, 'name' => 'Appartement indivis', 'address' => '1 rue A',
        'city' => 'Lyon', 'postal_code' => '69001', 'type' => 'apartment',
        'total_area' => 50, 'rented_area' => 50, 'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000, 'notary_fees' => 0, 'agency_fees' => 0,
        'land_percentage' => 20, 'rental_start_date' => '2023-01-01', 'is_primary_residence' => false,
    ]);
    indivisionYear($user, 812345);

    $this->actingAs($user)
        ->get('/teledeclaration?year=2025')
        ->assertOk()
        // Le titre de la SECTION, pas « 2031 bis-SD » seul : l'aide contextuelle de la page le
        // contient aussi, et une première version du test passait sans que la section s'affiche.
        ->assertSee('Répartition du résultat entre indivisaires (cadre E)')
        // Maquette écran 3, variante B : le cadre E tel qu'imprimé (colonnes du Cerfa), pas une
        // description à plat — la phrase « indivisaire, BIC « M », quote-part … » a disparu de
        // l'écran (elle survit dans l'export CSV, qui reste un tableau plat).
        ->assertSee('Qualité')
        ->assertSee('Indivisaire')
        ->assertSee('Geoffrey Martin')
        ->assertSee('4 062')
        // Ligne de total du cadre E (variante B) : prouve que la somme retombe sur la 2031-SD.
        ->assertSee('Total (= 2031-SD, case CB/CC)')
        ->assertSee('8 123')
        // 2042-C-PRO en cartes par indivisaire (variante B) : case, montant, Copier.
        ->assertSee('Quote-part 50 % · sur sa propre déclaration de revenus')
        ->assertSee('sa quote-part', false);
});

/**
 * Maquette écran 3, variante B : le contrôle de la répartition (venu de `checks()`, cf. la
 * suite « Contrôle « orange » » plus haut) est répété à côté du cadre E, pas seulement dans
 * les « Contrôles de cohérence » en bas d'écran — c'est le tableau qu'il vérifie.
 */
it('repeats the indivision check next to the cadre E table, not only at the bottom of the screen', function () {
    $user = indivisionUser(); // Geoffrey et Claire ont tous deux une identité incomplète.
    Property::forceCreate([
        'user_id' => $user->id, 'name' => 'Appartement indivis', 'address' => '1 rue A',
        'city' => 'Lyon', 'postal_code' => '69001', 'type' => 'apartment',
        'total_area' => 50, 'rented_area' => 50, 'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000, 'notary_fees' => 0, 'agency_fees' => 0,
        'land_percentage' => 20, 'rental_start_date' => '2023-01-01', 'is_primary_residence' => false,
    ]);
    indivisionYear($user, 812345);

    $html = $this->actingAs($user)->get('/teledeclaration?year=2025')->getContent();

    expect(substr_count($html, 'Indivisaires à compléter pour la 2031 bis'))->toBe(2);
});

it('offers no 2042-C-PRO amount on the e-filing screen while the split is incomplete', function () {
    // Bug relevé le 2026-10-15 : l'écran reprenait la ligne d'origine, donc le résultat ENTIER de
    // l'indivision, qu'aucun indivisaire ne doit reporter. Le PDF, lui, n'affichait rien.
    $user = indivisionUser([
        ['name' => 'Geoffrey Martin', 'share_bp' => 5000],
        ['name' => 'Claire Durand', 'share_bp' => 4000],
    ]);
    Property::forceCreate([
        'user_id' => $user->id, 'name' => 'Appartement indivis', 'address' => '1 rue A',
        'city' => 'Lyon', 'postal_code' => '69001', 'type' => 'apartment',
        'total_area' => 50, 'rented_area' => 50, 'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000, 'notary_fees' => 0, 'agency_fees' => 0,
        'land_percentage' => 20, 'rental_start_date' => '2023-01-01', 'is_primary_residence' => false,
    ]);
    indivisionYear($user, 812345);
    $this->actingAs($user);

    $page = new \App\Filament\Pages\Teledeclaration;
    $page->year = 2025;
    $section2042 = $page->declarationData()['forms']['2042-C-PRO'];

    expect($section2042['lines'])->toHaveCount(1)
        ->and($section2042['lines'][0]['raw'])->toBe(0)
        ->and($section2042['lines'][0]['value'])->toBe('—')
        ->and($section2042['lines'][0]['desc'])->toContain('incomplète')
        // Maquette écran 3, variante B : pas de carte par indivisaire tant que la
        // répartition est incomplète — l'écran retombe sur le tableau générique ci-dessus.
        ->and($section2042['persons'])->toBe([]);
});
