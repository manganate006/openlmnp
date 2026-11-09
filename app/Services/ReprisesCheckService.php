<?php

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\Property;

/**
 * Contrôle d'une reprise de dossier : ce que le cabinet a déclaré, face à ce que
 * l'application reconstitue.
 *
 * C'EST LA FONCTIONNALITÉ, pas un écran de confort. Sans ce contrôle, un bailleur qui recopie
 * sa liasse ne saura jamais si sa reprise est juste, et il n'osera pas quitter son cabinet.
 *
 * La comparaison porte sur l'exercice PRÉCÉDANT la reprise — le dernier que le cabinet a
 * bouclé — et sur cinq lignes, chacune identifiée par la case Cerfa où la lire :
 *
 *   2033-A 028  immobilisations brutes        reconstituées
 *   2033-A 030  amortissements cumulés        reconstitués
 *   2033-D 870  amortissements différés       recopiés (contrôle de transcription)
 *   2033-D 984  déficits restant à reporter   recopiés (contrôle de transcription)
 *   2033-B 352/354 résultat                   informatif : rien à reconstituer sans l'exercice
 *
 * ⚠️ Écart assumé avec le cahier des charges sur la case 028. Le CDC prévoyait de la
 * reconstituer par la SOMME DES BASES AMORTISSABLES ; ce serait une fausse alerte
 * systématique, la 028 d'un cabinet incluant le terrain, qui n'est pas amortissable. On
 * compare donc à ce que l'application imprimerait dans la même case (`compute2033A()`), et la
 * somme des bases reste disponible dans `context.amortisable_base`.
 *
 * Ne rend qu'un DIAGNOSTIC : ce service n'écrit rien et ne corrige rien.
 */
class ReprisesCheckService
{
    /** Écart ≤ 1 € : les liasses sont arrondies à l'euro. */
    public const VERDICT_MATCH = 'match';

    /**
     * Écart chiffré et ENTIÈREMENT expliqué par une différence de présentation qui ne coûte
     * pas un euro : les frais d'acquisition amortis en 030 chez nous, en 016 chez le cabinet,
     * avec un total brut (044) identique des deux côtés. Rien à corriger, rien à vérifier.
     *
     * ⚠️ Né le 2026-10-15 : cet écart s'affichait « à vérifier », et le seul bouton proposé
     * passait les frais en charges. Un utilisateur qui l'aurait suivi aurait retiré ses frais
     * des immobilisations (l'écart passait alors sur la 044) et perdu leur dotation annuelle,
     * alors que son cabinet les amortit.
     */
    public const VERDICT_EXPLAINED = 'explained';

    /** Écart ≤ 1 % : probablement une convention d'arrondi ou de prorata. */
    public const VERDICT_CLOSE = 'close';

    /** Au-delà : quelque chose ne correspond pas, et le diagnostic dit quoi chercher. */
    public const VERDICT_MISMATCH = 'mismatch';

    /** Ligne non renseignée sur la liasse : rien à comparer. */
    public const VERDICT_UNCHECKED = 'unchecked';

    public const TOLERANCE_CENTS = 100;
    public const TOLERANCE_RATIO = '0.01';

    public const LINE_GROSS_ASSETS = 'gross_assets';
    public const LINE_ACCUMULATED_DEPRECIATION = 'accumulated_depreciation';
    public const LINE_DEFERRED_DEPRECIATION = 'deferred_depreciation';
    public const LINE_DEFICIT_CARRYFORWARD = 'deficit_carryforward';
    public const LINE_FISCAL_RESULT = 'fiscal_result';

