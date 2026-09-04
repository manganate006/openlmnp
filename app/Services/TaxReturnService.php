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
        // Un exercice clôturé est généré depuis ses totaux figés, sans recalcul.
        if ($fiscalYear->status !== FiscalYear::STATUS_CLOSED) {
            $this->fiscalYearService->calculate($fiscalYear);
        }
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
    public function compute2031(FiscalYear $fy): array
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
    public function compute2033B(FiscalYear $fy, $properties, int $year): array
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
    public function compute2033A(FiscalYear $fy, $properties, int $year): array
    {
        $corpBrut = 0;
        $corpAmort = 0;
        $incorpBrut = 0;
        $incorpAmort = 0;
        $emprunts = 0;

        foreach ($properties as $prop) {
            // ⚠️ Immobilisations CORPORELLES brutes = le bien (terrain compris, il reste
            // corporel même s'il ne s'amortit pas) PLUS les travaux et le mobilier. Jusqu'au
            // 2026-09-05 la case 028 ne portait que la valeur de référence du bien : rejouer
            // une liasse réelle a montré qu'il y manquait exactement les travaux et le
            // mobilier — 9 144 € sur 226 645 —, alors que notre propre 2033-C les liste.
            // Sans effet sur le résultat, mais l'écran de contrôle de reprise compare cette
            // ligne : l'utilisateur voyait un écart rouge qui ne venait pas de lui.
            $refValue = $prop->market_value ?? $prop->acquisition_price;
            $corpBrut += (int) bcmul((string) $refValue, $prop->quota_share, 0);

            // Les frais d'acquisition sont des immobilisations INCORPORELLES (cases 014/016).
            // Ils sont incorporés au coût du bâtiment dans le 2033-C, mais le bilan les
            // distingue — c'est aussi ce que fait la liasse d'un cabinet.
            foreach ($this->depreciationService->depreciationDetailForYear($prop, $year) as $line) {
                if ($line['type'] === 'notary') {
                    $incorpBrut += (int) $line['base'];
                    $incorpAmort += (int) $line['cumul'];

                    continue;
                }

                // La base des composants immeuble est déjà comprise dans la valeur de
                // référence ci-dessus : seuls travaux et mobilier s'y ajoutent.
                if ($line['type'] === 'work' || $line['type'] === 'furniture') {
                    $corpBrut += (int) $line['base'];
                }

                $corpAmort += (int) $line['cumul'];
            }

            // Emprunts : capital restant dû
            foreach ($prop->loans as $loan) {
                $remaining = $loan->getRemainingCapitalAtEndOfYear($year);
                $emprunts += (int) bcmul((string) $remaining, $prop->quota_share, 0);
            }
        }

        $totalActif = ($corpBrut - $corpAmort) + ($incorpBrut - $incorpAmort);

        return [
            '014' => $incorpBrut,
            '016' => $incorpAmort,
            '028' => $corpBrut,
            '030' => $corpAmort,
            '044' => $corpBrut + $incorpBrut,
            '048' => $corpAmort + $incorpAmort,
            '112' => $totalActif,
            '120' => $totalActif - $fy->fiscal_result - $emprunts, // Compte exploitant (bouclage)
            '136' => $fy->fiscal_result,
            '156' => $emprunts,
            '180' => $totalActif, // Total passif = total actif
        ];
    }

    /**
     * 2033-C — Immobilisations et amortissements
     *
     * ⚠️ Les dotations viennent de `DepreciationService::depreciationDetailForYear()`,
     * qui s'appuie sur les mêmes calculs que la ligne 254 du 2033-B. L'égalité 572 = 254
     * est donc vraie PAR CONSTRUCTION.
     *
     * Elle ne l'était pas jusqu'au 2026-09-03, et la liasse imprimait « ⚠ Écart » sur trois
     * défauts cumulés : la ligne 572 sommait les `annual_depreciation` bruts (sans prorata
     * de première année, sans tenir compte des plans arrivés à terme), les frais de notaire
     * et d'agence n'avaient AUCUNE ligne dans ce tableau alors que la 254 les compte, et le
     * cumul était écrasé d'un bien à l'autre (`=` au lieu de `+=`) puis approximé par
     * `dotation × années`.
     *
     * ⚠️ Depuis le 2026-09-04, la ligne Cerfa d'un composant est une DONNÉE
     * (`property_components.cerfa_category`) et non plus une déduction faite sur son nom :
     * un composant renommé « Toiture ardoise » ou créé à la main basculait en « autres »
     * sans que rien ne le dise. La table de correspondance historique subsiste dans
     * `PropertyComponent::LEGACY_NAME_TO_CATEGORY`, où elle sert de valeur par défaut —
     * aucun montant n'a donc changé de ligne à la migration.
     */
    public function compute2033C($properties, int $year): array
    {
        // Ordre du Cerfa : incorporel, terrain, puis le corporel amortissable.
        $categories = [
            'incorporelles' => ['lines' => ['immo' => '410', 'amort' => '500'], 'brut' => 0, 'dotation' => 0, 'cumul' => 0],
            'terrains'      => ['lines' => ['immo' => '420', 'amort' => null], 'brut' => 0, 'dotation' => 0, 'cumul' => 0],
            'constructions' => ['lines' => ['immo' => '430', 'amort' => '520'], 'brut' => 0, 'dotation' => 0, 'cumul' => 0],
            'installations' => ['lines' => ['immo' => '440', 'amort' => '530'], 'brut' => 0, 'dotation' => 0, 'cumul' => 0],
            'agencements'   => ['lines' => ['immo' => '450', 'amort' => '540'], 'brut' => 0, 'dotation' => 0, 'cumul' => 0],
            'autres'        => ['lines' => ['immo' => '470', 'amort' => '560'], 'brut' => 0, 'dotation' => 0, 'cumul' => 0],
        ];

        foreach ($properties as $prop) {
            // ⚠️ Le terrain ne sort d'AUCUNE ligne du détail : il n'est pas amortissable, donc
            // le service d'amortissement l'ignore. Il n'en reste pas moins une immobilisation,
            // que la liasse d'un cabinet porte bien en 420 — sans lui, notre total 490 était
            // amputé de la part terrain (32 625 € sur 245 643 € pour la liasse réelle rejouée
            // le 2026-09-05). On le déduit de la valeur de référence, dont la base amortissable
            // est justement le complément.
            $refValue = (string) ($prop->market_value ?? $prop->acquisition_price);
            $land = bcsub(
                bcmul($refValue, $prop->quota_share, 0),
                $prop->depreciable_base,
                0
            );
            $categories['terrains']['brut'] += (int) $land;

            foreach ($this->depreciationService->depreciationDetailForYear($prop, $year) as $line) {
                $category = isset($categories[$line['cerfa_category'] ?? null])
                    ? $line['cerfa_category']
                    : 'autres';

                $categories[$category]['brut'] += (int) $line['base'];
                $categories[$category]['dotation'] += (int) $line['annual'];
                $categories[$category]['cumul'] += (int) $line['cumul'];
            }
        }

        return [
            'categories' => $categories,
            'total_brut' => array_sum(array_column($categories, 'brut')),
            'total_dotation' => array_sum(array_column($categories, 'dotation')),
            'total_cumul' => array_sum(array_column($categories, 'cumul')),
        ];
    }

    /**
     * 2033-D — Déficits reportables et amortissements différés
     *
     * ⚠️ Correction de conformité (v1.4.0). Les cases 982/983/984 suivent les DÉFICITS
     * reportables ; elles étaient alimentées par `previous_deferred`, c'est-à-dire par
     * l'AMORTISSEMENT RÉPUTÉ DIFFÉRÉ. Toute liasse d'un bailleur ayant de l'amortissement
     * différé déclarait donc des déficits qu'il n'avait pas — un défaut de conformité, pas
     * une gêne d'affichage. Les liasses générées avant la correction portent l'ancienne
     * valeur : le changement est annoncé au CHANGELOG et dans la page Liasse fiscale.
     *
     * Ce sont bien deux stocks distincts, que l'administration fait d'ailleurs suivre par
     * deux états séparés (BOI-FORM-000038 pour les amortissements dont la déduction a été
     * écartée, BOI-FORM-000039 pour les déficits) :
     *   - 982/983/984 : déficits antérieurs, imputés, restants — reportables DIX ans
     *     (CGI art. 156, I-1° ter ; BOI-BIC-CHAMP-40-20 § 250) ;
     *   - 870 : amortissements différés, reportables SANS limite de durée
     *     (CGI art. 39 C, II-3 ; BOI-BIC-AMT-20-40-10-30 § 10).
     *
     * La case 984 porte le stock à la clôture, déficit de l'exercice (860) compris.
     */
    public function compute2033D(FiscalYear $fy): array
    {
        return [
            '982' => (int) $fy->previous_deficit,      // Déficits antérieurs à l'ouverture
            '983' => (int) $fy->deficit_imputed,       // Imputés sur le bénéfice de l'exercice
            '984' => (int) $fy->deficit_carryforward,  // Restant à reporter à la clôture
            '860' => $fy->fiscal_result < 0 ? abs($fy->fiscal_result) : 0, // Déficit de l'exercice
            '870' => (int) $fy->deferred_depreciation, // Amortissements différés reportables
        ];
    }

    /**
     * 2042-C-PRO — Cases pour la déclaration de revenus
     */
    public function compute2042(FiscalYear $fy): array
    {
        return [
            'case_benefice' => '5NA', // Bénéfice avec OGA (ou 5NK sans)
            'case_deficit' => '5NY',  // Déficit avec OGA (ou 5NZ sans)
            'montant' => abs($fy->fiscal_result),
            'is_benefice' => $fy->fiscal_result >= 0,
        ];
    }
}
