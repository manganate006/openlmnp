<?php

use App\Models\AccountingEntry;
use App\Models\Income;
use App\Models\Loan;
use App\Models\Property;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\FiscalYearService;
use App\Services\LoanService;
use App\Services\TaxReturnService;

/**
 * Frais d'emprunt (art. 39-1-1° quater CGI, BOI-BIC-CHG-20-30-40 § 30-50) : frais de
 * dossier, courtage, part non restituable d'une caution. Déduits en une fois par défaut,
 * ou étalés linéairement sur la durée du prêt si l'utilisateur en a fait le choix
 * (`User::loan_fees_spread_from`, option GLOBALE au dossier).
 */
function feesLoanProperty(User $user, array $extra = []): Property
{
    return Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Bien à frais',
        'address' => '7 rue des Frais',
        'city' => 'Lyon',
        'postal_code' => '69003',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100, // quote-part = 1, sauf override
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 0,
        'land_percentage' => 0,
        'rental_start_date' => '2022-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ], $extra));
}

function feesLoan(Property $property, array $extra = []): Loan
{
    return Loan::create(array_merge([
        'property_id' => $property->id,
        'bank_name' => 'Banque Test',
        'amount' => 10000000,
        'annual_rate' => 2.0,
        'duration_months' => 120,
        'start_date' => '2024-01-01',
        'monthly_payment' => 0,
        'insurance_monthly' => 0,
    ], $extra));
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->loanService = app(LoanService::class);
});

it('deducts loan fees in full the year they are paid when no spread option is set', function () {
    $property = feesLoanProperty($this->user);
    $loan = feesLoan($property, [
        'fees_amount' => 120000, // 1 200 €
        'fees_paid_on' => '2024-03-15',
    ]);

    expect($this->loanService->feesForYear($loan, 2024))
        ->toBe(['deducted' => '120000', 'remaining_end' => '0', 'mode' => 'immediate'])
        ->and($this->loanService->feesForYear($loan, 2025))
        ->toBe(['deducted' => '0', 'remaining_end' => '0', 'mode' => 'immediate']);
});

it('spreads loan fees linearly over the loan duration once the dossier option covers the payment year', function () {
    $this->user->forceFill(['loan_fees_spread_from' => 2024])->save();

    $property = feesLoanProperty($this->user);
    $loan = feesLoan($property, [
        'duration_months' => 120, // 10 ans
        'fees_amount' => 120000, // 1 200 €, soit 120 € / an
        'fees_paid_on' => '2024-01-10',
    ]);

    // Année de paiement (index 0) comme chacune des 9 suivantes : 120 €/an, sans prorata
    // temporis (§ 70). La dernière année (2033) absorbe le reliquat de troncature.
    expect($this->loanService->feesForYear($loan, 2024))
        ->toBe(['deducted' => '12000', 'remaining_end' => '108000', 'mode' => 'spread'])
        ->and($this->loanService->feesForYear($loan, 2028))
        ->toBe(['deducted' => '12000', 'remaining_end' => '60000', 'mode' => 'spread'])
        ->and($this->loanService->feesForYear($loan, 2033))
        ->toBe(['deducted' => '12000', 'remaining_end' => '0', 'mode' => 'spread'])
        ->and($this->loanService->feesForYear($loan, 2034))
        ->toBe(['deducted' => '0', 'remaining_end' => '0', 'mode' => 'spread'])
        ->and($this->loanService->feesForYear($loan, 2023))
        ->toBe(['deducted' => '0', 'remaining_end' => '0', 'mode' => 'spread']);
});

it('ignores the spread option for fees paid before it takes effect', function () {
    $this->user->forceFill(['loan_fees_spread_from' => 2025])->save();

    $property = feesLoanProperty($this->user);
    $loan = feesLoan($property, [
        'fees_amount' => 50000,
        'fees_paid_on' => '2024-06-01', // avant l'année d'effet de l'option
    ]);

    expect($this->loanService->feesForYear($loan, 2024))
        ->toBe(['deducted' => '50000', 'remaining_end' => '0', 'mode' => 'immediate']);
});