    /**
     * Causes possibles sur chaque ligne reconstituée, ORDONNÉES PAR FRÉQUENCE.
     *
     * ⚠️ Une cause qui ne PEUT PAS jouer sur une ligne n'y figure pas. Un prorata de première
     * année ou une part de terrain ne changent pas une valeur BRUTE : la case 044 porte le
     * terrain et ignore les dotations. Les afficher en tête de l'écart sur 044, comme
     * jusqu'au 2026-10-15, envoyait l'utilisateur sur une piste impossible.
     *
     * L'ordre de fréquence n'est qu'un départage : `diagnostics()` place d'abord les causes
     * corroborées par un MONTANT, puis celles corroborées par une simple condition.
     */
    private const LINE_DIAGNOSTICS = [
        self::LINE_GROSS_ASSETS => ['acquisition_fees_expensed', 'missing_component', 'market_value'],
        self::LINE_ACCUMULATED_DEPRECIATION => [
            'rental_start_date', 'land_share', 'acquisition_fees', 'missing_component', 'market_value',
        ],
    ];

    /** Corroboration d'une cause : par un montant qui retombe sur l'écart, ou par une simple condition. */
    private const EVIDENCE_AMOUNT = 'amount';
    private const EVIDENCE_CONDITION = 'condition';

    private const DIAGNOSTICS = [
        'rental_start_date' => [
            'label' => 'La date de mise en location retenue n\'est pas celle du cabinet',
            'hint'  => 'Une année de départ décalée change le prorata de la première annuité, '
                     . 'et donc tout le cumul. Comparez la date de mise en location du bien à '
                     . 'celle du plan d\'amortissement du cabinet.',
        ],
        'land_share' => [
            'label' => 'La part du terrain diffère',
            'hint'  => 'Le terrain n\'est pas amortissable : une quote-part de 10 % au lieu de '
                     . '15 % déplace des dizaines de milliers d\'euros de base amortissable.',
        ],
        'acquisition_fees' => [
            'label' => 'Les frais d\'acquisition ne sont pas rangés sur la même ligne que votre cabinet',
            'hint'  => 'L\'écart vaut ce qui a été amorti de vos frais de notaire et d\'agence. '
                     . 'OpenLMNP les range en immobilisations corporelles : ils sont amortis '
                     . 'en 030. Beaucoup de cabinets les rangent en immobilisations '
                     . 'incorporelles : ils sont alors amortis en 016, et la case 030 ne les '
                     . 'contient pas. Le total des amortissements (048) est le même des deux '
                     . 'côtés, ce n\'est pas une erreur. Pour en être sûr, renseignez la case '
                     . '044 à l\'étape 4 : si elle tombe juste, l\'écart est expliqué ; si elle '
                     . 'diffère du montant de vos frais, votre cabinet les a passés en charges.',
        ],
        'acquisition_fees_presentation' => [
            'label' => 'Vos frais d\'acquisition sont amortis en 030 ici, en 016 chez votre cabinet',
            'hint'  => 'Le total des immobilisations brutes (044) est le même des deux côtés, et '
                     . 'l\'écart sur 030 vaut exactement ce qui a été amorti de vos frais de '
                     . 'notaire et d\'agence. Ils sont donc amortis des deux côtés, simplement '
                     . 'rangés en immobilisations corporelles ici et incorporelles chez votre '
                     . 'cabinet. Le total des amortissements (048) ne change pas, ni la dotation '
                     . 'de chaque année.',
        ],
        'acquisition_fees_expensed' => [
            'label' => 'Votre cabinet a passé les frais d\'acquisition en charges',
            'hint'  => 'L\'écart vaut vos frais de notaire et d\'agence : ils ne figurent pas '
                     . 'parmi les immobilisations de votre liasse. Les frais d\'acquisition '
                     . 'se déduisent soit en charges l\'année de l\'achat, soit en les '
                     . 'amortissant avec le bien, et votre cabinet a fait le premier choix. Ce '
                     . 'choix est fait une fois pour toutes : si votre liasse le confirme, '
                     . 'retenez le même traitement ici, sans quoi ces frais seraient déduits '
                     . 'une seconde fois.',
        ],
        'missing_component' => [
            'label' => 'Des travaux ou du mobilier déjà immobilisés manquent au plan',
            'hint'  => 'Le plan du cabinet comporte peut-être une ligne que la ventilation '
                     . 'standard ne prévoit pas, mais aussi des travaux ou du mobilier déjà '
                     . 'amortis, que cet assistant ne reprend pas lui-même : ajoutez-les '
                     . 'depuis les pages « Travaux » et « Mobilier », avec leur base, leur '
                     . 'date et leur durée, puis rejouez ce contrôle.',
        ],
        'market_value' => [
            'label' => 'La valeur retenue n\'est pas le prix d\'acquisition',
            'hint'  => 'Une valeur vénale a été retenue au lieu du prix payé (ou l\'inverse). '
                     . 'Les deux se défendent, mais il faut la même des deux côtés.',
        ],
    ];

