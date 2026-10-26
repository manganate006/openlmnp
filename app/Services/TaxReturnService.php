<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;
use App\Support\Indivision;
use Barryvdh\DomPDF\Facade\Pdf;

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

    /**
     * Formulaires ajoutés quand le dossier est celui d'une indivision (`Indivision`).
     * Même ancrage que `FORMS` : `TaxReturnFormsTest` compare la vue à leur réunion.
     */
    public const FORMS_INDIVISION = ['2031-bis-SD'];

    /**
     * Ce que contient la liasse DE CE DOSSIER — c'est cette liste qu'on annonce au dehors.
     *
     * @return list<string>
     */
    public static function formsFor(?User $user): array
    {
        return Indivision::isIndivision($user)
            ? [...self::FORMS, ...self::FORMS_INDIVISION]
            : self::FORMS;
    }

    public const CHECK_OK = 'ok';

    /** Situation permise par le produit, mais que l'utilisateur doit savoir. */
    public const CHECK_WARNING = 'warning';

    public const CHECK_ERROR = 'error';

    public function __construct(
        private FiscalYearService $fiscalYearService,
        private DepreciationService $depreciationService,
        private UpdateService $updateService = new UpdateService(),
    ) {}

    /** Nom de fichier proposé au téléchargement. */
    public function filename(FiscalYear $fiscalYear): string
    {
        return "liasse_fiscale_{$fiscalYear->year}.pdf";
    }

    /**
     * Produit la liasse et rend ses octets. **Rien n'est écrit sur le disque.**
     *
     * La liasse était auparavant déposée en clair dans `storage/app/private/tax-returns/`, sur
     * le volume même que le coffre-fort des justificatifs est censé mettre à l'abri — alors
     * qu'elle contient davantage qu'un justificatif : adresses, SIREN, résultats, reports.
     *
     * Elle est intégralement recalculable depuis les données comptables, et l'était déjà à
     * chaque téléchargement (le fichier posé sur le disque était réécrit puis relu dans la
     * même requête, jamais servi plus tard). La supprimer ne perd donc rien.
     *
     * ⚠️ Ne conserve QUE la date de génération, pas le document : garder une trace de ce qui a
     * réellement été déposé à l'administration demanderait un archivage daté et immuable, ce
     * que l'ancien fichier — écrasé à chaque clic et absent des sauvegardes — n'était pas.
     */
    public function renderPdf(FiscalYear $fiscalYear): string
    {
        $data = $this->pdfData($fiscalYear);

        $pdf = Pdf::loadView('pdf.tax-return', $data);
        $pdf->setPaper('A4', 'portrait');

        $fiscalYear->update(['tax_return_generated_at' => now()]);

        return (string) $pdf->output();
    }

    /**
     * Tout ce que la vue `pdf.tax-return` reçoit — séparé de `renderPdf()` pour que le rendu
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
            'form2033A' => null, // ci-dessous : la case 136 reprend la ligne 310 du 2033-B
            'form2033C' => $this->compute2033C($properties, $year),
            'form2033D' => $this->compute2033D($fiscalYear),
            'form2042' => $this->compute2042($fiscalYear),
            'form2031bis' => $this->compute2031bis($fiscalYear, $user),
            'assetBreakdown' => $this->assetBreakdown($properties, $year),
            // La mention de bas de page annonçait « OpenLMNP v0.1 » en dur : un document
            // remis à un tiers doit dire quelle version l'a produit.
            'appVersion' => $this->updateService->getCurrentVersion(),
        ];

        $data['form2033A'] = $this->compute2033A($fiscalYear, $properties, $year, $data['form2033B']['310']);

        // Contrôles sur les CENTIMES (un écart d'un centime y reste visible), formulaires
        // imprimés à l'EURO : voir `wholeEuros()`.
        $data['checks'] = $this->checks($data['form2033A'], $data['form2033B'], $data['form2033C'], $properties, $data['form2031bis']);

        return array_merge($data, $this->wholeEuros($data));
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
     *  - 360 reste À ZÉRO, et 370/372 = 352/354. ⚠️ Un déficit LMNP ne s'impute PAS sur la
     *    liasse : l'administration le pré-remplit en cases 5GA à 5GJ de la 2042-C-PRO et
     *    l'impute elle-même sur les revenus de location meublée du FOYER (CGI art. 156,
     *    I-1° ter). La 5NA reçoit donc le bénéfice AVANT imputation — l'imputer aussi ici,
     *    c'est le déduire deux fois si l'utilisateur reporte la 370.
     *
     *    Historique : jusqu'en v1.6.7 la 360 portait le report d'amortissements différés
     *    (`previous_deferred`) sous l'intitulé des déficits. Une première correction l'avait
     *    remplacé par `deficit_imputed` avec 370 = 352 − 360, jamais publiée : c'est l'erreur
     *    ci-dessus. `deficit_imputed` reste calculé par `FiscalYearService` pour SUIVRE le stock
     *    (annexe, 2033-D), pas pour le déduire.
     *
     * L'imputation des amortissements suit `FiscalYearService` : dotation de l'exercice
     * d'abord, report ensuite.
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
            '360' => 0, // déficits LMNP : cases 5GA à 5GJ de la 2042-C-PRO, jamais ici
            '370' => $fiscalResult > 0 ? $fiscalResult : 0,
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
                // Montant retenu à la part d'usage (#17) ; la quote-part s'applique au total.
                $amount = $exp->usageAdjusted($amountField);
                $sums[($exp->is_dedicated ? 'dedicated_' : 'shared_') . $line] += $amount;

                $expenseRows[] = [
                    'date'        => $exp->expense_date?->format('Y-m-d'),
                    'description' => (string) ($exp->description ?? ''),
                    'category'    => trim(preg_replace('/^\X\s*/u', '', $categoryLabels[$exp->category] ?? (string) $exp->category)),
                    'line'        => $line,
                    'amount'      => $amount,
                    'dedicated'   => (bool) $exp->is_dedicated,
                    'usage_bp'    => $exp->business_use_bp,
                ];
            }

            $sharedTotal = $sums['shared_242'] + $sums['shared_244'];
            $sharedRetained = (int) bcmul((string) $sharedTotal, $quota, 0);
            $sharedRetained244 = (int) bcmul((string) $sums['shared_244'], $quota, 0);
            $sharedRetained242 = $sharedRetained - $sharedRetained244;

            $retained242 = $sums['dedicated_242'] + $sharedRetained242;
            $retained244 = $sums['dedicated_244'] + $sharedRetained244;

            // Emprunts (ligne 294) — intérêts et assurance tronqués séparément, comme computeTotals().
            // Quote-part sauf emprunt dédié (Loan::prorate()) : un prêt qui finance
            // EXCLUSIVEMENT le logement loué (ex : prêt travaux du studio indépendant) est
            // déduit à 100 %, jamais proratisé une seconde fois.
            $loanRows = [];
            $retained294 = 0;
            foreach ($prop->loans as $loan) {
                $interest = $loan->getInterestsForYear($year);
                $insurance = $loan->getInsuranceForYear($year);
                $interestRetained = (int) $loan->prorate((string) $interest, $quota);
                $insuranceRetained = (int) $loan->prorate((string) $insurance, $quota);
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
    public function checks(array $form2033A, array $form2033B, array $form2033C, $properties, ?array $form2031bis = null): array
    {
        $checks = [
            $this->checkDotation($form2033B, $form2033C),
            $this->checkImmobilisations($form2033A, $form2033C, $properties),
            $this->checkResultat($form2033B),
            $this->checkBilan($form2033A, $form2033B),
        ];

        if ($form2031bis !== null) {
            $checks[] = $this->checkIndivision($form2031bis);
        }

        return $checks;
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
     * Le bilan porte le résultat COMPTABLE : case 136 du 2033-A = ligne 310 du 2033-B
     * (issue #20). Jusqu'en v1.6.11 la 136 reprenait le résultat fiscal, si bien que les deux
     * divergeaient dès qu'une part de l'amortissement était différée (art. 39 C).
     *
     * @return array{id: string, status: string, message: string, delta: int}
     */
    private function checkBilan(array $form2033A, array $form2033B): array
    {
        $bilan = (int) $form2033A['136'];
        $resultat = (int) $form2033B['310'];
        $delta = $bilan - $resultat;

        return [
            'id' => 'bilan',
            'status' => $delta === 0 ? self::CHECK_OK : self::CHECK_ERROR,
            'message' => $delta === 0
                ? 'Cohérence vérifiée : case 136 du 2033-A = ligne 310 du 2033-B (' . self::euros($resultat) . ').'
                : 'Écart : case 136 du 2033-A (' . self::euros($bilan) . ') ≠ ligne 310 du 2033-B (' . self::euros($resultat) . ').',
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
     * Les formulaires à l'EURO, comme ils se déclarent (issue #21).
     *
     * Chaque ligne de détail est arrondie, puis chaque total est RECALCULÉ sur les lignes
     * arrondies : un total arrondi pour lui-même pouvait ne plus être la somme de ce qui est
     * imprimé au-dessus. Les montants restent en centimes (multiples de 100), pour que les
     * vues gardent un seul formateur.
     *
     * Ordre imposé par les égalités entre formulaires :
     *  - 2033-C d'abord : la 254 du 2033-B reprend SON total 572, d'où 572 = 254 à l'euro ;
     *  - 2033-B : quand l'art. 39 C joue (318 ou 350 non nulle), le résultat fiscal reste
     *    celui de l'exercice arrondi et l'écart d'arrondi des lignes est porté par la 318 (à
     *    défaut la 350) : un résultat plafonné à 0 € reste à 0 €. Sinon 352/354 = 310, somme
     *    des lignes arrondies. Si la partie B ne bouclait déjà pas au centime (exercice
     *    clôturé modifié après coup, contrôle `resultat` en erreur), 352/354 gardent le
     *    résultat de l'exercice, comme avant l'arrondi ;
     *  - 2033-A : 136 = 310, puis 120 par différence ;
     *  - 2031, 2042-C-PRO et répartition 2031 bis reprennent 352/354 ; le suivi des
     *    amortissements différés (2033-D) reprend 318 et 350.
     *
     * Les contrôles de `checks()` tournent AVANT, sur les centimes.
     *
     * @param  array<string, mixed>  $forms  form2031, form2033A, form2033B, form2033C, form2033D, form2042, form2031bis
     * @return array<string, mixed>
     */
    public function wholeEuros(array $forms): array
    {
        $r = static fn ($cents): int => (int) round(((int) $cents) / 100, 0, PHP_ROUND_HALF_UP) * 100;

        $c = $forms['form2033C'];
        foreach ($c['categories'] as $key => $cat) {
            foreach (['brut', 'dotation', 'cumul'] as $col) {
                $c['categories'][$key][$col] = $r($cat[$col]);
            }
        }
        foreach (['brut', 'dotation', 'cumul'] as $col) {
            $c['total_' . $col] = array_sum(array_column($c['categories'], $col));
        }

        $b = $forms['form2033B'];
        foreach (['218', '218_brut', '242', '244', '294'] as $line) {
            $b[$line] = $r($b[$line]);
        }
        $b['254'] = $c['total_dotation'];
        $b['232'] = $b['218'];
        $b['264'] = $b['242'] + $b['244'] + $b['254'];
        $b['270'] = $b['232'] - $b['264'];
        $b['310'] = $b['270'] - $b['294'];
        $b['312'] = max($b['310'], 0);
        $b['314'] = max(-$b['310'], 0);

        $fiscal = $r($forms['form2033B']['352'] - $forms['form2033B']['354']);
        $b['318'] = $r($b['318']);
        $b['350'] = $r($b['350']);
        if ((int) $forms['form2033B']['318'] !== 0) {
            $b['318'] = max(0, $fiscal - $b['310'] + $b['350']);
        } elseif ((int) $forms['form2033B']['350'] !== 0) {
            $b['350'] = max(0, $b['310'] + $b['318'] - $fiscal);
        }
        $cents = $forms['form2033B'];
        $boucle = $cents['310'] + $cents['318'] - $cents['350'] === $cents['352'] - $cents['354'];
        $fiscal = $boucle ? $b['310'] + $b['318'] - $b['350'] : $fiscal;
        $b['352'] = $b['370'] = max($fiscal, 0);
        $b['354'] = $b['372'] = max(-$fiscal, 0);

        $a = $forms['form2033A'];
        foreach (['014', '016', '028', '030', '156'] as $line) {
            $a[$line] = $r($a[$line]);
        }
        $a['044'] = $a['028'] + $a['014'];
        $a['048'] = $a['030'] + $a['016'];
        $a['112'] = $a['180'] = $a['044'] - $a['048'];
        $a['136'] = $b['310'];
        $a['120'] = $a['112'] - $a['136'] - $a['156'];

        // Cadre « Déficits reportables » : vide en LMNP (cf. `compute2033D()`). Le suivi des
        // amortissements différés reprend la 318 et la 350 imprimées, pour boucler à l'euro.
        $d = $forms['form2033D'];
        $suivi = $d['suivi_39c'];
        $suivi['ouverture'] = $r($suivi['ouverture']);
        $suivi['differes'] = $b['318'];
        $suivi['repris'] = $b['350'];
        $suivi['cloture'] = $suivi['ouverture'] + $suivi['differes'] - $suivi['repris'];
        $d['suivi_39c'] = $suivi;

        $f2031 = $forms['form2031'];
        $f2031['AB'] = $r($f2031['AB']);
        $f2031['CB'] = $b['352'];
        $f2031['CC'] = $b['354'];

        $f2042 = $forms['form2042'];
        $f2042['montant'] = abs($fiscal);
        $f2042['is_benefice'] = $fiscal >= 0;

        $bis = $forms['form2031bis'] ?? null;
        if ($bis !== null) {
            $bis['result'] = $fiscal;
            if ($bis['complete']) {
                // Réparti en euros entiers : la somme des quotes-parts reste le résultat imprimé.
                $parts = Indivision::split(intdiv($fiscal, 100), $bis['rows']);
                foreach ($bis['rows'] as $i => $row) {
                    $part = $parts[$i] * 100;
                    $bis['rows'][$i]['benefice'] = max($part, 0);
                    $bis['rows'][$i]['deficit'] = max(-$part, 0);
                }
            }
        }

        return [
            'form2031' => $f2031,
            'form2033A' => $a,
            'form2033B' => $b,
            'form2033C' => $c,
            'form2033D' => $d,
            'form2042' => $f2042,
            'form2031bis' => $bis,
        ];
    }

    /**
     * 2033-A — Bilan simplifié
     */
    public function compute2033A(FiscalYear $fy, $properties, int $year, ?int $line310 = null): array
    {
        // ⚠️ Case 136 = résultat COMPTABLE (ligne 310 du 2033-B), pas le résultat fiscal
        // (issue #20). Le bilan enregistre l'amortissement en entier (030), le résultat qu'il
        // porte doit donc le déduire en entier, part différée comprise.
        $line310 ??= $this->compute2033B($fy, $properties, $year)['310'];

        $corpBrut = 0;
        $corpAmort = 0;
        $incorpBrut = 0;
        $incorpAmort = 0;
        $emprunts = 0;

        foreach ($properties as $prop) {
            // ⚠️ Immobilisations CORPORELLES brutes = le bien (terrain compris, il reste
            // corporel même s'il ne s'amortit pas) PLUS les travaux et le mobilier. Jusqu'au
            // 2026-09-05 la case 028 ne portait que la valeur de référence du bien : rejouer
            // un dossier de test a montré qu'il y manquait exactement les travaux et le
            // mobilier — 7 610 € sur 188 400 € dans cet exemple —, alors que notre propre 2033-C les liste.
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

            // Emprunts : capital restant dû (quote-part, sauf emprunt dédié)
            foreach ($prop->loans as $loan) {
                $remaining = $loan->getRemainingCapitalAtEndOfYear($year);
                $emprunts += (int) $loan->prorate((string) $remaining, $prop->quota_share);
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
            // Compte de l'exploitant par différence : l'application ne porte ni trésorerie ni
            // créances, tous les flux (apport, charges payées, loyers encaissés, capital
            // remboursé) passent par ce compte. Faux tant que la 136 l'était : il absorbait
            // l'amortissement différé.
            '120' => $totalActif - $line310 - $emprunts,
            '136' => $line310,
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
            // amputé de la part terrain (27 150 € sur 203 900 € dans le dossier de test rejoué
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
     * 2033-D — cadre « Déficits reportables », et suivi des amortissements différés.
     *
     * ⚠️ Le cadre (982, 983, 984, 860, 870) est LAISSÉ VIDE pour un LMNP (issue #19). La notice
     * 2033-NOT-SD le réserve aux entreprises à l'impôt sur les sociétés : à l'impôt sur le
     * revenu, le déficit se reporte sur la déclaration de revenus (cases 5GA à 5GJ de la
     * 2042-C-PRO pour un LMNP), pas sur les BIC suivants. Seule exception prévue : le stock
     * d'ARD de l'ancien régime existant au 1er janvier 2004, sans rapport avec l'art. 39 C
     * d'aujourd'hui.
     *
     * Historique : jusqu'en v1.3.2, 982 à 984 recevaient l'amortissement différé ; de v1.4.0 à
     * la 2.0, elles recevaient les déficits LMNP (984 déficit de l'exercice compris) et la 870
     * l'amortissement différé, sous l'intitulé « Total des déficits restant à reporter ». La
     * notice veut 870 = 984 + 860, et le cadre vide.
     *
     * Les amortissements différés (art. 39 C, II) se reportent sans limite de durée et n'ont
     * pas de case : leur stock est suivi à part (`suivi_39c`), comme l'état de suivi libre que
     * tiennent les cabinets. Clôture = ouverture + différés de l'exercice (318) − repris (350).
     * Beaucoup de cabinets le portent néanmoins en 870 : l'assistant de reprise continue de le
     * lire là, à titre d'indication.
     *
     * @return array{982: int, 983: int, 984: int, 860: int, 870: int, suivi_39c: array{ouverture: int, differes: int, repris: int, cloture: int}}
     */
    public function compute2033D(FiscalYear $fy): array
    {
        $total = (int) $fy->total_depreciation;
        $deductedCurrent = min($total, (int) $fy->capped_depreciation);

        return [
            '982' => 0,
            '983' => 0,
            '984' => 0,
            '860' => 0,
            '870' => 0,
            'suivi_39c' => [
                'ouverture' => (int) $fy->previous_deferred,
                'differes' => $total - $deductedCurrent,
                'repris' => (int) $fy->capped_depreciation - $deductedCurrent,
                'cloture' => (int) $fy->deferred_depreciation,
            ],
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

    /**
     * 2031 bis-SD, cadre E — répartition du résultat entre indivisaires.
     *
     * `null` si le dossier n'est pas une indivision. Le montant réparti est le résultat de la
     * 2031-SD (CB ou CC), signe compris : un déficit se répartit comme un bénéfice, et chaque
     * indivisaire le reporte sur sa 2042-C-PRO, où l'administration l'impute sur ses propres
     * revenus de location meublée (cf. `compute2033B`, case 360).
     *
     * Colonne 2 du cadre : « M » pour des BIC non professionnels (notice de la 2031-SD).
     *
     * @return array{complete: bool, total_share_bp: int, result: int, rows: list<array<string, mixed>>}|null
     */
    public function compute2031bis(FiscalYear $fy, ?User $user = null): ?array
    {
        $user ??= $fy->user;
        if (! Indivision::isIndivision($user)) {
            return null;
        }

        $coOwners = Indivision::coOwners($user);
        $complete = Indivision::isComplete($coOwners);
        $result = (int) $fy->fiscal_result;
        $parts = $complete ? Indivision::split($result, $coOwners) : array_fill(0, count($coOwners), 0);

        $rows = [];
        foreach ($coOwners as $i => $coOwner) {
            $part = $parts[$i];
            $rows[] = $coOwner + [
                'quality' => 'Indivisaire',
                'bic_type' => 'M',
                'benefice' => $part > 0 ? $part : 0,
                'deficit' => $part < 0 ? -$part : 0,
            ];
        }

        return [
            'complete' => $complete,
            'total_share_bp' => Indivision::totalShare($coOwners),
            'result' => $result,
            'rows' => $rows,
        ];
    }

    /**
     * ⚠️ Pas binaire, même logique que `checkImmobilisations()` : un reliquat délibérément
     * accepté (ici, des indivisaires sans adresse ni date de naissance) ne doit pas se
     * confondre avec une répartition fausse. La quote-part incomplète reste une ERREUR — elle
     * fait mentir le montant que chacun reporte sur sa 2042-C-PRO — alors que l'adresse et la
     * naissance manquantes sont permises : elles ne sont demandées QUE pour remplir la case
     * « identité » de la 2031 bis-SD à la main, jamais utilisées dans un calcul.
     */
    private function checkIndivision(array $form2031bis): array
    {
        if (! $form2031bis['complete']) {
            return [
                'id' => 'indivision',
                'status' => self::CHECK_ERROR,
                'delta' => 0,
                'message' => sprintf(
                    'Répartition entre indivisaires incomplète : %d indivisaire(s), quotes-parts totalisant %s %% au lieu de 100 %%. '
                    . 'Complétez-la dans votre profil, rubrique « Indivision ».',
                    count($form2031bis['rows']),
                    Indivision::basisPointsToPercent($form2031bis['total_share_bp']),
                ),
            ];
        }

        $incomplets = array_filter(
            $form2031bis['rows'],
            fn (array $row) => blank($row['address']) || blank($row['birth']),
        );

        if ($incomplets !== []) {
            // Libellé de la maquette écran 3, variante B : nommer chaque indivisaire et ce qui
            // lui manque, plutôt qu'un simple compte — c'est ce qu'il faut aller corriger, et où.
            $details = array_map(static function (array $row): string {
                $manque = [];
                if (blank($row['address'])) {
                    $manque[] = 'adresse';
                }
                if (blank($row['birth'])) {
                    $manque[] = 'date et lieu de naissance';
                }

                return $row['name'] . ' (' . implode(', ', $manque) . ')';
            }, array_values($incomplets));

            return [
                'id' => 'indivision',
                'status' => self::CHECK_WARNING,
                'delta' => 0,
                'message' => sprintf(
                    'Indivisaires à compléter pour la 2031 bis : %s. La notice les demande pour chaque '
                    . 'indivisaire ; les montants restent valables.',
                    implode(', ', $details),
                ),
            ];
        }

        return [
            'id' => 'indivision',
            'status' => self::CHECK_OK,
            'message' => 'Répartition entre indivisaires : les quotes-parts totalisent 100 %, '
                . 'et leur somme égale le résultat de la 2031-SD (' . self::euros($form2031bis['result']) . ').',
            'delta' => 0,
        ];
    }
}
