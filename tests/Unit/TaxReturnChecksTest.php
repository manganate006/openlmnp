<?php

use App\Models\FiscalYear;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\FiscalYearService;
use App\Services\TaxReturnService;

/**
 * Les contrôles de cohérence entre formulaires (issue #10).
 *
 * cocool97 a signalé que la case 044 du 2033-A ne concordait pas avec la ligne 490 du
 * 2033-C, et demandé un contrôle. Le calcul a été corrigé par `328fe3a3` ; ce fichier
 * couvre le contrôle lui-même.
 *
 * ⚠️ Le point qui rend ces tests non triviaux : l'écart 044 − 490 vaut EXACTEMENT le
 * reliquat de ventilation, et le produit accepte délibérément un reliquat positif. Le
 * contrôle a donc trois états, et une tolérance — sans laquelle un bien tout neuf serait
 * signalé en défaut, `generateDefaultComponents()` tronquant chaque part séparément.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->depreciation = app(DepreciationService::class);
    $this->tax = app(TaxReturnService::class);
});

function checksProperty(User $user, array $overrides = []): Property
{
    return Property::forceCreate(array_merge([
        'user_id'              => $user->id,
        'name'                 => 'Bien contrôlé',
        'address'              => '1 rue Test',
        'city'                 => 'Paris',
        'postal_code'          => '75001',
        'type'                 => 'apartment',
        'total_area'           => 100,
        'rented_area'          => 100,
        'acquisition_date'     => '2022-01-01',
        'acquisition_price'    => 25000000,
        'notary_fees'          => 0,
        'agency_fees'          => 0,
        'market_value'         => null,
        'land_percentage'      => 20,
        'rental_start_date'    => '2023-01-01',
        'is_primary_residence' => false,
    ], $overrides));
}

/** @return list<array{id: string, status: string, message: string, delta: int}> */
function checksFor(User $user, int $year = 2024): array
{
    $properties = Property::withoutGlobalScopes()->where('user_id', $user->id)->get();
    $fy = app(FiscalYearService::class)->getOrCreate($user, $year);
    $tax = app(TaxReturnService::class);

    return $tax->checks(
        $tax->compute2033A($fy, $properties, $year),
        $tax->compute2033B($fy, $properties, $year),
        $tax->compute2033C($properties, $year),
        $properties,
    );
}

function checkNamed(array $checks, string $id): array
{
    return collect($checks)->firstWhere('id', $id);
}

// --- Le cas nominal, et le piège qu'il cache ------------------------------------------

it('accepts a freshly created property despite the truncation dust', function () {
    // ⚠️ LE test de ce fichier. `generateDefaultComponents()` répartit six pourcentages
    // qui font 100 %, mais tronque chaque part séparément et n'appelle PAS
    // `absorbTruncationDust()` : le bien porte donc réellement quelques centimes de
    // reliquat. Un contrôle strict crierait au loup sur un dossier intact — et le PDF,
    // qui arrondit à l'euro, afficherait « Écart : 245 643 € ≠ 245 643 € ».
    //
    // ⚠️⚠️ Le prix n'est PAS décoratif. Les six pourcentages par défaut sont tous des
    // multiples de 5 %, donc un prix rond (250 000 €) tombe juste et ne produit AUCUNE
    // poussière : écrit avec ce prix-là, ce test passait même en supprimant la tolérance,
    // et ne prouvait donc rien. 234 567,89 € laisse un vrai reliquat de troncature.
    $property = checksProperty($this->user, ['acquisition_price' => 23456789]);
    $this->depreciation->generateDefaultComponents($property);
    $property->refresh();

    $dust = (int) $property->depreciable_base - (int) $property->components->sum('base_amount');
    expect($dust)->toBeGreaterThan(0, 'le scénario doit produire de la poussière de troncature');
    expect($dust)->toBeLessThanOrEqual($this->depreciation->truncationTolerance($property));

    $check = checkNamed(checksFor($this->user), 'immobilisations');

    expect($check['delta'])->toBe($dust);
    expect($check['status'])->toBe(TaxReturnService::CHECK_OK);
});