it('absorbs the bcmath rounding remainder in the last year of the spread, exactly', function () {
    $this->user->forceFill(['loan_fees_spread_from' => 2024])->save();

    $property = feesLoanProperty($this->user);
    // 130 mois = 11 années civiles (ceil(130/12)) ; 100 000 / 130 mois * 12 = 9 230,76... €
    // tronqués à 9 230 € : les 10 premières années ne totalisent que 92 300 €, la 11e doit
    // récupérer le reliquat de 7 700 € pour retomber exactement sur fees_amount.
    $loan = feesLoan($property, [
        'duration_months' => 130,
        'fees_amount' => 100000,
        'fees_paid_on' => '2024-01-01',
    ]);

    $sum = 0;
    for ($year = 2024; $year <= 2035; $year++) {
        $sum += (int) $this->loanService->feesForYear($loan, $year)['deducted'];
    }

    expect($this->loanService->feesForYear($loan, 2033)) // 10e année (index 9)
        ->toBe(['deducted' => '9230', 'remaining_end' => '7700', 'mode' => 'spread'])
        ->and($this->loanService->feesForYear($loan, 2034)) // 11e et dernière année (index 10)
        ->toBe(['deducted' => '7700', 'remaining_end' => '0', 'mode' => 'spread'])
        ->and($sum)->toBe(100000);
});

it('adds the immediate fees to the fiscal result, quote-part applied', function () {
    $property = feesLoanProperty($this->user, ['rented_area' => 50]); // quote-part 50 %
    feesLoan($property, [
        'fees_amount' => 100000, // 1 000 €
        'fees_paid_on' => '2024-05-01',
    ]);

    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2024);
    $totals = app(FiscalYearService::class)->computeTotals($fy);

    expect($totals['total_expenses'])->toBe(50000); // 1 000 € × 50 %
});

it('adds the spread fees fraction (not the whole amount) to the fiscal result', function () {
    $this->user->forceFill(['loan_fees_spread_from' => 2024])->save();

    $property = feesLoanProperty($this->user); // quote-part 100 %
    feesLoan($property, [
        'duration_months' => 120,
        'fees_amount' => 120000, // 1 200 €, soit 120 €/an
        'fees_paid_on' => '2024-01-01',
    ]);

    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2024);
    $totals = app(FiscalYearService::class)->computeTotals($fy);

    expect($totals['total_expenses'])->toBe(12000); // 120 €, pas les 1 200 € payés
});

it('puts immediate fees on line 242 of the 2033-B, not 294', function () {
    $property = feesLoanProperty($this->user, ['name' => 'Bien immédiat']);
    feesLoan($property, [
        'fees_amount' => 60000,
        'fees_paid_on' => '2024-02-01',
    ]);

    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2024);
    $properties = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->get();
    $form = app(TaxReturnService::class)->compute2033B($fy, $properties, 2024);

    expect($form['242'])->toBe(60000)
        ->and($form['294'])->toBe(0);
});

it('puts spread fees on line 294 of the 2033-B, not 242', function () {
    // Option GLOBALE au dossier (`User::loan_fees_spread_from`) : dès qu'elle est activée,
    // TOUS les emprunts dont les frais sont payés à partir de cette année basculent, pas
    // seulement un emprunt choisi un par un.
    $this->user->forceFill(['loan_fees_spread_from' => 2024])->save();

    $property = feesLoanProperty($this->user, ['name' => 'Bien étalé']);
    feesLoan($property, [
        'duration_months' => 120,
        'fees_amount' => 120000, // 1/10e par an = 12 000 € en 2024
        'fees_paid_on' => '2024-01-01',
    ]);

    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2024);
    $properties = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->get();
    $form = app(TaxReturnService::class)->compute2033B($fy, $properties, 2024);

    expect($form['242'])->toBe(0)
        ->and($form['294'])->toBe(12000);
});

it('carries the non-amortized spread balance on line 092 of the 2033-A, zero in immediate mode', function () {
    $this->user->forceFill(['loan_fees_spread_from' => 2024])->save();

    $property = feesLoanProperty($this->user);
    feesLoan($property, [
        'duration_months' => 120,
        'fees_amount' => 120000, // 1 200 €, 120 €/an, solde restant = 1 080 € fin 2024
        'fees_paid_on' => '2024-01-01',
    ]);

    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2024);
    $properties = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->get();
    $formA = app(TaxReturnService::class)->compute2033A($fy, $properties, 2024);

    expect($formA['092'])->toBe(108000);

    // Dix ans plus tard, le solde est épuisé.
    $fyLast = app(FiscalYearService::class)->getOrCreate($this->user, 2033);
    $formALast = app(TaxReturnService::class)->compute2033A($fyLast, $properties, 2033);

    expect($formALast['092'])->toBe(0);
});

