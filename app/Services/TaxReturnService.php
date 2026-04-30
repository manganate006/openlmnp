<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Property;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Génération de la liasse fiscale LMNP structurée selon les lignes Cerfa.
 *
 * Formulaires : 2031-SD, 2033-A (bilan), 2033-B (résultat), 2033-C (immobilisations), 2033-D (déficits)
 * Régime : BIC réel simplifié (RSI)
 */
class TaxReturnService
{
    public function __construct(
        private FiscalYearService $fiscalYearService,
        private DepreciationService $depreciationService,
    ) {}

    public function generatePdf(FiscalYear $fiscalYear): string
    {
        $this->fiscalYearService->calculate($fiscalYear);
        $fiscalYear->refresh();

        $user = $fiscalYear->user;
        $year = $fiscalYear->year;
        $properties = Property::withoutGlobalScopes()->where('user_id', $user->id)->get();

        $data = [
            'user' => $user,
            'year' => $year,
            'fiscalYear' => $fiscalYear,
            'properties' => $properties,
            'siren' => $user->siren ?? '000000000',
            'form2031' => $this->compute2031($fiscalYear),
            'form2033B' => $this->compute2033B($fiscalYear, $properties, $year),
            'form2033A' => $this->compute2033A($fiscalYear, $properties, $year),
            'form2033C' => $this->compute2033C($properties, $year),
            'form2033D' => $this->compute2033D($fiscalYear),
            'form2042' => $this->compute2042($fiscalYear),
        ];

        $pdf = Pdf::loadView('pdf.tax-return', $data);
        $pdf->setPaper('A4', 'portrait');

        $filename = "liasse_fiscale_{$year}.pdf";
        $path = "tax-returns/{$year}/{$filename}";

        Storage::put($path, $pdf->output());
        $fiscalYear->update(['pdf_path' => $path]);

        return $path;
    }

    /**
     * 2031-SD — Déclaration de résultat
     */
    private function compute2031(FiscalYear $fy): array
    {
        return [
            'AB' => $fy->total_income, // Production vendue services (loyers)
            'CB' => $fy->fiscal_result > 0 ? $fy->fiscal_result : 0, // Bénéfice
            'CC' => $fy->fiscal_result <= 0 ? abs($fy->fiscal_result) : 0, // Déficit
        ];
    }

    /**
     * 2033-B — Compte de résultat simplifié
     */
    private function compute2033B(FiscalYear $fy, $properties, int $year): array
    {
        // Produits
        $loyers = 0; // Ligne 218 : loyers bruts (montant - commission)
        $loyersBruts = 0; // CA brut incluant commissions
        foreach ($properties as $prop) {
            $income = $prop->incomes()->whereYear('income_date', $year);
            $loyers += $income->selectRaw('SUM(amount) - SUM(platform_fee) as net')->value('net') ?? 0;
            $loyersBruts += $income->sum('amount');
        }

        // Charges par ligne Cerfa
        $line242 = 0; // Autres charges externes
        $line244 = 0; // Impôts et taxes
        $line294 = 0; // Charges financières (intérêts)

        foreach ($properties as $prop) {
            $expenses = $prop->expenses()->whereYear('expense_date', $year)->get();
            foreach ($expenses as $exp) {
                $effective = $exp->is_dedicated
                    ? $exp->amount
                    : (int) bcmul((string) $exp->amount, $prop->quota_share, 0);

                if (in_array($exp->category, ['property_tax'])) {
                    $line244 += $effective;
                } else {
                    $line242 += $effective;
                }
            }

            // Intérêts d'emprunt
            foreach ($prop->loans as $loan) {
                $interests = $loan->getInterestsForYear($year);
                $insurance = $loan->getInsuranceForYear($year);
                $prorata = (int) bcmul((string) ($interests + $insurance), $prop->quota_share, 0);
                $line294 += $prorata;
            }
        }

        // Amortissements — ligne 254
        $line254 = 0;
        foreach ($properties as $prop) {
            $dep = $this->depreciationService->calculateAnnualDepreciation($prop, $year);
            $line254 += (int) $dep['total'];
        }

        $line232 = $loyers; // Total produits
        $line264 = $line242 + $line244 + $line254; // Total charges exploitation
        $line270 = $line232 - $line264; // Résultat exploitation
        $line310 = $line270 - $line294; // Résultat comptable

        return [
            '218' => $loyers,
            '218_brut' => $loyersBruts,
            '232' => $line232,
            '242' => $line242,
            '244' => $line244,
            '254' => $line254,
            '264' => $line264,
            '270' => $line270,
            '294' => $line294,
            '310' => $line310,
            '312' => $line310 > 0 ? $line310 : 0,
            '314' => $line310 < 0 ? abs($line310) : 0,
            '318' => max(0, $fy->total_depreciation - $fy->capped_depreciation), // ARD
            '352' => $fy->fiscal_result > 0 ? $fy->fiscal_result : 0,
            '354' => $fy->fiscal_result < 0 ? abs($fy->fiscal_result) : 0,
            '360' => $fy->previous_deferred,
            '370' => $fy->fiscal_result > 0 ? $fy->fiscal_result : 0,
            '372' => $fy->fiscal_result < 0 ? abs($fy->fiscal_result) : 0,
        ];
    }