    public function __construct(
        private DepreciationService $depreciationService,
        private TaxReturnService $taxReturnService,
        private FiscalYearService $fiscalYearService,
    ) {}

    /**
     * @param  FiscalYear  $repriseYear  L'exercice de reprise (celui qui porte les soldes d'ouverture)
     * @param  array<string, int|null>  $declared  Les montants lus sur la liasse, en centimes.
     *                                             Clés : les constantes LINE_*. Une clé absente
     *                                             ou nulle n'est pas comparée.
     * @return array{
     *     year: int,
     *     verdict: string,
     *     warning: string|null,
     *     lines: array<int, array<string, mixed>>,
     *     context: array<string, int>
     * }
     */
    public function check(FiscalYear $repriseYear, array $declared): array
    {
        $comparedYear = $repriseYear->year - 1;

        $properties = Property::withoutGlobalScopes()
            ->where('user_id', $repriseYear->user_id)
            ->get();

        $context = $this->buildContext($properties, $comparedYear);

        // Même source que la liasse générée par l'application : le contrôle et la liasse ne
        // peuvent donc pas raconter deux histoires différentes.
        $form2033A = $this->taxReturnService->compute2033A($repriseYear, $properties, $comparedYear);

        // ⚠️ Le TOTAL (044), pas la seule case 028 : depuis que les frais d'acquisition
        // peuvent légitimement se présenter en corporelles (chez nous) ou en incorporelles
        // (chez certains cabinets), 028 dépend d'une convention de présentation alors que
        // 044 n'en dépend pas. Comparer 028 faisait apparaître en rouge une différence de
        // présentation qui ne coûte pas un euro — et la ligne 014 qui servait à la révéler
        // faisait alors DEUX lignes rouges pour un seul et même fait.
        $computed = [
            self::LINE_GROSS_ASSETS             => (int) $form2033A['044'],
            self::LINE_ACCUMULATED_DEPRECIATION => (int) $form2033A['030'],
            self::LINE_DEFERRED_DEPRECIATION    => (int) $repriseYear->opening_deferred_depreciation,
            self::LINE_DEFICIT_CARRYFORWARD     => $repriseYear->openingDeficitsTotal(),
            self::LINE_FISCAL_RESULT            => null, // informatif : rien à reconstituer
        ];

        $lines = [];
        foreach ($this->lineDefinitions() as $key => $definition) {
            $lines[$key] = $this->buildLine($key, $definition, $declared[$key] ?? null, $computed[$key], $context);

            // Le total brut (044) départage les deux lectures d'un écart sur 030 égal au
            // cumul des frais : rangés en 016 chez le cabinet (044 identique), ou passés en
            // charges (044 en écart du montant des frais). D'où son rôle dans le contexte.
            if ($key === self::LINE_GROSS_ASSETS) {
                $context['gross_assets_agree'] = in_array(
                    $lines[$key]['verdict'],
                    [self::VERDICT_MATCH, self::VERDICT_CLOSE],
                    true,
                ) ? 1 : 0;
            }
        }

        $lines = array_values($lines);

        return [
            'year'    => $comparedYear,
            'verdict' => $this->worstVerdict($lines),
            'warning' => $this->fiscalYearService->openingBalanceWarning($repriseYear),
            'lines'   => $lines,
            'context' => $context,
        ];
    }