it('books immediate fees on 6272 debit / 512 credit', function () {
    $property = feesLoanProperty($this->user);
    $loan = feesLoan($property, [
        'fees_amount' => 45000,
        'fees_paid_on' => '2024-04-10',
    ]);

    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2024);

    expect((int) $fy->accountingEntries()->where('account_code', '6272')->sum('debit'))->toBe(45000)
        ->and((int) $fy->accountingEntries()->where('account_code', '481')->sum('debit'))->toBe(0)
        ->and((int) $fy->accountingEntries()->sum('debit'))
            ->toBe((int) $fy->accountingEntries()->sum('credit'));
});

it('books spread fees on 481/512 at payment, then a yearly 6862/481 dotation', function () {
    $this->user->forceFill(['loan_fees_spread_from' => 2024])->save();

    $property = feesLoanProperty($this->user);
    feesLoan($property, [
        'duration_months' => 120,
        'fees_amount' => 120000,
        'fees_paid_on' => '2024-01-01',
    ]);

    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2024);

    // L'inscription en charges à répartir (481/512) se fait une seule fois, à la date du
    // paiement, pour le montant TOTAL — pas seulement la fraction 2024.
    expect((int) $fy->accountingEntries()->where('account_code', '481')->where('debit', '>', 0)->sum('debit'))
        ->toBe(120000)
        ->and((int) $fy->accountingEntries()->where('account_code', '6862')->sum('debit'))->toBe(12000)
        ->and((int) $fy->accountingEntries()->where('account_code', '481')->sum('credit'))->toBe(12000)
        ->and((int) $fy->accountingEntries()->sum('debit'))
            ->toBe((int) $fy->accountingEntries()->sum('credit'));

    // L'année suivante : plus d'écriture 481/512 (déjà inscrits en 2024), mais une nouvelle
    // dotation 6862/481.
    $fy2025 = app(FiscalYearService::class)->getOrCreate($this->user, 2025);

    expect((int) $fy2025->accountingEntries()->where('account_code', '481')->where('debit', '>', 0)->sum('debit'))
        ->toBe(0)
        ->and((int) $fy2025->accountingEntries()->where('account_code', '6862')->sum('debit'))->toBe(12000);
});

it('includes interest, insurance and fees in the projected loan charges of a future year', function () {
    $property = feesLoanProperty($this->user, ['rented_area' => 50]); // quote-part 50 %
    $loan = feesLoan($property, [
        'duration_months' => 120,
        'insurance_monthly' => 2000, // 20 €/mois
        'fees_amount' => 120000,
        'fees_paid_on' => '2024-01-01',
    ]);
    $this->loanService->generateSchedule($loan);

    $year = 2026;
    $interest = $loan->getInterestsForYear($year);
    $insurance = $loan->getInsuranceForYear($year);
    $fees = (int) $this->loanService->feesForYear($loan, $year)['deducted'];
    $expected = (int) $loan->prorate((string) ($interest + $insurance + $fees), $property->quota_share);

    expect($expected)->toBeGreaterThan(0)
        ->and($this->loanService->projectedLoanChargesForYear($property, $year))->toBe($expected);
});

it('deducts loan charges (interest, insurance, fees) in the multi-year projection page, not just the depreciation', function () {
    $this->actingAs($this->user);

    $property = feesLoanProperty($this->user);
    $loan = feesLoan($property, [
        'duration_months' => 120,
        'insurance_monthly' => 3000,
        'fees_amount' => 120000,
        'fees_paid_on' => '2024-01-01',
    ]);
    $this->loanService->generateSchedule($loan);

    $component = \Livewire\Livewire::test(\App\Filament\Pages\Projection::class)
        ->set('startYear', 2026)
        ->set('projectionYears', 1);

    $data = $component->instance()->projectionData();
    $expectedLoanCharges = $this->loanService->projectedLoanChargesForYear($property, 2026);

    expect($expectedLoanCharges)->toBeGreaterThan(0)
        ->and($data['rows'][0]['expenses'])->toBeGreaterThanOrEqual($expectedLoanCharges);
});