it('states the equality when the ventilation covers the base exactly', function () {
    $property = checksProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    // On efface la poussière pour obtenir un écart rigoureusement nul.
    $property->refresh();
    $allocated = $property->components->sum('base_amount');
    $largest = $property->components->sortByDesc('base_amount')->first();
    $largest->forceFill([
        'base_amount' => $largest->base_amount + ((int) $property->depreciable_base - $allocated),
    ])->save();

    $check = checkNamed(checksFor($this->user), 'immobilisations');

    expect($check['delta'])->toBe(0);
    expect($check['status'])->toBe(TaxReturnService::CHECK_OK);
    expect($check['message'])->toContain('case 044 du 2033-A = ligne 490 du 2033-C');
});

// --- Les trois états ------------------------------------------------------------------

it('reports a deliberate under-ventilation as permitted, not as an error', function () {
    // Sous-ventiler est ACCEPTÉ par le produit depuis l'issue #8 : l'afficher en erreur
    // contredirait l'éditeur d'amortissements, qui l'affiche en orange.
    $property = checksProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    $property->refresh();
    $component = $property->components->sortByDesc('base_amount')->first();
    $component->forceFill(['base_amount' => $component->base_amount - 5000000])->save();

    $check = checkNamed(checksFor($this->user), 'immobilisations');

    expect($check['status'])->toBe(TaxReturnService::CHECK_WARNING);
    expect($check['delta'])->toBe(5000000);
    expect($check['message'])
        ->toContain('ne s\'amortira pas')
        ->toContain('C\'est permis');
});

it('reports components above the depreciable base as an error', function () {
    $property = checksProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    $property->refresh();
    $component = $property->components->sortByDesc('base_amount')->first();
    $component->forceFill(['base_amount' => $component->base_amount + 3000000])->save();

    $check = checkNamed(checksFor($this->user), 'immobilisations');

    expect($check['status'])->toBe(TaxReturnService::CHECK_ERROR);
    expect($check['delta'])->toBe(-3000000);
    expect($check['message'])->toContain('dépassent la base amortissable');
});

// --- Le contrôle repris du 2033-C ------------------------------------------------------

it('confirms the depreciation charge matches between 2033-B and 2033-C', function () {
    $property = checksProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    $check = checkNamed(checksFor($this->user), 'dotation');

    expect($check['status'])->toBe(TaxReturnService::CHECK_OK);
    expect($check['delta'])->toBe(0);
    expect($check['message'])->toContain('ligne 572 = ligne 254 du 2033-B');
});

it('flags a divergence between the two depreciation totals', function () {
    $property = checksProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    $properties = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->get();
    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2024);

    $form2033B = $this->tax->compute2033B($fy, $properties, 2024);
    $form2033B['254'] += 12345; // Une divergence forcée, que rien ne doit absorber.

    $check = checkNamed($this->tax->checks(
        $this->tax->compute2033A($fy, $properties, 2024),
        $form2033B,
        $this->tax->compute2033C($properties, 2024),
        $properties,
    ), 'dotation');

    expect($check['status'])->toBe(TaxReturnService::CHECK_ERROR);
    expect($check['delta'])->toBe(-12345);
    expect($check['message'])->toContain('≠ ligne 254 du 2033-B');
});

// --- La tolérance ne doit pas devenir un tapis sous lequel balayer ----------------------

it('does not let the tolerance swallow a real gap', function () {
    // La tolérance vaut UN centime par ligne ventilée. Un reliquat d'un euro doit
    // ressortir, sinon le contrôle ne contrôle rien.
    $property = checksProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    $property->refresh();
    $tolerance = $this->depreciation->truncationTolerance($property);
    $component = $property->components->sortByDesc('base_amount')->first();
    $component->forceFill(['base_amount' => $component->base_amount - ($tolerance + 100)])->save();

    $check = checkNamed(checksFor($this->user), 'immobilisations');

    expect($check['status'])->toBe(TaxReturnService::CHECK_WARNING);
});