    /**
     * 2033-A — Bilan simplifié
     */
    private function compute2033A(FiscalYear $fy, $properties, int $year): array
    {
        $immoBrut = 0;
        $immoAmort = 0;
        $emprunts = 0;

        foreach ($properties as $prop) {
            // Immobilisations brutes (valeur de référence × quote-part)
            $refValue = $prop->market_value ?? $prop->acquisition_price;
            $immoBrut += (int) bcmul((string) $refValue, $prop->quota_share, 0);

            // Amortissements cumulés (estimation simplifiée)
            $startYear = (int) $prop->rental_start_date->format('Y');
            $yearsActive = max(0, $year - $startYear + 1);
            for ($y = $startYear; $y <= $year; $y++) {
                $dep = $this->depreciationService->calculateAnnualDepreciation($prop, $y);
                $immoAmort += (int) $dep['total'];
            }

            // Emprunts : capital restant dû
            foreach ($prop->loans as $loan) {
                $remaining = $loan->getRemainingCapitalAtEndOfYear($year);
                $emprunts += (int) bcmul((string) $remaining, $prop->quota_share, 0);
            }
        }

        $immoNet = $immoBrut - $immoAmort;
        $totalActif = $immoNet; // Simplifié : pas de trésorerie trackée

        return [
            '028' => $immoBrut,
            '030' => $immoAmort,
            '112' => $totalActif,
            '120' => $totalActif - $fy->fiscal_result - $emprunts, // Compte exploitant (bouclage)
            '136' => $fy->fiscal_result,
            '156' => $emprunts,
            '180' => $totalActif, // Total passif = total actif
        ];
    }

    /**
     * 2033-C — Immobilisations et amortissements
     */
    private function compute2033C($properties, int $year): array
    {
        $categories = [
            'constructions' => ['lines' => ['immo' => '430', 'amort' => '520'], 'brut' => 0, 'dotation' => 0, 'cumul' => 0],
            'installations' => ['lines' => ['immo' => '440', 'amort' => '530'], 'brut' => 0, 'dotation' => 0, 'cumul' => 0],
            'agencements'   => ['lines' => ['immo' => '450', 'amort' => '540'], 'brut' => 0, 'dotation' => 0, 'cumul' => 0],
            'autres'        => ['lines' => ['immo' => '470', 'amort' => '560'], 'brut' => 0, 'dotation' => 0, 'cumul' => 0],
        ];

        $componentCategoryMap = [
            'Gros œuvre' => 'constructions',
            'Toiture' => 'constructions',
            'Installations électriques' => 'installations',
            'Plomberie / sanitaire' => 'installations',
            'Étanchéité' => 'agencements',
            'Agencements intérieurs' => 'agencements',
        ];

        foreach ($properties as $prop) {
            // Composants immeuble
            foreach ($prop->components as $comp) {
                $cat = $componentCategoryMap[$comp->name] ?? 'autres';
                $categories[$cat]['brut'] += $comp->base_amount;
                $categories[$cat]['dotation'] += $comp->annual_depreciation;
            }

            // Travaux
            foreach ($prop->works as $work) {
                $amount = $work->is_dedicated ? $work->amount : (int) bcmul((string) $work->amount, $prop->quota_share, 0);
                $categories['agencements']['brut'] += $amount;
                $annualDep = $work->is_dedicated ? $work->annual_depreciation : (int) bcmul((string) $work->annual_depreciation, $prop->quota_share, 0);
                $categories['agencements']['dotation'] += $annualDep;
            }

            // Mobilier
            foreach ($prop->furniture as $item) {
                $amount = $item->is_dedicated ? $item->amount : (int) bcmul((string) $item->amount, $prop->quota_share, 0);
                $categories['autres']['brut'] += $amount;
                $annualDep = $item->is_dedicated ? $item->annual_depreciation : (int) bcmul((string) $item->annual_depreciation, $prop->quota_share, 0);
                $categories['autres']['dotation'] += $annualDep;
            }
        }

        // Calculer cumuls approximatifs
        foreach ($properties as $prop) {
            $startYear = (int) $prop->rental_start_date->format('Y');
            $yearsActive = max(0, $year - $startYear);
            foreach ($categories as $cat => &$data) {
                $data['cumul'] = $data['dotation'] * $yearsActive; // Approximation linéaire
            }
        }

        // Total ligne 572 (dotations) doit = ligne 254 du 2033-B
        $totalDotation = array_sum(array_column($categories, 'dotation'));

        return [
            'categories' => $categories,
            'total_brut' => array_sum(array_column($categories, 'brut')),
            'total_dotation' => $totalDotation,
            'total_cumul' => array_sum(array_column($categories, 'cumul')),
        ];
    }

    /**
     * 2033-D — Déficits reportables
     */
    private function compute2033D(FiscalYear $fy): array
    {
        return [
            '982' => $fy->previous_deferred, // Déficits N-1
            '983' => min($fy->previous_deferred, max(0, $fy->fiscal_result)), // Imputés
            '984' => max(0, $fy->previous_deferred - min($fy->previous_deferred, max(0, $fy->fiscal_result))),
            '860' => $fy->fiscal_result < 0 ? abs($fy->fiscal_result) : 0,
            '870' => $fy->deferred_depreciation, // Total reportable
        ];
    }

    /**
     * 2042-C-PRO — Cases pour la déclaration de revenus
     */
    private function compute2042(FiscalYear $fy): array
    {
        return [
            'case_benefice' => '5NA', // Bénéfice avec OGA (ou 5NK sans)
            'case_deficit' => '5NY',  // Déficit avec OGA (ou 5NZ sans)
            'montant' => abs($fy->fiscal_result),
            'is_benefice' => $fy->fiscal_result >= 0,
        ];
    }
}