    /** @return array<string, array{cerfa: string, label: string, reconstituted: bool}> */
    private function lineDefinitions(): array
    {
        return [
            self::LINE_GROSS_ASSETS => [
                'cerfa' => '2033-A case 044',
                'label' => 'Total des immobilisations brutes',
                'reconstituted' => true,
            ],
            self::LINE_ACCUMULATED_DEPRECIATION => [
                'cerfa' => '2033-A case 030',
                'label' => 'Amortissements cumulés',
                'reconstituted' => true,
            ],
            self::LINE_DEFERRED_DEPRECIATION => [
                'cerfa' => '2033-D case 870',
                'label' => 'Amortissements différés reportables',
                'reconstituted' => false,
            ],
            self::LINE_DEFICIT_CARRYFORWARD => [
                'cerfa' => '2033-D case 984',
                'label' => 'Déficits restant à reporter',
                'reconstituted' => false,
            ],
            self::LINE_FISCAL_RESULT => [
                'cerfa' => '2033-B cases 352 / 354',
                'label' => 'Résultat fiscal de l\'exercice',
                'reconstituted' => false,
            ],
        ];
    }

    /**
     * @param  array{cerfa: string, label: string, reconstituted: bool}  $definition
     * @param  array<string, int>  $context
     * @return array<string, mixed>
     */
    private function buildLine(string $key, array $definition, ?int $declared, ?int $computed, array $context): array
    {
        $line = [
            'key'           => $key,
            'cerfa'         => $definition['cerfa'],
            'label'         => $definition['label'],
            'declared'      => $declared,
            'computed'      => $computed,
            'difference'    => null,
            'ratio'         => null,
            'verdict'       => self::VERDICT_UNCHECKED,
            'diagnostics'   => [],
        ];

        if ($declared === null || $computed === null) {
            return $line;
        }

        $difference = $declared - $computed;
        $line['difference'] = $difference;
        $line['ratio'] = $this->ratio($difference, $declared, $computed);
        $line['verdict'] = $this->verdict($difference, $line['ratio']);

        if (! $definition['reconstituted']) {
            if ($line['verdict'] === self::VERDICT_MISMATCH) {
                $line['diagnostics'] = [$this->transcriptionDiagnostic($definition['cerfa'])];
            }

            return $line;
        }

        // L'écart sur 030 vaut le cumul des frais d'acquisition ET le total brut 044 tombe
        // juste : les frais sont amortis des deux côtés, simplement rangés en 016 chez le
        // cabinet. C'est une explication, pas un doute, quel que soit le pourcentage.
        if ($key === self::LINE_ACCUMULATED_DEPRECIATION
            && in_array($line['verdict'], [self::VERDICT_CLOSE, self::VERDICT_MISMATCH], true)
            && ($context['gross_assets_agree'] ?? 0) === 1
            && $this->acquisitionFeesCorroborated($difference, $context)) {
            $line['verdict'] = self::VERDICT_EXPLAINED;
            $line['diagnostics'] = [$this->namedDiagnostic('acquisition_fees_presentation', self::EVIDENCE_AMOUNT)];

            return $line;
        }

        if ($line['verdict'] === self::VERDICT_MISMATCH) {
            $line['diagnostics'] = $this->diagnostics($key, $difference, $context);
        }

        // ⚠️ Un écart « proche » produit un diagnostic depuis le 2026-09-10, mais UN SEUL,
        // et pas la même liste. Sous 1 %, dérouler six causes possibles serait du bruit : il
        // reste une différence de convention, et c'est la seule chose à dire, SAUF quand le
        // rangement des frais d'acquisition corrobore l'écart : ce n'est alors plus une
        // convention de prorata, et le dire aurait envoyé un dossier réel sur une fausse
        // piste (le 2026-10-15, un écart entièrement expliqué par 030 chez nous contre 016
        // chez le cabinet a été pris pour de l'arrondi).
        //
        // La convention de prorata ne concerne que les CUMULS : sur la valeur brute (044),
        // un écart, même faible, ne peut venir que d'un montant présent d'un côté et pas de
        // l'autre. Ce sont les causes de la ligne qu'il faut alors montrer.
        if ($line['verdict'] === self::VERDICT_CLOSE) {
            $line['diagnostics'] = match (true) {
                $key !== self::LINE_ACCUMULATED_DEPRECIATION => $this->diagnostics($key, $difference, $context),
                $this->acquisitionFeesCorroborated($difference, $context) => [
                    $this->namedDiagnostic('acquisition_fees', self::EVIDENCE_AMOUNT),
                ],
                default => [$this->conventionDiagnostic()],
            };
        }

        return $line;
    }