it('counts only the ventilated lines in the tolerance', function () {
    // Une base saisie à la main (`manual`) ne vient pas d'une troncature : elle n'ouvre
    // aucun droit à tolérance.
    $property = checksProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);
    $property->refresh();

    $before = $this->depreciation->truncationTolerance($property);

    $property->components->first()->forceFill([
        'base_source' => PropertyComponent::BASE_SOURCE_MANUAL,
    ])->save();

    expect($this->depreciation->truncationTolerance($property->fresh()))->toBe($before - 1);
});

// --- Ce que voit l'utilisateur ---------------------------------------------------------

it('gives the screen and the PDF the same verdict on the same fiscal year', function () {
    // Le contrôle vivait en double — `!=` lâche dans la vue PDF, `===` strict dans la page
    // Filament. Deux règles pour une seule vérité, donc deux écrans qui pouvaient se
    // contredire. Ce test verrouille la source unique.
    $property = checksProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    $property->refresh();
    $component = $property->components->sortByDesc('base_amount')->first();
    $component->forceFill(['base_amount' => $component->base_amount - 5000000])->save();

    $this->actingAs($this->user);

    $expected = collect(checksFor($this->user));
    $warning = $expected->firstWhere('status', TaxReturnService::CHECK_WARNING);
    expect($warning)->not->toBeNull('le scénario doit produire un avertissement');

    // 1. L'écran de télédéclaration.
    $screen = collect(
        Livewire\Livewire::test(App\Filament\Pages\Teledeclaration::class, ['year' => 2024])
            ->instance()->declarationData['checks']
    );
    expect($screen->pluck('message')->all())->toBe($expected->pluck('message')->all());
    expect($screen->pluck('status')->all())->toBe($expected->pluck('status')->all());

    // 2. Le PDF, rendu pour de vrai et lu en HTML.
    $html = renderTaxReturnHtml($this->user, 2024);

    foreach ($expected as $check) {
        expect($html)->toContain(e($check['message']));
    }
    // L'avertissement doit porter l'habillage ambre, pas celui d'une erreur.
    expect($html)->toContain('class="notice"');
});

/** Rend la vue du PDF en HTML, avec exactement les données que `generatePdf()` lui passe. */
function renderTaxReturnHtml(User $user, int $year): string
{
    $properties = Property::withoutGlobalScopes()->where('user_id', $user->id)->get();
    $fy = app(FiscalYearService::class)->getOrCreate($user, $year);
    $tax = app(TaxReturnService::class);

    $form2033A = $tax->compute2033A($fy, $properties, $year);
    $form2033B = $tax->compute2033B($fy, $properties, $year);
    $form2033C = $tax->compute2033C($properties, $year);

    return view('pdf.tax-return', [
        'user' => $user,
        'year' => $year,
        'fiscalYear' => $fy,
        'properties' => $properties,
        'siren' => $user->siren ?? '000000000',
        'form2031' => $tax->compute2031($fy),
        'form2033A' => $form2033A,
        'form2033B' => $form2033B,
        'form2033C' => $form2033C,
        'form2033D' => $tax->compute2033D($fy),
        'form2042' => $tax->compute2042($fy),
        'checks' => $tax->checks($form2033A, $form2033B, $form2033C, $properties),
    ])->render();
}

it('prints the boxes 044 and 048 that the check refers to', function () {
    // Un message qui nomme « case 044 » sans que le document l'affiche est opaque : les
    // deux cases étaient calculées et montrées nulle part.
    $property = checksProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    expect(renderTaxReturnHtml($this->user, 2024))
        ->toContain('Total immob. brut (044)')
        ->toContain('Total amortissements (048)');
});