/**
 * Issue #26 — effet déficit vs. amortissement différé, sur une chaîne de dix exercices.
 *
 * Deux dossiers IDENTIQUES par ailleurs (même bien, même emprunt dédié à 100 %, mêmes
 * recettes), qui ne diffèrent QUE par le mode de déduction des 5 000 € de frais payés en
 * N — immédiat (a) vs étalé dès N (b) — pour isoler la seule variable qui nous intéresse.
 * Aucun loyer en N (le déficit de N n'est donc QUE les frais). À partir de N+1, un loyer net
 * modeste (2 000 €/an) qu'un gros œuvre largement dimensionné (7 599,99 €/an, et un stock
 * différé qui ne fait que croître derrière) absorbe INTÉGRALEMENT chaque année des deux
 * côtés : le résultat fiscal retombe exactement à 0 de N+1 à N+10, dans les deux dossiers.
 *
 * Deux déficits différents mais JAMAIS imputés (puisque le résultat des années suivantes
 * est toujours nul, donc rien à imputer dessus) :
 *   - immédiat : le déficit de N porte les 5 000 € EN ENTIER (aucune recette pour les
 *     absorber, et l'étalement n'existe pas) ;
 *   - étalé : le déficit de N ne porte que LA FRACTION 2024 (250 €, 500 000 / 240 mois ×
 *     12), le solde restant (4 750 €) est une charge à répartir, pas un déficit.
 *
 * L'écart ne se voit donc PAS sur `fiscal_result` (0 partout de N+1 à N+10 dans les deux
 * dossiers) ni sur `deficit_imputed` (0 partout, rien n'étant jamais imputé) : il se loge
 * UNIQUEMENT dans le stock `deferred_depreciation`, qui grandit plus vite côté étalé — de
 * 250 € par an, une fraction par année N+1..N+10 (PAS N+1..N+10 inclus l'année N : la
 * fraction de N a déjà été absorbée dans le déficit de N lui-même, calculé ci-dessus, et
 * n'entre donc dans aucun des deux stocks différés — vérifié ci-dessous à N par l'égalité
 * stricte des deux `deferred_depreciation`). Sur dix fractions (N+1 à N+10), l'écart vaut
 * exactement 10 × 250 € = 2 500 €, mesuré ici au centime (250 000 centimes), ni 9 ni 11
 * fractions — valeurs confirmées par une exécution instrumentée de ce test avant sa version
 * finale (`deferred_depreciation` : 6 359 989 en (a), 6 609 989 en (b), à N+10).
 */