    /** Rapport de l'écart à la valeur déclarée, en décimal (chaîne bcmath). */
    private function ratio(int $difference, int $declared, int $computed): string
    {
        $reference = max(abs($declared), abs($computed));

        if ($reference === 0) {
            return '0';
        }

        return bcdiv((string) abs($difference), (string) $reference, 6);
    }

    private function verdict(int $difference, string $ratio): string
    {
        if (abs($difference) <= self::TOLERANCE_CENTS) {
            return self::VERDICT_MATCH;
        }

        if (bccomp($ratio, self::TOLERANCE_RATIO, 6) <= 0) {
            return self::VERDICT_CLOSE;
        }

        return self::VERDICT_MISMATCH;
    }

    /**
     * Le seul diagnostic d'un écart sous la tolérance : une différence de convention.
     *
     * ⚠️ Ne PAS écrire « arrondi ». La troncature au centime de
     * `DepreciationService::annualFromBase()` plafonne à un centime par ligne et par
     * exercice — quelques dizaines de centimes sur un plan entier, jamais des dizaines
     * d'euros. La cause tient à la convention de PRORATA de première année : nous comptons
     * en jours (BOI-BIC-AMT-20-10 § 20), beaucoup de cabinets comptent en mois, et l'écart
     * qui en résulte est d'environ 0,5 % — définitif, puisqu'il se retrouve à l'identique
     * dans tous les cumuls suivants.
     *
     * ⚠️ Et c'est le chiffre du CABINET qui fait foi, pas le nôtre : le sien est déposé.
     *
     * @return array{code: string, label: string, hint: string, corroborated: bool}
     */
    private function conventionDiagnostic(): array
    {
        return [
            'code'  => 'prorata_convention',
            'label' => 'Une convention de calcul diffère, sans que l\'un des deux chiffres soit faux',
            'evidence' => self::EVIDENCE_CONDITION,
            'hint'  => 'Sous 1 %, il ne manque rien à votre plan. La première annuité se '
                     . 'proratise ici au JOUR ; beaucoup de cabinets la proratisent au MOIS, '
                     . 'ce qui déplace environ 0,5 %, et l\'écart se reporte ensuite à '
                     . 'l\'identique dans tous les cumuls. Les deux conventions sont admises. '
                     . 'Conservez le chiffre de votre liasse déposée : c\'est lui qui assure '
                     . 'la continuité de votre bilan d\'un exercice à l\'autre.',
            'corroborated' => true,
        ];
    }

