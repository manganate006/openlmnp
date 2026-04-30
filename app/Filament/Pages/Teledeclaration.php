<?php

namespace App\Filament\Pages;

use App\Models\FiscalYear;
use App\Models\Property;
use App\Services\CsvExportService;
use App\Services\DepreciationService;
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
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;
    protected static string | UnitEnum | null $navigationGroup = 'Fiscal';
    protected static ?string $navigationLabel = 'Télédéclaration';
    protected static ?string $title = 'Aide à la télédéclaration';
    protected static ?int $navigationSort = 4;
    protected string $view = 'filament.pages.teledeclaration';

    public int $year = 2026;

    public function mount(): void
    {
        $this->year = (int) date('Y');
    }

    #[Computed]
    public function declarationData(): ?array
    {
        $user = auth()->user();
        $properties = Property::all();

        if ($properties->isEmpty()) {
            return null;
        }

        // Recalculer l'exercice
        $fiscalYearService = app(FiscalYearService::class);
        $fy = $fiscalYearService->getOrCreate($user, $this->year);

        // Récupérer les données structurées
        $taxService = app(TaxReturnService::class);

        // Utiliser la réflexion pour accéder aux méthodes privées, ou recalculer
        $form2033B = $this->compute2033B($fy, $properties);
        $form2033C = $this->compute2033C($properties);
        $form2042 = $this->compute2042($fy);

        return [
            'fiscal_year' => $fy,
            'siren' => $user->siren ?? 'Non renseigné',
            'lines' => $this->buildLines($fy, $form2033B, $form2033C, $form2042),
        ];
    }

    private function compute2033B(FiscalYear $fy, $properties): array
    {
        $year = $fy->year;
        $loyers = 0;
        $line242 = 0;
        $line244 = 0;
        $line294 = 0;

        foreach ($properties as $prop) {
            $loyers += $prop->incomes()->whereYear('income_date', $year)
                ->selectRaw('COALESCE(SUM(amount) - SUM(platform_fee), 0) as net')->value('net') ?? 0;

            $expenses = $prop->expenses()->whereYear('expense_date', $year)->get();
            foreach ($expenses as $exp) {
                $effective = $exp->is_dedicated ? $exp->amount : (int) bcmul((string) $exp->amount, $prop->quota_share, 0);
                if ($exp->category === 'property_tax') {
                    $line244 += $effective;
                } else {
                    $line242 += $effective;
                }
            }

            foreach ($prop->loans as $loan) {
                $interests = $loan->getInterestsForYear($year) + $loan->getInsuranceForYear($year);
                $line294 += (int) bcmul((string) $interests, $prop->quota_share, 0);
            }
        }

        $line254 = 0;
        foreach ($properties as $prop) {
            $dep = app(DepreciationService::class)->calculateAnnualDepreciation($prop, $year);
            $line254 += (int) $dep['total'];
        }

        $line232 = $loyers;
        $line264 = $line242 + $line244 + $line254;
        $line270 = $line232 - $line264;
        $line310 = $line270 - $line294;

        return compact('loyers', 'line232', 'line242', 'line244', 'line254', 'line264', 'line270', 'line294', 'line310');
    }

    private function compute2033C($properties): array
    {
        $totalDotation = 0;
        foreach ($properties as $prop) {
            $dep = app(DepreciationService::class)->calculateAnnualDepreciation($prop, $this->year);
            $totalDotation += (int) $dep['total'];
        }
        return ['total_dotation' => $totalDotation];
    }

    private function compute2042(FiscalYear $fy): array
    {
        return [
            'case' => $fy->fiscal_result >= 0 ? '5NA' : '5NY',
            'montant' => abs($fy->fiscal_result),
        ];
    }

    private function buildLines(FiscalYear $fy, array $b, array $c, array $form2042): array
    {
        $fmt = fn($v) => number_format($v / 100, 2, ',', ' ');

        return [
            ['form' => '2031', 'line' => 'AB', 'desc' => 'Production vendue — Services (loyers)', 'value' => $fmt($b['loyers']), 'raw' => $b['loyers']],
            ['form' => '2031', 'line' => 'CB/CC', 'desc' => 'Résultat fiscal (bénéfice/déficit)', 'value' => $fmt($fy->fiscal_result), 'raw' => $fy->fiscal_result],
            ['form' => '2033-B', 'line' => '218', 'desc' => 'Production vendue — Services', 'value' => $fmt($b['loyers']), 'raw' => $b['loyers']],
            ['form' => '2033-B', 'line' => '232', 'desc' => 'Total produits exploitation (I)', 'value' => $fmt($b['line232']), 'raw' => $b['line232']],
            ['form' => '2033-B', 'line' => '242', 'desc' => 'Autres charges externes', 'value' => $fmt($b['line242']), 'raw' => $b['line242']],
            ['form' => '2033-B', 'line' => '244', 'desc' => 'Impôts, taxes (taxe foncière, CFE)', 'value' => $fmt($b['line244']), 'raw' => $b['line244']],
            ['form' => '2033-B', 'line' => '254', 'desc' => 'Dotations aux amortissements', 'value' => $fmt($b['line254']), 'raw' => $b['line254']],
            ['form' => '2033-B', 'line' => '264', 'desc' => 'Total charges exploitation (II)', 'value' => $fmt($b['line264']), 'raw' => $b['line264']],
            ['form' => '2033-B', 'line' => '270', 'desc' => 'Résultat exploitation (I — II)', 'value' => $fmt($b['line270']), 'raw' => $b['line270']],
            ['form' => '2033-B', 'line' => '294', 'desc' => 'Charges financières (intérêts emprunt)', 'value' => $fmt($b['line294']), 'raw' => $b['line294']],
            ['form' => '2033-B', 'line' => '310', 'desc' => 'Résultat comptable', 'value' => $fmt($b['line310']), 'raw' => $b['line310']],
            ['form' => '2033-B', 'line' => '370/372', 'desc' => 'Résultat fiscal après imputation', 'value' => $fmt($fy->fiscal_result), 'raw' => $fy->fiscal_result],
            ['form' => '2033-C', 'line' => '572', 'desc' => 'Total dotations amortissements', 'value' => $fmt($c['total_dotation']), 'raw' => $c['total_dotation']],
            ['form' => '2042-C-PRO', 'line' => $form2042['case'], 'desc' => ($fy->fiscal_result >= 0 ? 'Bénéfice' : 'Déficit') . ' LMNP', 'value' => $fmt($form2042['montant']), 'raw' => $form2042['montant']],
        ];
    }

    public function exportCsv()
    {
        $data = $this->declarationData;
        if (! $data) {
            Notification::make()->title('Aucune donnée')->danger()->send();
            return;
        }

        $lines = collect($data['lines']);

        return CsvExportService::export(
            "declaration_lmnp_{$this->year}.csv",
            ['Formulaire', 'Ligne', 'Description', 'Montant (€)'],
            $lines,
            fn ($line) => [$line['form'], $line['line'], $line['desc'], number_format($line['raw'] / 100, 2, ',', '')]
        );
    }

    public function updatedYear(): void
    {
        unset($this->declarationData);
    }
}