it('matches the deferred-depreciation gap to the number of spread fee fractions once the deficit of N absorbs the rest (issue #26)', function () {
    $depreciationService = app(DepreciationService::class);
    $startYear = 2024;
    $endYear = $startYear + 10;

    // (a) Déduction immédiate — dossier et utilisateur dédiés.
    $userImmediate = User::factory()->create();
    $propertyImmediate = feesLoanProperty($userImmediate, [
        'acquisition_date' => "{$startYear}-01-01",
        'rental_start_date' => "{$startYear}-01-01",
    ]);
    $depreciationService->generateDefaultComponents($propertyImmediate);
    feesLoan($propertyImmediate, [
        'duration_months' => 240, // 20 ans
        'is_dedicated' => true,
        'fees_amount' => 500000, // 5 000 €
        'fees_paid_on' => "{$startYear}-01-01",
        'start_date' => "{$startYear}-01-01",
    ]);

    // (b) Étalement dès N — dossier et utilisateur SÉPARÉS, sinon l'option globale au
    // dossier contaminerait aussi (a).
    $userSpread = User::factory()->create();
    $userSpread->forceFill(['loan_fees_spread_from' => $startYear])->save();
    $propertySpread = feesLoanProperty($userSpread, [
        'acquisition_date' => "{$startYear}-01-01",
        'rental_start_date' => "{$startYear}-01-01",
    ]);
    $depreciationService->generateDefaultComponents($propertySpread);
    feesLoan($propertySpread, [
        'duration_months' => 240,
        'is_dedicated' => true,
        'fees_amount' => 500000,
        'fees_paid_on' => "{$startYear}-01-01",
        'start_date' => "{$startYear}-01-01",
    ]);

    // Aucun loyer en N ; 2 000 €/an net de N+1 à N+10, des deux côtés.
    for ($year = $startYear + 1; $year <= $endYear; $year++) {
        foreach ([$propertyImmediate, $propertySpread] as $property) {
            Income::create([
                'property_id' => $property->id,
                'income_date' => "{$year}-06-01",
                'amount' => 200000,
                'platform_fee' => 0,
                'tourist_tax' => 0,
                'source' => 'direct',
            ]);
        }
    }

    // La chaîne, dans l'ordre, pour chaque dossier : chaque année a besoin du
    // `deferred_depreciation` réellement calculé et persisté l'année précédente.
    $immediateYears = [];
    $spreadYears = [];
    for ($year = $startYear; $year <= $endYear; $year++) {
        $immediateYears[$year] = app(FiscalYearService::class)->getOrCreate($userImmediate, $year);
        $spreadYears[$year] = app(FiscalYearService::class)->getOrCreate($userSpread, $year);
    }

    // --- (a) immédiat : le déficit de N porte les 5 000 € en entier ---
    $fyImmediateN = $immediateYears[$startYear];
    expect((int) $fyImmediateN->fiscal_result)->toBe(-500000)
        ->and($fyImmediateN->deficit_detail)->toHaveCount(1)
        ->and($fyImmediateN->deficit_detail[0]['origin_year'])->toBe($startYear)
        ->and($fyImmediateN->deficit_detail[0]['remaining'])->toBe(500000);

    // N+1 à N+10 : résultat ramené à 0 par l'amortissement, donc rien à imputer sur le
    // déficit de N, qui ne diminue pas.
    foreach (range($startYear + 1, $endYear) as $year) {
        expect((int) $immediateYears[$year]->fiscal_result)->toBe(0)
            ->and((int) $immediateYears[$year]->deficit_imputed)->toBe(0);
    }

    $deficitImmediateAtN10 = collect($immediateYears[$endYear]->deficit_detail)
        ->firstWhere('origin_year', $startYear);
    expect($deficitImmediateAtN10['remaining'])->toBe(500000)
        // Pas encore périmé à N+10 : le délai de dix ans n'expire qu'à N+11
        // (CGI art. 156, I-1° ter ; BOI-BIC-DEF-20-10 § 50).
        ->and($deficitImmediateAtN10['expired'])->toBe(0);

    // --- (b) étalé : le déficit de N ne porte que la fraction 2024 (250 €) ---
    $fySpreadN = $spreadYears[$startYear];
    expect((int) $fySpreadN->fiscal_result)->toBe(-25000)
        ->and($fySpreadN->deficit_detail)->toHaveCount(1)
        ->and($fySpreadN->deficit_detail[0]['origin_year'])->toBe($startYear)
        ->and($fySpreadN->deficit_detail[0]['remaining'])->toBe(25000);

    foreach (range($startYear + 1, $endYear) as $year) {
        expect((int) $spreadYears[$year]->fiscal_result)->toBe(0)
            ->and((int) $spreadYears[$year]->deficit_imputed)->toBe(0);
    }

    $deficitSpreadAtN10 = collect($spreadYears[$endYear]->deficit_detail)
        ->firstWhere('origin_year', $startYear);
    expect($deficitSpreadAtN10['remaining'])->toBe(25000)
        ->and($deficitSpreadAtN10['expired'])->toBe(0);

    // --- Le même bien amortissable des deux côtés : à N, le stock différé est identique
    // (la fraction 2024 des frais, qu'elle soit de 250 € ou 5 000 €, n'y entre dans aucun
    // des deux cas — elle est tout entière dans le déficit de N ci-dessus, pas ici).
    expect((int) $immediateYears[$startYear]->deferred_depreciation)
        ->toBe((int) $spreadYears[$startYear]->deferred_depreciation);

    // --- L'écart mesuré à N+10 : exactement dix fractions de 250 € (N+1 à N+10), jamais
    // absorbées par aucun déficit puisque le résultat des deux dossiers est nul chaque
    // année — elles ne peuvent donc aller que dans `deferred_depreciation`.
    $gapAtN10 = (int) $spreadYears[$endYear]->deferred_depreciation
        - (int) $immediateYears[$endYear]->deferred_depreciation;

    expect($gapAtN10)->toBe(10 * 25000);
});