    /**
     * Causes possibles d'un écart sur une ligne, dans l'ordre où il faut les chercher.
     *
     * `corroborated` dit que les données de l'application appuient cette piste — c'est un
     * indice, jamais une conclusion : un diagnostic non corroboré reste possible.
     *
     * ⚠️ L'ORDRE : d'abord les causes corroborées par un MONTANT qui retombe sur l'écart,
     * puis celles corroborées par une simple CONDITION, puis les autres, chacune dans l'ordre
     * de fréquence. Jusqu'au 2026-10-15 seul cet ordre de fréquence comptait : la date de
     * mise en location passait en tête dès qu'un bien avait démarré dans les deux dernières
     * années, c'est-à-dire pour toute reprise d'un premier exercice, devant les frais
     * d'acquisition dont le montant expliquait pourtant l'écart à l'euro près.
     *
     * @param  array<string, int>  $context
     * @return array<int, array{code: string, label: string, hint: string, corroborated: bool, evidence: string|null}>
     */
    private function diagnostics(string $key, int $difference, array $context): array
    {
        $evidence = [
            // Un bien mis en location dans l'année comparée, ou juste avant, subit un prorata :
            // c'est là que les conventions de date divergent le plus souvent.
            'rental_start_date' => $context['properties_started_recently'] > 0 ? self::EVIDENCE_CONDITION : null,
            // Une part de terrain nulle est presque toujours une reprise incomplète : un
            // cabinet en retient une, le terrain n'étant pas amortissable.
            'land_share' => $context['properties_without_land_share'] > 0 ? self::EVIDENCE_CONDITION : null,
            // Sur 030, l'écart vaut ce qui a été amorti des frais à ce jour (et non les frais
            // entiers) : c'est la mesure qui se chiffre.
            'acquisition_fees' => $this->acquisitionFeesCorroborated($difference, $context)
                ? self::EVIDENCE_AMOUNT
                : null,
            // Sur 044, des frais passés en charges manquent EN ENTIER : l'écart vaut leur
            // montant brut, et le cabinet déclare moins que nous.
            'acquisition_fees_expensed' => $difference < 0
                && $context['acquisition_fees'] > 0
                && $this->withinOnePercent(abs($difference), $context['acquisition_fees'])
                ? self::EVIDENCE_AMOUNT
                : null,
            // Le cabinet déclare PLUS que ce qu'on reconstitue : il nous manque quelque chose.
            'missing_component' => $difference > 0 ? self::EVIDENCE_CONDITION : null,
            'market_value' => $context['properties_using_market_value'] > 0 ? self::EVIDENCE_CONDITION : null,
        ];

        $strength = fn (?string $kind) => match ($kind) {
            self::EVIDENCE_AMOUNT => 0,
            self::EVIDENCE_CONDITION => 1,
            default => 2,
        };

        $codes = self::LINE_DIAGNOSTICS[$key] ?? [];

        // Tri stable : à force égale, l'ordre de fréquence de LINE_DIAGNOSTICS départage.
        $order = array_flip($codes);
        usort($codes, fn (string $a, string $b) => [$strength($evidence[$a]), $order[$a]]
            <=> [$strength($evidence[$b]), $order[$b]]);

        return array_map(fn (string $code) => $this->namedDiagnostic($code, $evidence[$code]), $codes);
    }

    /**
     * L'écart correspond-il à ce que les frais d'acquisition expliquent à eux seuls ?
     *
     * Partagée entre la liste des 5 causes (verdict « à vérifier ») et le diagnostic unique
     * d'un écart « proche » : la même mesure fait foi des deux côtés, sinon un écart pourrait
     * être dit « corroboré par les frais » à un endroit et « convention de prorata » à
     * l'autre pour un seul et même dossier.
     */
    private function acquisitionFeesCorroborated(int $difference, array $context): bool
    {
        // Le cabinet déclare MOINS que nous sur 030 : ses frais sont en 016, ou en charges.
        // Dans l'autre sens, les frais n'expliquent rien.
        return $difference < 0
            && $context['acquisition_fees_depreciated'] > 0
            && $this->withinOnePercent(abs($difference), $context['acquisition_fees_depreciated']);
    }

