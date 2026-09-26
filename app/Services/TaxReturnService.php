<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Property;
use App\Models\PropertyComponent;
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
    /**
     * Les formulaires que le document contient RÉELLEMENT.
     *
     * ⚠️ Source unique de l'annonce faite au dehors — l'outil MCP `generate_tax_return` la
     * lit ici. Elle a longtemps promis « 2031, 2033-A à 2033-G » là où la vue ne rend que
     * quatre sections : un assistant répète l'annonce, l'utilisateur cherche des pages qui
     * n'existent pas, et l'erreur essaime dans la documentation. `TaxReturnFormsTest` la
     * compare aux titres de `pdf/tax-return.blade.php` DANS LES DEUX SENS — ajouter une
     * section sans l'annoncer échoue aussi.
     *
     * Les codes sont ceux du Cerfa, tels que la vue les écrit.
     */
    public const FORMS = ['2031-SD', '2033-A', '2033-B', '2033-C', '2033-D'];

    public const CHECK_OK = 'ok';

    /** Situation permise par le produit, mais que l'utilisateur doit savoir. */
    public const CHECK_WARNING = 'warning';

    public const CHECK_ERROR = 'error';

    public function __construct(
        private FiscalYearService $fiscalYearService,
        private DepreciationService $depreciationService,
    ) {}

    public function generatePdf(FiscalYear $fiscalYear): string
    {
        $data = $this->pdfData($fiscalYear);
        $year = $data['year'];

        $pdf = Pdf::loadView('pdf.tax-return', $data);
        $pdf->setPaper('A4', 'portrait');

        $filename = "liasse_fiscale_{$year}.pdf";
        $path = "tax-returns/{$year}/{$filename}";

        Storage::put($path, $pdf->output());
        $fiscalYear->update(['pdf_path' => $path]);

        return $path;
    }

    /**
     * Tout ce que la vue `pdf.tax-return` reçoit — séparé de `generatePdf()` pour que le rendu
     * HTML se teste sans passer par DomPDF.
     *
     * @return array<string, mixed>
     */
    public function pdfData(FiscalYear $fiscalYear): array
    {
        // Un exercice clôturé est généré depuis ses totaux figés, sans recalcul.
        if ($fiscalYear->status !== FiscalYear::STATUS_CLOSED) {
            $this->fiscalYearService->calculate($fiscalYear);
        }
        $fiscalYear->refresh();

        $user = $fiscalYear->user;
        $year = $fiscalYear->year;
        $properties = Property::withoutGlobalScopes()->where('user_id', $user->id)->get();
        $resultBreakdown = $this->resultBreakdown($fiscalYear, $properties, $year);

        $data = [
            'user' => $user,
            'year' => $year,
            'fiscalYear' => $fiscalYear,
            'properties' => $properties,
            'siren' => $user->siren ?? '000000000',
            'form2031' => $this->compute2031($fiscalYear),
            'form2033B' => $this->compute2033B($fiscalYear, $properties, $year, $resultBreakdown),
            'resultBreakdown' => $resultBreakdown,
            'form2033A' => $this->compute2033A($fiscalYear, $properties, $year),
            'form2033C' => $this->compute2033C($properties, $year),
            'form2033D' => $this->compute2033D($fiscalYear),
            'form2042' => $this->compute2042($fiscalYear),
            'assetBreakdown' => $this->assetBreakdown($properties, $year),
        ];

        $data['checks'] = $this->checks($data['form2033A'], $data['form2033B'], $data['form2033C'], $properties);

        return $data;
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
     * 2033-B — Compte de résultat simplifié.
     *
     * Une SOMME de `resultBreakdown()`, rien d'autre : l'annexe « Détail du résultat » du PDF
     * imprime ce même détail, si bien que chaque ligne ci-dessous se refait à la main depuis
     * l'annexe (issue #13).
     *
     * Partie B (détermination du résultat fiscal) :
     *  - 318 réintègre la part de la dotation DE L'EXERCICE écartée par l'art. 39 C ;
     *  - 350 déduit les amortissements différés des exercices antérieurs repris cette année
     *    (« déductions diverses ») — sans elle, la partie B ne bouclait pas dès qu'un report
     *    était consommé : 310 + 318 ne retombait pas sur 352 ;
     *  - 360 porte les DÉFICITS antérieurs imputés. Elle a porté jusqu'en v1.6.7 le report
     *    d'amortissements différés (`previous_deferred`), sous l'intitulé des déficits, et
     *    370/372 recopiaient 352/354 sans rien retrancher.
     *
     * L'imputation suit `FiscalYearService` : dotation de l'exercice d'abord, report ensuite,
     * déficits antérieurs sur le résultat déjà déterminé.
     */
    public function compute2033B(FiscalYear $fy, $properties, int $year, ?array $breakdown = null): array
    {
        $breakdown ??= $this->resultBreakdown($fy, $properties, $year);
        $lines = $breakdown['lines'];
        $capping = $breakdown['capping'];

        $line232 = $lines['218']; // Total produits
        $line264 = $lines['242'] + $lines['244'] + $lines['254']; // Total charges exploitation
        $line270 = $line232 - $line264; // Résultat exploitation
        $line310 = $line270 - $lines['294']; // Résultat comptable

        $fiscalResult = (int) $fy->fiscal_result;
        $imputed = (int) $fy->deficit_imputed;

        return [
            '218' => $lines['218'],
            '218_brut' => $breakdown['income_gross'],
            '232' => $line232,
            '242' => $lines['242'],
            '244' => $lines['244'],
            '254' => $lines['254'],
            '264' => $line264,
            '270' => $line270,
            '294' => $lines['294'],
            '310' => $line310,
            '312' => $line310 > 0 ? $line310 : 0,
            '314' => $line310 < 0 ? abs($line310) : 0,
            '318' => $capping['reintegrated'],
            '350' => $capping['deducted_carried'],
            '352' => $fiscalResult > 0 ? $fiscalResult : 0,
            '354' => $fiscalResult < 0 ? abs($fiscalResult) : 0,
            '360' => $imputed,
            '370' => $fiscalResult > 0 ? $fiscalResult - $imputed : 0,
            '372' => $fiscalResult < 0 ? abs($fiscalResult) : 0,
        ];
    }

    /**
     * Détail du passage des données saisies au résultat fiscal (issue #13).
     *
     * Reprend À L'IDENTIQUE les règles de `FiscalYearService::computeTotals()` — HT pour un
     * bien assujetti à la TVA, quote-part appliquée à la SOMME des charges partagées d'un bien
     * (pas charge par charge, ce qui aurait décalé le total de quelques centimes) — faute de
     * quoi le 2033-B et le résultat de l'exercice diraient deux choses. Le contrôle `resultat`
     * de `checks()` le vérifie à chaque génération.
     *
     * La quote-part des charges partagées d'un bien est répartie entre 242 et 244 ainsi :
     * 244 = partagées de taxe × quote-part (tronqué), 242 = le reste du total partagé retenu.
     * La somme des deux est donc exactement celle de `computeTotals()`.
     *
     * Les montants du plafonnement et des déficits viennent de l'exercice (ils dépendent de la
     * chaîne N-1, pas des seules données de l'année).
     *
     * @return array{
     *     properties: list<array<string, mixed>>,
     *     lines: array{218: int, 242: int, 244: int, 254: int, 294: int},
     *     income_gross: int,
     *     capping: array<string, int>,
     *     deficits: array<string, mixed>,
     * }
     */
    public function resultBreakdown(FiscalYear $fy, $properties, int $year): array
    {
        $lines = ['218' => 0, '242' => 0, '244' => 0, '254' => 0, '294' => 0];
        $incomeGross = 0;
        $rows = [];
        $categoryLabels = Expense::categoryLabels();

        foreach ($properties as $prop) {
            $tvaLiable = $prop->isTvaLiable();
            $amountField = $tvaLiable ? 'amount_ht' : 'amount';
            $quota = (string) $prop->quota_share;

            // Recettes (ligne 218)
            $incomeQuery = $prop->incomes()->whereYear('income_date', $year);
            $gross = (int) (clone $incomeQuery)->sum($amountField);
            $fees = (int) (clone $incomeQuery)->sum('platform_fee');
            $incomeCount = (int) (clone $incomeQuery)->count();
            $incomeGross += (int) (clone $incomeQuery)->sum('amount');

            // Charges (lignes 242 / 244)
            $expenses = $prop->expenses()
                ->whereYear('expense_date', $year)
                ->orderBy('expense_date')
                ->orderBy('id')
                ->get();

            $expenseRows = [];
            $sums = ['dedicated_242' => 0, 'dedicated_244' => 0, 'shared_242' => 0, 'shared_244' => 0];
            foreach ($expenses as $exp) {
                $line = $exp->category === Expense::CATEGORY_PROPERTY_TAX ? '244' : '242';
                $amount = (int) $exp->{$amountField};
                $sums[($exp->is_dedicated ? 'dedicated_' : 'shared_') . $line] += $amount;

                $expenseRows[] = [
                    'date'        => $exp->expense_date?->format('Y-m-d'),
                    'description' => (string) ($exp->description ?? ''),
                    'category'    => trim(preg_replace('/^\X\s*/u', '', $categoryLabels[$exp->category] ?? (string) $exp->category)),
                    'line'        => $line,
                    'amount'      => $amount,
                    'dedicated'   => (bool) $exp->is_dedicated,
                ];
            }

            $sharedTotal = $sums['shared_242'] + $sums['shared_244'];
            $sharedRetained = (int) bcmul((string) $sharedTotal, $quota, 0);
            $sharedRetained244 = (int) bcmul((string) $sums['shared_244'], $quota, 0);
            $sharedRetained242 = $sharedRetained - $sharedRetained244;

            $retained242 = $sums['dedicated_242'] + $sharedRetained242;
            $retained244 = $sums['dedicated_244'] + $sharedRetained244;

            // Emprunts (ligne 294) — intérêts et assurance tronqués séparément, comme computeTotals()
            $loanRows = [];
            $retained294 = 0;
            foreach ($prop->loans as $loan) {
                $interest = $loan->getInterestsForYear($year);
                $insurance = $loan->getInsuranceForYear($year);
                $interestRetained = (int) bcmul((string) $interest, $quota, 0);
                $insuranceRetained = (int) bcmul((string) $insurance, $quota, 0);
                $retained294 += $interestRetained + $insuranceRetained;

                $loanRows[] = [
                    'name'               => (string) ($loan->bank_name ?: 'Emprunt'),
                    'interest'           => $interest,
                    'insurance'          => $insurance,
                    'interest_retained'  => $interestRetained,
                    'insurance_retained' => $insuranceRetained,
                    'retained'           => $interestRetained + $insuranceRetained,
                ];
            }

            // Amortissements (ligne 254) — le détail actif par actif est l'annexe des immobilisations
            $depreciation = (int) $this->depreciationService->calculateAnnualDepreciation($prop, $year)['total'];

            $lines['218'] += $gross - $fees;
            $lines['242'] += $retained242;
            $lines['244'] += $retained244;
            $lines['254'] += $depreciation;
            $lines['294'] += $retained294;

            $rows[] = [
                'name'        => (string) $prop->name,
                'quota_share' => $quota,
                'tva_liable'  => $tvaLiable,
                'income'      => [
                    'count' => $incomeCount,
                    'gross' => $gross,
                    'fees'  => $fees,
                    'net'   => $gross - $fees,
                ],
                'expenses'        => $expenseRows,
                'expense_totals'  => $sums + [
                    'shared_total'        => $sharedTotal,
                    'shared_retained'     => $sharedRetained,
                    'shared_retained_242' => $sharedRetained242,
                    'shared_retained_244' => $sharedRetained244,
                    'retained_242'        => $retained242,
                    'retained_244'        => $retained244,
                ],
                'loans'           => $loanRows,
                'loans_retained'  => $retained294,
                'depreciation'    => $depreciation,
            ];
        }

        $totalDepreciation = (int) $fy->total_depreciation;
        $capped = (int) $fy->capped_depreciation;
        $deductedCurrent = min($totalDepreciation, $capped);

        return [
            'properties'   => $rows,
            'lines'        => $lines,
            'income_gross' => $incomeGross,
            'capping'      => [
                'income'                     => (int) $fy->total_income,
                'expenses'                   => (int) $fy->total_expenses,
                'result_before_depreciation' => (int) $fy->total_income - (int) $fy->total_expenses,
                'depreciation_year'          => $totalDepreciation,
                'carried_forward'            => (int) $fy->previous_deferred,
                'available'                  => $totalDepreciation + (int) $fy->previous_deferred,
                'deducted'                   => $capped,
                'deducted_current'           => $deductedCurrent,
                'deducted_carried'           => $capped - $deductedCurrent,
                'reintegrated'               => $totalDepreciation - $deductedCurrent,
                'deferred'                   => (int) $fy->deferred_depreciation,
                'fiscal_result'              => (int) $fy->fiscal_result,
            ],
            'deficits' => [
                'previous'     => (int) $fy->previous_deficit,
                'imputed'      => (int) $fy->deficit_imputed,
                'carryforward' => (int) $fy->deficit_carryforward,
                'detail'       => is_array($fy->deficit_detail) ? $fy->deficit_detail : [],
            ],
        ];
    }

    /**
     * Contrôles de cohérence entre formulaires — SEULE source de vérité.
     *
     * Fonction pure : les trois tableaux sont déjà calculés, aucun accès base
     * supplémentaire. Le PDF et l'écran de télédéclaration l'appellent tous les deux au
     * lieu de recalculer chacun le sien. Avant ça, le contrôle 572 = 254 vivait en double
     * — `!=` lâche dans la vue PDF, `===` strict dans la page Filament — deux écrans qui
     * pouvaient donc, à terme, dire l'inverse l'un de l'autre sur la même liasse.
     *
     * @param  \Illuminate\Support\Collection<int, Property>|array<int, Property>  $properties
     * @return list<array{id: string, status: string, message: string, delta: int}>
     */
    public function checks(array $form2033A, array $form2033B, array $form2033C, $properties): array
    {
        return [
            $this->checkDotation($form2033B, $form2033C),
            $this->checkImmobilisations($form2033A, $form2033C, $properties),
            $this->checkResultat($form2033B),
        ];
    }

    /**
     * La partie B du 2033-B doit retomber sur le résultat fiscal de l'exercice :
     * 310 + 318 − 350 = 352 − 354.
     *
     * Les lignes 218 à 310 sont recalculées depuis les données saisies, 318 à 354 viennent de
     * l'exercice. Un écart dit donc que les deux se sont désynchronisées — typiquement une
     * donnée modifiée après la clôture, l'exercice clôturé gardant ses totaux figés.
     *
     * @return array{id: string, status: string, message: string, delta: int}
     */
    private function checkResultat(array $form2033B): array
    {
        $rebuilt = $form2033B['310'] + $form2033B['318'] - $form2033B['350'];
        $declared = $form2033B['352'] - $form2033B['354'];
        $delta = $rebuilt - $declared;

        if ($delta === 0) {
            return [
                'id' => 'resultat',
                'status' => self::CHECK_OK,
                'message' => 'Cohérence vérifiée : 310 + 318 − 350 = résultat fiscal ('
                    . self::euros($declared) . ').',
                'delta' => 0,
            ];
        }

        return [
            'id' => 'resultat',
            'status' => self::CHECK_ERROR,
            'message' => 'Écart : 310 + 318 − 350 donne ' . self::euros($rebuilt)
                . ', le résultat fiscal de l\'exercice est de ' . self::euros($declared)
                . ' (' . self::euros($delta) . '). Les données de l\'année ont changé depuis le '
                . 'dernier calcul de l\'exercice : recalculez-le, ou, s\'il est clôturé, '
                . 'vérifiez ce qui a été modifié après la clôture.',
            'delta' => $delta,
        ];
    }

    /**
     * La dotation de l'exercice doit se retrouver à l'identique dans les deux tableaux.
     *
     * @return array{id: string, status: string, message: string, delta: int}
     */
    private function checkDotation(array $form2033B, array $form2033C): array
    {
        $dotation = (int) $form2033C['total_dotation'];
        $resultat = (int) ($form2033B['254'] ?? 0);
        $delta = $dotation - $resultat;

        if ($delta === 0) {
            return [
                'id' => 'dotation',
                'status' => self::CHECK_OK,
                'message' => 'Cohérence vérifiée : ligne 572 = ligne 254 du 2033-B ('
                    . self::euros($dotation) . ').',
                'delta' => 0,
            ];
        }

        return [
            'id' => 'dotation',
            'status' => self::CHECK_ERROR,
            'message' => 'Écart : ligne 572 (' . self::euros($dotation)
                . ') ≠ ligne 254 du 2033-B (' . self::euros($resultat) . ').',
            'delta' => $delta,
        ];
    }

    /**
     * Le total des immobilisations brutes doit concorder entre le bilan et le 2033-C.
     *
     * ⚠️ Ce contrôle n'est pas binaire, et c'est le cœur du sujet (issue #10). L'écart
     * `044 − 490` n'est pas quelconque : tous les autres termes des deux sommes viennent
     * des mêmes expressions et s'annulent au centime, si bien que
     *
     *     044 − 490 = base amortissable − Σ des bases de composants
     *
     * c'est-à-dire **exactement le reliquat de ventilation**. Or le produit ACCEPTE
     * délibérément un reliquat positif depuis l'issue #8 : un comptable peut n'avoir
     * ventilé qu'une partie de la base. Afficher cela en erreur contredirait l'éditeur
     * d'amortissements, qui l'affiche en orange avec « c'est permis, mais cette part ne
     * s'amortira pas ». D'où trois états, et une tolérance empruntée à la règle de
     * troncature plutôt que réinventée.
     *
     * @return array{id: string, status: string, message: string, delta: int}
     */
    private function checkImmobilisations(array $form2033A, array $form2033C, $properties): array
    {
        $bilan = (int) $form2033A['044'];
        $tableau = (int) $form2033C['total_brut'];
        $delta = $bilan - $tableau;

        $tolerance = 0;
        foreach ($properties as $property) {
            $tolerance += $this->depreciationService->truncationTolerance($property);
        }

        if (abs($delta) <= $tolerance) {
            return [
                'id' => 'immobilisations',
                'status' => self::CHECK_OK,
                'message' => 'Cohérence vérifiée : case 044 du 2033-A = ligne 490 du 2033-C ('
                    . self::euros($bilan) . ').',
                'delta' => $delta,
            ];
        }

        if ($delta > 0) {
            return [
                'id' => 'immobilisations',
                'status' => self::CHECK_WARNING,
                'message' => self::euros($delta) . ' de base amortissable ne sont rattachés à '
                    . 'aucun composant : cette part ne s\'amortira pas. C\'est permis — ajustez '
                    . 'la ventilation dans l\'éditeur d\'amortissements si ce n\'est pas voulu.',
                'delta' => $delta,
            ];
        }

        return [
            'id' => 'immobilisations',
            'status' => self::CHECK_ERROR,
            'message' => 'Les composants dépassent la base amortissable de ' . self::euros(abs($delta))
                . ' : la ligne 490 du 2033-C est au-dessus de la case 044 du 2033-A. Vérifiez la '
                . 'valeur retenue et la part du terrain sur la fiche du bien, puis la ventilation.',
            'delta' => $delta,
        ];
    }

    /** Montant en centimes → euros, arrondi comme le PDF (`$fmtInt`). */
    private static function euros(int $cents): string
    {
        return number_format($cents / 100, 0, ',', ' ') . ' €';
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
            $corpBrut += (int) bcmul($prop->referenceValue(), $prop->quota_share, 0);

            // ⚠️ Le classement se fait sur `cerfa_category`, JAMAIS sur le type de ligne.
            // C'est la même règle que celle du 2033-C, et c'est ce qui garantit que les deux
            // formulaires ne peuvent pas ranger un même montant à deux endroits différents.
            // Le cas particulier `type === 'notary'` qui vivait ici jusqu'au 2026-09-08 était
            // la seconde règle, indépendante, qui envoyait les frais d'acquisition en 014
            // pendant que le 2033-C les envoyait en 410 : deux vérités pour un montant.
            foreach ($this->depreciationService->depreciationDetailForYear($prop, $year) as $line) {
                $isIntangible = ($line['cerfa_category'] ?? null) === PropertyComponent::CERFA_CATEGORY_INTANGIBLE;

                // La base des composants immeuble est déjà comprise dans la valeur de
                // référence ci-dessus : seuls travaux, mobilier et frais amortis à part
                // s'y ajoutent.
                $addsToGross = $line['type'] !== 'building';

                if ($isIntangible) {
                    $incorpBrut += $addsToGross ? (int) $line['base'] : 0;
                    $incorpAmort += (int) $line['cumul'];

                    continue;
                }

                if ($addsToGross) {
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
            $land = bcsub(
                bcmul($prop->referenceValue(), $prop->quota_share, 0),
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
     * Le détail des immobilisations, actif par actif — l'annexe qui rend la liasse lisible.
     *
     * Raison d'être : issue #11. Un utilisateur y découvrait une ligne « Immob. incorporelles
     * brut (014) » de 8 900 € que rien, nulle part, ne reliait à ce qu'il avait saisi. Le
     * calcul était juste au centime ; c'est la traçabilité qui manquait, et un montant qu'on
     * ne peut pas remonter jusqu'à sa source est un montant qu'on ne peut pas vérifier.
     *
     * ⚠️ Rendue depuis `depreciationDetailForYear()`, la source qui alimente DÉJÀ le 2033-A
     * et le 2033-C. Une annexe qui recalculerait de son côté pourrait contredire les deux
     * tableaux qu'elle prétend expliquer — c'est le défaut qu'avait le contrôle 572 = 254
     * avant qu'il n'ait une source unique.
     *
     * @return list<array{name: string, origin: string, cerfa: string, base: int, annual: int, cumul: int}>
     */
    public function assetBreakdown($properties, int $year): array
    {
        $origins = [
            'building'  => 'Composant du bien',
            'work'      => 'Travaux',
            'furniture' => 'Mobilier',
            'notary'    => 'Frais d\'acquisition',
        ];

        $cerfaLines = [
            PropertyComponent::CERFA_CATEGORY_INTANGIBLE    => '410 / 500',
            PropertyComponent::CERFA_CATEGORY_CONSTRUCTIONS  => '430 / 520',
            PropertyComponent::CERFA_CATEGORY_INSTALLATIONS  => '440 / 530',
            PropertyComponent::CERFA_CATEGORY_FITTINGS       => '450 / 540',
            PropertyComponent::CERFA_CATEGORY_OTHER          => '470 / 560',
        ];

        $rows = [];

        foreach ($properties as $prop) {
            // Le terrain ne sort d'aucune ligne du détail — il ne s'amortit pas — et c'est
            // précisément la part que l'utilisateur cherche quand il ne retrouve pas son
            // total. On l'écrit donc, à sa place, avant les actifs amortissables.
            $land = (int) bcsub(
                bcmul($prop->referenceValue(), $prop->quota_share, 0),
                $prop->depreciable_base,
                0
            );

            if ($land > 0) {
                $rows[] = [
                    'name'   => 'Terrain (non amortissable)',
                    'origin' => 'Quote-part du bien',
                    'cerfa'  => '420',
                    'base'   => $land,
                    'annual' => 0,
                    'cumul'  => 0,
                ];
            }

            foreach ($this->depreciationService->depreciationDetailForYear($prop, $year) as $line) {
                $rows[] = [
                    'name'   => $line['name'],
                    'origin' => $origins[$line['type']] ?? $line['type'],
                    'cerfa'  => $cerfaLines[$line['cerfa_category'] ?? ''] ?? '470 / 560',
                    // ⚠️ Un composant d'immeuble est DÉJÀ compris dans la valeur de référence
                    // du bien : sa base n'est pas un actif de plus, c'est la ventilation de
                    // celui qui précède. Additionner cette colonne ne rend donc PAS la case
                    // 044 — le terrain et les composants s'y recouvrent — et l'annexe ne
                    // porte volontairement aucun total, pour ne pas suggérer le contraire.
                    'base'   => (int) $line['base'],
                    'annual' => (int) $line['annual'],
                    'cumul'  => (int) $line['cumul'],
                ];
            }
        }

        return $rows;
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
