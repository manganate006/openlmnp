<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\NavigationAware;
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
        $form2033A = $tax->compute2033A($fy, $properties, $this->year);
        $form2033B = $tax->compute2033B($fy, $properties, $this->year);
        $form2033C = $tax->compute2033C($properties, $this->year);
        $form2033D = $tax->compute2033D($fy);
        $form2042 = $tax->compute2042($fy);

        return [
            'fiscal_year' => $fy,
            'siren' => $user->siren ?? 'Non renseigné',
            'forms' => $this->buildFormSections($fy, $form2031, $form2033A, $form2033B, $form2033C, $form2033D, $form2042),
        ];
    }

    private function buildFormSections($fy, array $f2031, array $f2033A, array $f2033B, array $f2033C, array $f2033D, array $f2042): array
    {
        $fmt = fn($v) => number_format($v / 100, 2, ',', ' ');

        return [
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
                    ['line' => '028', 'desc' => 'Immobilisations corporelles (brut)', 'value' => $fmt($f2033A['028']), 'raw' => $f2033A['028']],
                    ['line' => '030', 'desc' => 'Amortissements cumulés', 'value' => $fmt($f2033A['030']), 'raw' => $f2033A['030']],
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
                    ['line' => '318', 'desc' => 'Amortissements réputés différés (art. 39C)', 'value' => $fmt($f2033B['318']), 'raw' => $f2033B['318']],
                    ['line' => '352', 'desc' => 'Résultat fiscal — bénéfice (avant imputation)', 'value' => $fmt($f2033B['352']), 'raw' => $f2033B['352']],
                    ['line' => '354', 'desc' => 'Résultat fiscal — déficit (avant imputation)', 'value' => $fmt($f2033B['354']), 'raw' => $f2033B['354']],
                    ['line' => '360', 'desc' => 'Déficits antérieurs imputés', 'value' => $fmt($f2033B['360']), 'raw' => $f2033B['360']],
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
                    ['line' => '430 / 520', 'desc' => 'Constructions', 'value' => $fmt($f2033C['categories']['constructions']['brut']), 'raw' => $f2033C['categories']['constructions']['brut'], 'dotation' => $fmt($f2033C['categories']['constructions']['dotation']), 'dotation_raw' => $f2033C['categories']['constructions']['dotation']],
                    ['line' => '440 / 530', 'desc' => 'Installations techniques', 'value' => $fmt($f2033C['categories']['installations']['brut']), 'raw' => $f2033C['categories']['installations']['brut'], 'dotation' => $fmt($f2033C['categories']['installations']['dotation']), 'dotation_raw' => $f2033C['categories']['installations']['dotation']],
                    ['line' => '450 / 540', 'desc' => 'Agencements, aménagements', 'value' => $fmt($f2033C['categories']['agencements']['brut']), 'raw' => $f2033C['categories']['agencements']['brut'], 'dotation' => $fmt($f2033C['categories']['agencements']['dotation']), 'dotation_raw' => $f2033C['categories']['agencements']['dotation']],
                    ['line' => '470 / 560', 'desc' => 'Autres immobilisations (mobilier)', 'value' => $fmt($f2033C['categories']['autres']['brut']), 'raw' => $f2033C['categories']['autres']['brut'], 'dotation' => $fmt($f2033C['categories']['autres']['dotation']), 'dotation_raw' => $f2033C['categories']['autres']['dotation']],
                    ['line' => '490', 'desc' => 'Total immobilisations (brut)', 'value' => $fmt($f2033C['total_brut']), 'raw' => $f2033C['total_brut']],
                    ['line' => '572', 'desc' => 'Total dotations aux amortissements', 'value' => $fmt($f2033C['total_dotation']), 'raw' => $f2033C['total_dotation']],
                ],
                'check_572_254' => $f2033C['total_dotation'] === (int) ($f2033B['254'] ?? 0),
            ],
            '2033-D' => [
                'title' => '2033-D — Déficits et amortissements différés',
                'cerfa' => 'CERFA 10959',
                'open' => false,
                'lines' => [
                    ['line' => '982', 'desc' => 'Déficits restant à reporter (N-1)', 'value' => $fmt($f2033D['982']), 'raw' => $f2033D['982']],
                    ['line' => '983', 'desc' => 'Déficits imputés sur le résultat', 'value' => $fmt($f2033D['983']), 'raw' => $f2033D['983']],
                    ['line' => '984', 'desc' => 'Déficits reportables restants', 'value' => $fmt($f2033D['984']), 'raw' => $f2033D['984']],
                    ['line' => '860', 'desc' => 'Déficit de l\'exercice', 'value' => $fmt($f2033D['860']), 'raw' => $f2033D['860']],
                    ['line' => '870', 'desc' => 'Total amortissements différés reportables', 'value' => $fmt($f2033D['870']), 'raw' => $f2033D['870']],
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
    }

    public function exportCsv()
    {
        $data = $this->declarationData;
        if (! $data) {
            Notification::make()->title('Aucune donnée')->danger()->send();
            return;
        }

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
    }
}