    /** @return array{code: string, label: string, hint: string, corroborated: bool, evidence: string|null} */
    private function namedDiagnostic(string $code, ?string $evidence): array
    {
        return [
            'code'         => $code,
            'label'        => self::DIAGNOSTICS[$code]['label'],
            'hint'         => self::DIAGNOSTICS[$code]['hint'],
            'corroborated' => $evidence !== null,
            'evidence'     => $evidence,
        ];
    }

    /** @return array{code: string, label: string, hint: string, corroborated: bool} */
    private function transcriptionDiagnostic(string $cerfa): array
    {
        return [
            'code'         => 'transcription',
            'label'        => 'Le montant saisi ne correspond pas à la case lue sur la liasse',
            'hint'         => 'Cette ligne n\'est pas reconstituée : elle compare ce que vous avez '
                            . 'saisi à ce que vous lisez en ' . $cerfa . '. Un écart est une faute '
                            . 'de recopie, ou une case lue au mauvais endroit.',
            'corroborated' => true,
            'evidence'     => self::EVIDENCE_CONDITION,
        ];
    }

    private function withinOnePercent(int $value, int $reference): bool
    {
        if ($reference === 0) {
            return false;
        }

        return bccomp(
            bcdiv((string) abs($value - $reference), (string) $reference, 6),
            self::TOLERANCE_RATIO,
            6,
        ) <= 0;
    }

    /**
     * Faits mesurables sur le dossier, qui servent à corroborer un diagnostic.
     *
     * @param  \Illuminate\Support\Collection<int, Property>  $properties
     * @return array<string, int>
     */
    private function buildContext($properties, int $comparedYear): array
    {
        $amortisableBase = '0';
        $acquisitionFees = '0';
        $feesDepreciated = '0';
        $withoutLandShare = 0;
        $usingMarketValue = 0;
        $startedRecently = 0;

        foreach ($properties as $property) {
            foreach ($this->depreciationService->depreciationDetailForYear($property, $comparedYear) as $detail) {
                $amortisableBase = bcadd($amortisableBase, (string) $detail['base'], 0);

                // Part des frais d'acquisition DÉJÀ AMORTIE à la clôture comparée.
                if (($detail['type'] ?? null) === 'notary') {
                    $feesDepreciated = bcadd($feesDepreciated, (string) $detail['cumul'], 0);
                }
            }

            $fees = bcadd((string) $property->notary_fees, (string) $property->agency_fees, 0);
            $acquisitionFees = bcadd(
                $acquisitionFees,
                bcmul($fees, $property->quota_share, 0),
                0,
            );

            if ((int) $property->land_percentage === 0) {
                $withoutLandShare++;
            }

            if ($property->market_value !== null) {
                $usingMarketValue++;
            }

            if ((int) $property->rental_start_date->format('Y') >= $comparedYear - 1) {
                $startedRecently++;
            }
        }

        return [
            'amortisable_base'                => (int) $amortisableBase,
            'acquisition_fees'                => (int) $acquisitionFees,
            'acquisition_fees_depreciated'    => (int) $feesDepreciated,
            'properties_without_land_share'   => $withoutLandShare,
            'properties_using_market_value'   => $usingMarketValue,
            'properties_started_recently'     => $startedRecently,
            // Posé par `check()` une fois la ligne 044 comparée.
            'gross_assets_agree'              => 0,
        ];
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function worstVerdict(array $lines): string
    {
        $ranking = [
            self::VERDICT_UNCHECKED => 0,
            self::VERDICT_MATCH     => 1,
            self::VERDICT_EXPLAINED => 2,
            self::VERDICT_CLOSE     => 3,
            self::VERDICT_MISMATCH  => 4,
        ];

        $worst = self::VERDICT_UNCHECKED;

        foreach ($lines as $line) {
            if ($ranking[$line['verdict']] > $ranking[$worst]) {
                $worst = $line['verdict'];
            }
        }

        return $worst;
    }
}
