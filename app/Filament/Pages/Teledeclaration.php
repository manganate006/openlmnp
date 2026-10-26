<?php

namespace App\Filament\Pages;

use App\Filament\Actions\RecordTransmissionAction;
use App\Filament\Actions\SireneCheckAction;
use App\Filament\Pages\Concerns\NavigationAware;
use App\Filament\Pages\Concerns\ShowsDiagnosticReport;
use App\Models\FiscalYear;
use App\Models\Property;
use App\Services\CsvExportService;
use App\Services\FiscalYearService;
use App\Services\TaxReturnService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use UnitEnum;

class Teledeclaration extends Page
{
    use NavigationAware;
    use ShowsDiagnosticReport;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;
    protected static string | UnitEnum | null $navigationGroup = 'Fiscal';
    protected static ?string $navigationLabel = 'Télédéclaration';
    protected static ?string $title = 'Aide à la télédéclaration';
    protected static ?int $navigationSort = 4;

    protected static function getGuidedNavigationGroup(): string
    {
        return 'Déclaration annuelle';
    }
    protected string $view = 'filament.pages.teledeclaration';

    public int $year = 2026;

    public function mount(): void
    {
        $this->year = (int) (request()->query('year', date('Y')));
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->diagnosticReportAction(),
            // Le dépôt se note là où il vient d'être fait : c'est au retour d'impots.gouv.fr,
            // l'accusé sous les yeux, que le numéro est disponible — pas plus tard.
            RecordTransmissionAction::forYear(fn () => $this->fiscalYearForYear)
                ->after(fn () => $this->resetTransmission()),
            // Proposée seulement si un SIREN est déjà renseigné : sans lui, il n'y a rien
            // à vérifier, et le bouton ne doit pas envoyer chercher une saisie vide.
            SireneCheckAction::make(
                fn () => auth()->user()->siren,
                fn () => filled(auth()->user()->siren),
            ),
        ];
    }

    /**
     * L'exercice affiché, s'il existe déjà.
     *
     * Volontairement sans `getOrCreate()` : ouvrir le sélecteur d'année ne doit pas créer
     * d'exercice, et un exercice qui n'existe pas n'a rien pu déposer.
     */
    #[Computed]
    public function fiscalYearForYear(): ?FiscalYear
    {
        return FiscalYear::query()->where('year', $this->year)->first();
    }

    private function resetTransmission(): void
    {
        unset($this->fiscalYearForYear);
    }

    /** Le rapport de diagnostic décrit l'exercice affiché, pas l'année en cours. */
    protected function diagnosticReportYear(): int
    {
        return $this->year;
    }

    /**
     * Faut-il prévenir que le 2033-D a changé de règle ?
     *
     * Les cases 982/983/984 suivaient l'amortissement réputé différé au lieu des déficits :
     * une liasse déjà téléchargée ou déjà transmise porte donc des déficits qui n'existent pas.
     * Corriger sans le dire laisserait l'utilisateur devant deux liasses contradictoires sans
     * savoir laquelle croire — l'encart n'apparaît que pour ceux qui sont concernés.
     */
    #[Computed]
    public function showsDeficitCorrectionNotice(): bool
    {
        // BelongsToUserScope limite déjà la requête à l'utilisateur courant.
        //
        // ⚠️ On ne teste QUE la date de génération, et c'est la branche « fichiers en clair »
        // qui l'impose : elle cesse d'écrire le PDF sur le disque, donc `pdf_path` n'est plus
        // renseigné et l'ancien test ne trouverait plus personne. L'encart s'adresse à qui a
        // DÉJÀ imprimé une liasse avec l'ancien calcul, transmise ou non — `transmitted_at`
        // ne couvrirait que les déclarations déposées et raterait exactement ceux qui ont un
        // PDF faux entre les mains.
        //
        // ⚠️ RÉSOLUTION DE FUSION (2026-09-11) : `tax_return_generated_at` l'emporte sur le
        // `pdf_path` venu de `main`, et ce n'est pas un réflexe de garder « sa » version. Sur
        // cette branche, « fichiers en clair » cesse d'écrire le PDF sur le disque : `pdf_path`
        // y reste vide, et le test de `main` ne trouverait donc plus PERSONNE — l'encart
        // disparaîtrait silencieusement pour tous ceux qu'il doit avertir.
        //
        // ⚠️ `transmitted_at` et `ack_number` sont en revanche de vraies colonnes depuis les
        // migrations des 2026-09-04 et 2026-09-05 : l'avertissement « ne jamais les interroger
        // en SQL » qui vivait ici est périmé et a été retiré. Elles restent malgré tout
        // inutilisables pour ce test-ci, mais pour une autre raison — elles sont nulles partout
        // où l'utilisateur n'a rien saisi, donc elles sélectionnent « les dépôts notés », pas
        // « les liasses imprimées ».
        return FiscalYear::query()->whereNotNull('tax_return_generated_at')->exists();
    }

    #[Computed]
    public function declarationData(): ?array
    {
        $user = auth()->user();
        $properties = Property::all();

        if ($properties->isEmpty()) {
            return null;
        }

        $fy = app(FiscalYearService::class)->getOrCreate($user, $this->year);
        $tax = app(TaxReturnService::class);

        $form2031 = $tax->compute2031($fy);
        $form2033B = $tax->compute2033B($fy, $properties, $this->year);
        $form2033A = $tax->compute2033A($fy, $properties, $this->year, $form2033B['310']);
        $form2033C = $tax->compute2033C($properties, $this->year);
        $form2033D = $tax->compute2033D($fy);
        $form2042 = $tax->compute2042($fy);
        $form2031bis = $tax->compute2031bis($fy, $user);

        // Contrôles sur les centimes, AVANT l'arrondi : un écart d'un centime y reste visible.
        $checks = $tax->checks($form2033A, $form2033B, $form2033C, $properties, $form2031bis);

        // Les montants se télédéclarent en euros entiers (issue #21) : chaque ligne arrondie,
        // chaque total recalculé sur les lignes arrondies, comme dans le PDF.
        [
            'form2031' => $form2031, 'form2033A' => $form2033A, 'form2033B' => $form2033B,
            'form2033C' => $form2033C, 'form2033D' => $form2033D, 'form2042' => $form2042,
            'form2031bis' => $form2031bis,
        ] = $tax->wholeEuros(compact('form2031', 'form2033A', 'form2033B', 'form2033C', 'form2033D', 'form2042', 'form2031bis'));

        return [
            'fiscal_year' => $fy,
            'siren' => $user->siren ?? 'Non renseigné',
            'forms' => $this->buildFormSections($fy, $form2031, $form2033A, $form2033B, $form2033C, $form2033D, $form2042, $form2031bis),
            'form2031bis' => $form2031bis,
            // Les contrôles viennent du service, jamais d'un calcul refait ici : le PDF les
            // affiche à partir de la même source, sinon les deux écrans peuvent diverger.
            'checks' => $checks,
            // Le détail actif par actif — d'où vient chaque ligne du cadre I ci-dessus.
            // Même source que les tableaux, pour la même raison que les contrôles.
            'breakdown' => $tax->assetBreakdown($properties, $this->year),
        ];
    }

    private function buildFormSections($fy, array $f2031, array $f2033A, array $f2033B, array $f2033C, array $f2033D, array $f2042, ?array $f2031bis = null): array
    {
        $fmt = fn($v) => number_format($v / 100, 0, ',', ' ');

        $sections = [
            '2031' => [
                'title' => '2031-SD — Déclaration de résultat',
                'cerfa' => 'CERFA 11085',
                'open' => true,
                'lines' => [
                    ['line' => 'AB', 'desc' => 'Production vendue — Services (loyers)', 'value' => $fmt($f2031['AB']), 'raw' => $f2031['AB']],
                    ['line' => 'CB', 'desc' => 'Bénéfice fiscal', 'value' => $fmt($f2031['CB']), 'raw' => $f2031['CB']],
                    ['line' => 'CC', 'desc' => 'Déficit fiscal', 'value' => $fmt($f2031['CC']), 'raw' => $f2031['CC']],
                ],
            ],
            '2033-A' => [
                'title' => '2033-A — Bilan simplifié',
                'cerfa' => 'CERFA 10956',
                'open' => false,
                'lines' => [
                    ['line' => '014', 'desc' => 'Immobilisations incorporelles (brut)', 'value' => $fmt($f2033A['014']), 'raw' => $f2033A['014']],
                    ['line' => '016', 'desc' => 'Amortissements des incorporelles', 'value' => $fmt($f2033A['016']), 'raw' => $f2033A['016']],
                    ['line' => '028', 'desc' => 'Immobilisations corporelles (bien, terrain, travaux, mobilier, frais d\'acquisition)', 'value' => $fmt($f2033A['028']), 'raw' => $f2033A['028']],
                    ['line' => '030', 'desc' => 'Amortissements cumulés', 'value' => $fmt($f2033A['030']), 'raw' => $f2033A['030']],
                    // 044 et 048 étaient calculées sans être affichées nulle part. Ce sont de
                    // vraies cases du Cerfa : les taire laissait l'utilisateur les remplir au
                    // jugé, et rendait opaque le contrôle qui compare 044 à la ligne 490.
                    ['line' => '044', 'desc' => 'Total immobilisations (brut)', 'value' => $fmt($f2033A['044']), 'raw' => $f2033A['044']],
                    ['line' => '048', 'desc' => 'Total amortissements', 'value' => $fmt($f2033A['048']), 'raw' => $f2033A['048']],
                    ['line' => '112', 'desc' => 'Total actif', 'value' => $fmt($f2033A['112']), 'raw' => $f2033A['112']],
                    ['line' => '120', 'desc' => 'Compte de l\'exploitant', 'value' => $fmt($f2033A['120']), 'raw' => $f2033A['120']],
                    ['line' => '136', 'desc' => 'Résultat de l\'exercice', 'value' => $fmt($f2033A['136']), 'raw' => $f2033A['136']],
                    ['line' => '156', 'desc' => 'Emprunts et dettes', 'value' => $fmt($f2033A['156']), 'raw' => $f2033A['156']],
                    ['line' => '180', 'desc' => 'Total passif', 'value' => $fmt($f2033A['180']), 'raw' => $f2033A['180']],
                ],
            ],
            '2033-B' => [
                'title' => '2033-B — Compte de résultat simplifié',
                'cerfa' => 'CERFA 10957',
                'open' => true,
                'lines' => [
                    ['line' => '218', 'desc' => 'Production vendue — Services (loyers)', 'value' => $fmt($f2033B['218']), 'raw' => $f2033B['218']],
                    ['line' => '232', 'desc' => 'Total produits d\'exploitation (I)', 'value' => $fmt($f2033B['232']), 'raw' => $f2033B['232']],
                    ['line' => '242', 'desc' => 'Autres charges externes', 'value' => $fmt($f2033B['242']), 'raw' => $f2033B['242']],
                    ['line' => '244', 'desc' => 'Impôts, taxes (taxe foncière, CFE)', 'value' => $fmt($f2033B['244']), 'raw' => $f2033B['244']],
                    ['line' => '254', 'desc' => 'Dotations aux amortissements', 'value' => $fmt($f2033B['254']), 'raw' => $f2033B['254']],
                    ['line' => '264', 'desc' => 'Total charges d\'exploitation (II)', 'value' => $fmt($f2033B['264']), 'raw' => $f2033B['264']],
                    ['line' => '270', 'desc' => 'Résultat d\'exploitation (I — II)', 'value' => $fmt($f2033B['270']), 'raw' => $f2033B['270']],
                    ['line' => '294', 'desc' => 'Charges financières (intérêts emprunt)', 'value' => $fmt($f2033B['294']), 'raw' => $f2033B['294']],
                    ['line' => '310', 'desc' => 'Résultat comptable', 'value' => $fmt($f2033B['310']), 'raw' => $f2033B['310']],
                    ['line' => '312', 'desc' => 'Résultat comptable — bénéfice', 'value' => $fmt($f2033B['312']), 'raw' => $f2033B['312']],
                    ['line' => '314', 'desc' => 'Résultat comptable — déficit', 'value' => $fmt($f2033B['314']), 'raw' => $f2033B['314']],
                    ['line' => '318', 'desc' => 'Réintégration : amortissements de l\'exercice non déductibles (art. 39 C), mis en report', 'value' => $fmt($f2033B['318']), 'raw' => $f2033B['318']],
                    ['line' => '350', 'desc' => 'Déduction : amortissements différés antérieurs repris (art. 39 C)', 'value' => $fmt($f2033B['350']), 'raw' => $f2033B['350']],
                    ['line' => '352', 'desc' => 'Résultat fiscal — bénéfice (avant imputation)', 'value' => $fmt($f2033B['352']), 'raw' => $f2033B['352']],
                    ['line' => '354', 'desc' => 'Résultat fiscal — déficit (avant imputation)', 'value' => $fmt($f2033B['354']), 'raw' => $f2033B['354']],
                    ['line' => '360', 'desc' => 'Déficits antérieurs imputés — à zéro en LMNP (cases 5GA à 5GJ de la 2042-C-PRO)', 'value' => $fmt($f2033B['360']), 'raw' => $f2033B['360']],
                    ['line' => '370', 'desc' => 'Résultat fiscal définitif — bénéfice', 'value' => $fmt($f2033B['370']), 'raw' => $f2033B['370']],
                    ['line' => '372', 'desc' => 'Résultat fiscal définitif — déficit', 'value' => $fmt($f2033B['372']), 'raw' => $f2033B['372']],
                ],
            ],
            '2033-C' => [
                'title' => '2033-C — Immobilisations et amortissements',
                'cerfa' => 'CERFA 10958',
                'open' => false,
                'categories' => $f2033C['categories'],
                'lines' => [
                    ['line' => '410 / 500', 'desc' => 'Immobilisations incorporelles', 'value' => $fmt($f2033C['categories']['incorporelles']['brut']), 'raw' => $f2033C['categories']['incorporelles']['brut'], 'dotation' => $fmt($f2033C['categories']['incorporelles']['dotation']), 'dotation_raw' => $f2033C['categories']['incorporelles']['dotation']],
                    // Le terrain n'a pas de ligne d'amortissement : il ne s'amortit pas.
                    ['line' => '420', 'desc' => 'Terrains (non amortissables)', 'value' => $fmt($f2033C['categories']['terrains']['brut']), 'raw' => $f2033C['categories']['terrains']['brut'], 'dotation' => $fmt(0), 'dotation_raw' => 0],
                    ['line' => '430 / 520', 'desc' => 'Constructions (dont frais d\'acquisition amortis à part)', 'value' => $fmt($f2033C['categories']['constructions']['brut']), 'raw' => $f2033C['categories']['constructions']['brut'], 'dotation' => $fmt($f2033C['categories']['constructions']['dotation']), 'dotation_raw' => $f2033C['categories']['constructions']['dotation']],
                    ['line' => '440 / 530', 'desc' => 'Installations techniques', 'value' => $fmt($f2033C['categories']['installations']['brut']), 'raw' => $f2033C['categories']['installations']['brut'], 'dotation' => $fmt($f2033C['categories']['installations']['dotation']), 'dotation_raw' => $f2033C['categories']['installations']['dotation']],
                    ['line' => '450 / 540', 'desc' => 'Agencements, aménagements', 'value' => $fmt($f2033C['categories']['agencements']['brut']), 'raw' => $f2033C['categories']['agencements']['brut'], 'dotation' => $fmt($f2033C['categories']['agencements']['dotation']), 'dotation_raw' => $f2033C['categories']['agencements']['dotation']],
                    ['line' => '470 / 560', 'desc' => 'Autres immobilisations (mobilier)', 'value' => $fmt($f2033C['categories']['autres']['brut']), 'raw' => $f2033C['categories']['autres']['brut'], 'dotation' => $fmt($f2033C['categories']['autres']['dotation']), 'dotation_raw' => $f2033C['categories']['autres']['dotation']],
                    ['line' => '490', 'desc' => 'Total immobilisations (brut)', 'value' => $fmt($f2033C['total_brut']), 'raw' => $f2033C['total_brut']],
                    ['line' => '572', 'desc' => 'Total dotations aux amortissements', 'value' => $fmt($f2033C['total_dotation']), 'raw' => $f2033C['total_dotation']],
                ],
            ],
            // Cadre « Déficits reportables » VIDE en LMNP (notice 2033-NOT-SD, issue #19) : on
            // ne liste que le suivi des amortissements différés, qui n'a pas de case Cerfa.
            '2033-D' => [
                'title' => '2033-D — Suivi des amortissements différés (art. 39 C)',
                'cerfa' => 'CERFA 10959',
                'open' => false,
                'note' => 'Laissez vide le cadre « Déficits reportables » du 2033-D (cases 982 à 870) : '
                    . 'la notice le réserve aux sociétés à l\'impôt sur les sociétés. Vos déficits LMNP '
                    . 'sont repris par l\'administration en cases 5GA à 5GJ de la 2042-C-PRO. Les '
                    . 'amortissements différés n\'ont pas de case : ce suivi est à conserver, et à joindre '
                    . 'sur papier libre si vous le souhaitez.',
                'lines' => [
                    ['line' => '39 C', 'desc' => 'Amortissements différés à l\'ouverture', 'value' => $fmt($f2033D['suivi_39c']['ouverture']), 'raw' => $f2033D['suivi_39c']['ouverture']],
                    ['line' => '318', 'desc' => '+ Différés de l\'exercice (dotation non déduite)', 'value' => $fmt($f2033D['suivi_39c']['differes']), 'raw' => $f2033D['suivi_39c']['differes']],
                    ['line' => '350', 'desc' => '− Repris cette année', 'value' => $fmt($f2033D['suivi_39c']['repris']), 'raw' => $f2033D['suivi_39c']['repris']],
                    ['line' => '39 C', 'desc' => '= Amortissements différés à reporter à la clôture', 'value' => $fmt($f2033D['suivi_39c']['cloture']), 'raw' => $f2033D['suivi_39c']['cloture']],
                ],
            ],
            '2042-C-PRO' => [
                'title' => '2042-C-PRO — Déclaration de revenus',
                'cerfa' => 'CERFA 11222',
                'open' => true,
                'lines' => [
                    [
                        'line' => $f2042['is_benefice'] ? $f2042['case_benefice'] : $f2042['case_deficit'],
                        'desc' => ($f2042['is_benefice'] ? 'Bénéfice' : 'Déficit') . ' LMNP',
                        'value' => $fmt($f2042['montant']),
                        'raw' => $f2042['montant'],
                    ],
                ],
            ],
        ];

        if ($f2031bis === null) {
            return $sections;
        }

        // Indivision : la 2031 bis-SD (cadre E) répartit le résultat, et chaque indivisaire
        // reporte SA quote-part sur sa propre 2042-C-PRO — pas le résultat entier.
        $pct = fn (int $bp) => \App\Support\Indivision::basisPointsToPercent($bp) . ' %';
        $bisLines = [];
        $bisRows = [];
        $pcLines = [];
        $pcCards = [];
        foreach ($f2031bis['rows'] as $row) {
            $isDeficit = $row['deficit'] > 0;
            $amount = $isDeficit ? $row['deficit'] : $row['benefice'];
            $bisLines[] = [
                'line' => 'E',
                'desc' => $row['name'] . ' — indivisaire, BIC « M », quote-part ' . $pct($row['share_bp'])
                    . ($isDeficit ? ' (déficit)' : ' (bénéfice)'),
                'value' => $f2031bis['complete'] ? $fmt($amount) : '—',
                'raw' => $f2031bis['complete'] ? $amount : 0,
            ];
            // Maquette écran 3, variante B : le cadre E tel qu'imprimé (une colonne par
            // information du Cerfa), pas une ligne de texte composée à plat.
            $bisRows[] = [
                'name' => $row['name'],
                'identity' => $this->coOwnerIdentitySummary($row),
                'quality' => $row['quality'],
                'bic_type' => $row['bic_type'],
                'share_pct' => $pct($row['share_bp']),
                'benefice' => $f2031bis['complete'] ? $fmt($row['benefice']) : '—',
                'deficit' => $f2031bis['complete'] ? $fmt($row['deficit']) : '—',
            ];
            $pcLines[] = [
                'line' => $isDeficit ? $f2042['case_deficit'] : $f2042['case_benefice'],
                'desc' => ($isDeficit ? 'Déficit' : 'Bénéfice') . ' LMNP — ' . $row['name'] . ' (' . $pct($row['share_bp']) . ')',
                'value' => $f2031bis['complete'] ? $fmt($amount) : '—',
                'raw' => $f2031bis['complete'] ? $amount : 0,
            ];
            // Maquette écran 3, variante B : une carte par indivisaire (case, montant, Copier)
            // plutôt qu'une ligne de tableau générique — c'est SA déclaration, pas celle de
            // l'indivision.
            $pcCards[] = [
                'name' => $row['name'],
                'initials' => $this->coOwnerInitials($row['name']),
                'share_pct' => $pct($row['share_bp']),
                'case' => $isDeficit ? $f2042['case_deficit'] : $f2042['case_benefice'],
                'label' => $isDeficit ? 'Déficit LMNP' : 'Bénéfice LMNP',
                'value' => $f2031bis['complete'] ? $fmt($amount) : '—',
                'raw' => $f2031bis['complete'] ? $amount : 0,
            ];
        }

        $ordered = [];
        foreach ($sections as $key => $section) {
            $ordered[$key] = $section;
            // `'2031'` est une clé numérique : PHP la range en entier, d'où la comparaison en chaîne.
            if ((string) $key === '2031') {
                $ordered['2031-bis'] = [
                    'title' => '2031 bis-SD — Répartition du résultat entre indivisaires (cadre E)',
                    'cerfa' => 'annexe au CERFA 11085',
                    'open' => true,
                    'lines' => $bisLines,
                    'rows' => $bisRows,
                    // Ligne de total du cadre E (maquette, variante B) : elle prouve que la
                    // somme des quotes-parts et des montants retombe sur le résultat de la
                    // 2031-SD (case CB/CC), affiché même répartition incomplète (0 €/0 %).
                    'total' => [
                        'share_pct' => $pct($f2031bis['total_share_bp']),
                        'benefice' => $fmt($f2031bis['result'] > 0 ? $f2031bis['result'] : 0),
                        'deficit' => $fmt($f2031bis['result'] < 0 ? -$f2031bis['result'] : 0),
                    ],
                    'complete' => $f2031bis['complete'],
                ];
            }
        }
        // Répartition incomplète : aucun montant à reporter, comme dans le PDF. Laisser la ligne
        // d'origine afficherait le résultat ENTIER de l'indivision, qu'aucun indivisaire ne doit
        // reporter.
        $ordered['2042-C-PRO']['lines'] = $f2031bis['complete'] ? $pcLines : [[
            'line' => '—',
            'desc' => 'Répartition entre indivisaires incomplète : aucun montant à reporter tant que les quotes-parts ne totalisent pas 100 %',
            'value' => '—',
            'raw' => 0,
        ]];
        $ordered['2042-C-PRO']['persons'] = $f2031bis['complete'] ? $pcCards : [];

        return $ordered;
    }

    /** Sous-titre d'une ligne du cadre E : adresse et naissance, ou leur absence. */
    private function coOwnerIdentitySummary(array $row): string
    {
        $address = $row['address'] ?? null;
        $birth = $row['birth'] ?? null;

        if (blank($address) && blank($birth)) {
            return 'Adresse et naissance non renseignées';
        }

        $parts = array_filter([
            $address,
            filled($birth) ? 'né(e) ' . $birth : null,
        ]);

        return implode(' · ', $parts);
    }

    /** Initiales d'un indivisaire pour l'avatar de sa carte 2042-C-PRO (maquette, variante B). */
    private function coOwnerInitials(string $name): string
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));

        if ($words === []) {
            return '??';
        }

        $first = mb_strtoupper(mb_substr($words[0], 0, 1));
        $last = count($words) > 1 ? mb_strtoupper(mb_substr($words[count($words) - 1], 0, 1)) : '';

        return $first . $last;
    }

    public function exportCsv()
    {
        $data = $this->declarationData;
        if (! $data) {
            Notification::make()->title('Aucune donnée')->danger()->send();
            return;
        }

        // Événement navigateur relayé vers le dataLayer GTM (partials/gtm-head)
        $this->dispatch('analytics', [
            'event' => 'teledeclaration_generated',
            'form_type' => 'csv',
            'fiscal_year' => $this->year,
        ]);

        $allLines = collect();
        foreach ($data['forms'] as $formKey => $form) {
            foreach ($form['lines'] as $line) {
                $allLines->push([
                    'form' => $formKey,
                    'line' => $line['line'],
                    'desc' => $line['desc'],
                    'raw' => $line['raw'],
                ]);
            }
        }

        return CsvExportService::export(
            "declaration_lmnp_{$this->year}.csv",
            ['Formulaire', 'Ligne', 'Description', 'Montant (€)'],
            $allLines,
            fn ($line) => [$line['form'], $line['line'], $line['desc'], number_format($line['raw'] / 100, 2, ',', '')]
        );
    }

    public function updatedYear(): void
    {
        unset($this->declarationData);
        $this->resetTransmission();
    }
}
