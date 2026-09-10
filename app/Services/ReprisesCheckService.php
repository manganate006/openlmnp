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
     * Causes probables d'un écart sur une ligne reconstituée, ORDONNÉES PAR FRÉQUENCE.
     * L'ordre est le message : c'est par là qu'il faut commencer à chercher.
     */
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
            'label' => 'Le traitement des frais d\'acquisition n\'est pas celui du cabinet',
            'hint'  => 'Frais de notaire et honoraires d\'agence se traitent de quatre façons, '
                     . 'et le choix se lit sur la fiche du bien. S\'ils manquent au total du '
                     . 'cabinet, il les a passés en charges l\'année de l\'acquisition. S\'ils '
                     . 'y sont mais que le cumul diffère, il les a peut-être intégrés au coût '
                     . 'du bien — donc amortis au rythme des composants, part terrain exclue — '
                     . 'là où nous les amortissons séparément, ou l\'inverse.',
        ],
        'missing_component' => [
            'label' => 'Un composant du plan du cabinet manque ici',
            'hint'  => 'Le plan du cabinet comporte peut-être une ligne que la ventilation '
                     . 'standard ne prévoit pas. Ajoutez-la avec sa base et sa durée.',
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
            $lines[] = $this->buildLine($key, $definition, $declared[$key] ?? null, $computed[$key], $context);
        }

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

        // ⚠️ `close` produit des diagnostics AUSSI, depuis le 2026-09-10. Un écart sous la
        // tolérance restait muet : l'utilisateur lisait « proche » sans un mot d'explication,
        // et n'avait aucun moyen de savoir s'il devait s'en inquiéter. C'est précisément le
        // cas qui reste après la correction d'un double comptage — quelques dizaines d'euros
        // de convention de prorata, inoffensifs, mais que personne ne peut deviner.
        if ($line['verdict'] === self::VERDICT_MISMATCH) {
            $line['diagnostics'] = $definition['reconstituted']
                ? $this->diagnostics($difference, $context)
                : [$this->transcriptionDiagnostic($definition['cerfa'])];
        }

        // ⚠️ Un écart « proche » produit lui aussi un diagnostic depuis le 2026-09-10 — mais
        // UN SEUL, et pas la même liste. Sous 1 %, dérouler six causes possibles serait du
        // bruit : il ne manque pas un composant, la part du terrain n'est pas fausse. Il
        // reste une différence de convention, et c'est la seule chose à dire.
        //
        // Jusque-là ce cas était MUET : l'utilisateur lisait « proche » sans un mot
        // d'explication, et n'avait aucun moyen de savoir s'il devait s'en inquiéter. C'est
        // exactement ce qui subsiste après la correction d'un double comptage.
        if ($line['verdict'] === self::VERDICT_CLOSE && $definition['reconstituted']) {
            $line['diagnostics'] = [$this->conventionDiagnostic()];
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
            'hint'  => 'Sous 1 %, il ne manque rien à votre plan. La première annuité se '
                     . 'proratise ici au JOUR ; beaucoup de cabinets la proratisent au MOIS, '
                     . 'ce qui déplace environ 0,5 % — et l\'écart se reporte ensuite à '
                     . 'l\'identique dans tous les cumuls. Les deux conventions sont admises. '
                     . 'Conservez le chiffre de votre liasse déposée : c\'est lui qui assure '
                     . 'la continuité de votre bilan d\'un exercice à l\'autre.',
            'corroborated' => true,
        ];
    }

    /**
     * Causes probables, dans l'ordre où il faut les chercher.
     *
     * `corroborated` dit que les données de l'application appuient cette piste — c'est un
     * indice, jamais une conclusion : un diagnostic non corroboré reste possible.
     *
     * @param  array<string, int>  $context
     * @return array<int, array{code: string, label: string, hint: string, corroborated: bool}>
     */
    private function diagnostics(int $difference, array $context): array
    {
        $corroboration = [
            // Un bien mis en location dans l'année comparée, ou juste avant, subit un prorata :
            // c'est là que les conventions de date divergent le plus souvent.
            'rental_start_date' => $context['properties_started_recently'] > 0,
            // Une part de terrain nulle est presque toujours une reprise incomplète : un
            // cabinet en retient une, le terrain n'étant pas amortissable.
            'land_share' => $context['properties_without_land_share'] > 0,
            // Écart du même ordre que les frais d'acquisition amortis ici : la piste la plus
            // parlante de toutes, parce qu'elle se chiffre.
            //
            // DEUX références, et il en faut deux : sur la case 028 (valeur BRUTE) l'écart
            // vaut les frais entiers, mais sur la case 030 (amortissements CUMULÉS) il ne
            // vaut que ce qui en a été amorti à ce jour — 4 213 € pour 16 000 € de frais
            // sur un bien loué depuis 2019. Ne comparer qu'aux frais bruts laissait donc
            // la piste NON corroborée sur la ligne 030, c'est-à-dire précisément dans le
            // cas que la maquette donne comme le plus fréquent.
            'acquisition_fees' => ($context['acquisition_fees'] > 0
                    && $this->withinOnePercent(abs($difference), $context['acquisition_fees']))
                || ($context['acquisition_fees_depreciated'] > 0
                    && $this->withinOnePercent(abs($difference), $context['acquisition_fees_depreciated'])),
            // Le cabinet déclare PLUS que ce qu'on reconstitue : il nous manque quelque chose.
            'missing_component' => $difference > 0,
            'market_value' => $context['properties_using_market_value'] > 0,
        ];

        $diagnostics = [];

        foreach (self::DIAGNOSTICS as $code => $diagnostic) {
            $diagnostics[] = [
                'code'         => $code,
                'label'        => $diagnostic['label'],
                'hint'         => $diagnostic['hint'],
                'corroborated' => $corroboration[$code],
            ];
        }

        return $diagnostics;
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
        ];
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function worstVerdict(array $lines): string
    {
        $ranking = [
            self::VERDICT_UNCHECKED => 0,
            self::VERDICT_MATCH     => 1,
            self::VERDICT_CLOSE     => 2,
            self::VERDICT_MISMATCH  => 3,
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
