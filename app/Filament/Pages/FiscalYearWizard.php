<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\NavigationAware;
use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Expense;
use App\Models\Property;
use App\Services\FiscalYearService;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * Assistant de création d'un exercice fiscal LMNP.
 *
 * Parcours en 4 étapes :
 *   1. Sélection de l'année
 *   2. Vérification des données (recettes, charges, amortissements)
 *   3. Résultat fiscal (plafonnement, amortissement différé, micro-BIC vs réel)
 *   4. Confirmation et clôture
 */
class FiscalYearWizard extends Page
{
    use NavigationAware;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;
    protected static string|UnitEnum|null $navigationGroup = 'Fiscal';
    protected static ?string $navigationLabel = 'Nouvel exercice';
    protected static ?string $title = 'Assistant de clôture fiscale';
    protected static ?int $navigationSort = 2;
    protected static bool $shouldRegisterNavigation = false;

    protected static function getGuidedNavigationGroup(): string
    {
        return 'Déclaration annuelle';
    }
    protected string $view = 'filament.pages.fiscal-year-wizard';

    // -------------------------------------------------------------------------
    // État du formulaire
    // -------------------------------------------------------------------------

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->data = [
            'year'   => app(FiscalYearService::class)->nextYearToCreate(auth()->user()),
            'status' => FiscalYear::STATUS_DRAFT,
        ];
    }

    // -------------------------------------------------------------------------
    // Schéma du formulaire (wizard)
    // -------------------------------------------------------------------------

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Wizard::make([
                    $this->stepSelectYear(),
                    $this->stepVerifyData(),
                    $this->stepFiscalResult(),
                    $this->stepConfirmation(),
                ])
                ->submitAction(new HtmlString(
                    '<button type="submit"
                        class="fi-btn fi-btn-size-md fi-btn-color-primary fi-color-custom fi-btn-color-primary relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-btn-size-md gap-1.5 px-4 py-2 text-sm inline-grid shadow-sm bg-custom-600 text-white hover:bg-custom-500 focus-visible:ring-custom-500/50 dark:bg-custom-500 dark:hover:bg-custom-400 dark:focus-visible:ring-custom-400/50 fi-color-primary"
                        style="--c-400:var(--primary-400);--c-500:var(--primary-500);--c-600:var(--primary-600);"
                    >
                        Créer l\'exercice fiscal
                    </button>'
                )),
            ]);
    }

    // -------------------------------------------------------------------------
    // Étape 1 : Sélection de l'année
    // -------------------------------------------------------------------------

    private function stepSelectYear(): Step
    {
        $currentYear = (int) date('Y');

        return Step::make('Sélection de l\'année')
            ->icon('heroicon-o-calendar')
            ->description('Choisissez l\'année de l\'exercice fiscal à créer')
            ->schema([
                Section::make()
                    ->schema([
                        Select::make('year')
                            ->label('Année fiscale')
                            ->options($this->buildYearOptions($currentYear))
                            ->default($currentYear - 1)
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->resetComputedData())
                            ->hint('L\'exercice couvre du 1er janvier au 31 décembre')
                            ->rules([
                                fn () => function (string $attribute, $value, \Closure $fail) {
                                    $error = app(FiscalYearService::class)
                                        ->missingPreviousYearError(auth()->user(), (int) $value);

                                    if ($error !== null) {
                                        $fail($error);
                                    }
                                },
                            ]),

                        \Filament\Forms\Components\Placeholder::make('year_summary')
                            ->label('Résumé de l\'année sélectionnée')
                            ->content(fn () => $this->buildYearSummaryHtml()),

                        \Filament\Forms\Components\Placeholder::make('year_chain_alert')
                            ->hiddenLabel()
                            ->content(fn () => $this->buildAlertsHtml())
                            ->visible(fn () => $this->hasAlerts()),
                    ]),
            ]);
    }

    // -------------------------------------------------------------------------
    // Étape 2 : Vérification des données
    // -------------------------------------------------------------------------

    private function stepVerifyData(): Step
    {
        return Step::make('Vérification des données')
            ->icon('heroicon-o-clipboard-document-check')
            ->description('Contrôlez les montants avant le calcul fiscal')
            ->schema([
                Section::make('Recettes et charges')
                    ->schema([
                        \Filament\Forms\Components\Placeholder::make('verify_income')
                            ->label('Total recettes nettes')
                            ->content(fn () => $this->buildPlaceholderHtml(
                                $this->getYearIncomeCents(),
                                'Loyers encaissés déduction faite des commissions plateformes',
                                'wz-green-dark'
                            )),

                        \Filament\Forms\Components\Placeholder::make('verify_expenses')
                            ->label('Total charges déductibles')
                            ->content(fn () => $this->buildPlaceholderHtml(
                                $this->getYearExpensesCents(),
                                'Charges directes + quote-part des charges partagées + intérêts d\'emprunt',
                                'wz-red-dark'
                            )),

                        \Filament\Forms\Components\Placeholder::make('verify_depreciation')
                            ->label('Total amortissements de l\'année')
                            ->content(fn () => $this->buildPlaceholderHtml(
                                $this->getYearDepreciationCents(),
                                'Immeuble (composants) + travaux + mobilier, au prorata temporis',
                                'wz-blue'
                            )),

                        \Filament\Forms\Components\Placeholder::make('verify_alert')
                            ->label('Alertes')
                            ->content(fn () => $this->buildAlertsHtml())
                            ->visible(fn () => $this->hasAlerts()),
                    ]),
            ]);
    }

    // -------------------------------------------------------------------------
    // Étape 3 : Résultat fiscal
    // -------------------------------------------------------------------------

    private function stepFiscalResult(): Step
    {
        return Step::make('Résultat fiscal')
            ->icon('heroicon-o-calculator')
            ->description('Calcul du résultat imposable au régime réel simplifié')
            ->schema([
                Section::make('Détail du calcul')
                    ->schema([
                        \Filament\Forms\Components\Placeholder::make('result_before_dep')
                            ->label('Résultat avant amortissement')
                            ->content(fn () => $this->buildResultLineHtml(
                                $this->getResultBeforeDepreciation(),
                                'Recettes − Charges'
                            )),

                        \Filament\Forms\Components\Placeholder::make('result_carried_forward')
                            ->label('Amortissements différés N−1')
                            ->content(fn () => $this->buildPlaceholderHtml(
                                $this->getPreviousDeferred(),
                                'Report de l\'exercice précédent (non limités dans le temps)',
                                'wz-purple'
                            )),

                        \Filament\Forms\Components\Placeholder::make('result_total_dep')
                            ->label('Amortissements disponibles (année + report)')
                            ->content(fn () => $this->buildPlaceholderHtml(
                                $this->getTotalAvailableDepreciation(),
                                'Amortissements de l\'année + différés N−1',
                                'wz-blue'
                            )),

                        \Filament\Forms\Components\Placeholder::make('result_capped')
                            ->label('Amortissement plafonné (déduit)')
                            ->content(fn () => $this->buildPlaceholderHtml(
                                $this->getCappedDepreciation(),
                                'Limité au résultat avant amortissement (ne peut pas créer de déficit)',
                                'wz-orange'
                            )),

                        \Filament\Forms\Components\Placeholder::make('result_deferred')
                            ->label('Amortissement différé (reporté N+1)')
                            ->content(fn () => $this->buildPlaceholderHtml(
                                $this->getDeferredDepreciation(),
                                'Excédent d\'amortissement reporté indéfiniment',
                                'wz-purple'
                            )),

                        \Filament\Forms\Components\Placeholder::make('result_fiscal')
                            ->label('Résultat fiscal final')
                            ->content(fn () => $this->buildFiscalResultHtml()),
                    ]),

                Section::make('Comparaison micro-BIC vs régime réel')
                    ->schema([
                        \Filament\Forms\Components\Placeholder::make('micro_bic_comparison')
                            ->label('Comparatif')
                            ->content(fn () => $this->buildMicroBicComparisonHtml()),
                    ]),
            ]);
    }

    // -------------------------------------------------------------------------
    // Étape 4 : Confirmation
    // -------------------------------------------------------------------------

    private function stepConfirmation(): Step
    {
        return Step::make('Confirmation')
            ->icon('heroicon-o-check-circle')
            ->description('Vérifiez le résumé puis créez l\'exercice')
            ->schema([
                Section::make('Récapitulatif')
                    ->schema([
                        \Filament\Forms\Components\Placeholder::make('confirmation_summary')
                            ->hiddenLabel()
                            ->content(fn () => $this->buildConfirmationSummaryHtml()),
                    ]),

                Section::make('Statut de l\'exercice')
                    ->schema([
                        Toggle::make('is_closed')
                            ->label('Clôturer l\'exercice')
                            ->helperText('Activez cette option pour marquer l\'exercice comme définitivement clôturé. Désactivé = brouillon modifiable.')
                            ->default(false)
                            ->live()
                            ->afterStateUpdated(fn (bool $state) => $this->data['status'] = $state
                                ? FiscalYear::STATUS_CLOSED
                                : FiscalYear::STATUS_DRAFT
                            ),

                        \Filament\Forms\Components\Placeholder::make('status_hint')
                            ->hiddenLabel()
                            ->content(fn () => $this->buildStatusHintHtml()),
                    ]),
            ]);
    }

    // -------------------------------------------------------------------------
    // Action de création de l'exercice
    // -------------------------------------------------------------------------

    public function create(): void
    {
        $year   = (int) ($this->data['year'] ?? date('Y') - 1);
        $status = $this->data['status'] ?? FiscalYear::STATUS_DRAFT;

        $service    = app(FiscalYearService::class);
        $fiscalYear = $service->getOrCreate(auth()->user(), $year);

        $fiscalYear->update(['status' => $status]);

        app(\App\Services\BadgeService::class)->evaluate(auth()->user(), 'fiscal_year_created', [
            'fiscal_year' => $year,
        ]);

        Notification::make()
            ->title('Exercice fiscal créé')
            ->body(sprintf(
                'L\'exercice %d a été %s avec succès.',
                $year,
                $status === FiscalYear::STATUS_CLOSED ? 'clôturé' : 'enregistré en brouillon'
            ))
            ->success()
            ->send();

        $this->redirect(route('filament.admin.resources.fiscal-years.index'));
    }

    // -------------------------------------------------------------------------
    // Méthodes de calcul (données de l'année sélectionnée)
    // -------------------------------------------------------------------------

    private function selectedYear(): int
    {
        return (int) ($this->data['year'] ?? date('Y') - 1);
    }

    #[Computed]
    public function properties()
    {
        return Property::all();
    }

    private function getYearIncomeCents(): int
    {
        $year       = $this->selectedYear();
        $properties = Property::all();
        $total      = 0;

        foreach ($properties as $property) {
            $net = $property->incomes()
                ->whereYear('income_date', $year)
                ->selectRaw('SUM(amount) - SUM(platform_fee) as net_income')
                ->value('net_income');
            $total += (int) ($net ?? 0);
        }

        return $total;
    }

    private function getYearExpensesCents(): int
    {
        $year       = $this->selectedYear();
        $properties = Property::all();
        $total      = 0;

        foreach ($properties as $property) {
            // Charges 100 % dédiées
            $dedicated = $property->expenses()
                ->whereYear('expense_date', $year)
                ->where('is_dedicated', true)
                ->sum('amount');
            $total += (int) $dedicated;

            // Charges partagées au prorata
            $shared = $property->expenses()
                ->whereYear('expense_date', $year)
                ->where('is_dedicated', false)
                ->sum('amount');
            $total += (int) bcmul((string) $shared, $property->quota_share, 0);

            // Intérêts et assurances d'emprunt (quote-part)
            foreach ($property->loans as $loan) {
                $interest  = $loan->payments()->whereYear('payment_date', $year)->sum('interest_amount');
                $insurance = $loan->payments()->whereYear('payment_date', $year)->sum('insurance_amount');
                $total    += (int) bcmul((string) ($interest + $insurance), $property->quota_share, 0);
            }
        }

        return $total;
    }

    private function getYearDepreciationCents(): int
    {
        $year             = $this->selectedYear();
        $properties       = Property::all();
        $depService       = app(\App\Services\DepreciationService::class);
        $total            = 0;

        foreach ($properties as $property) {
            $dep    = $depService->calculateAnnualDepreciation($property, $year);
            $total += (int) $dep['total'];
        }

        return $total;
    }

    private function getPreviousDeferred(): int
    {
        $year     = $this->selectedYear();
        $previous = FiscalYear::withoutGlobalScopes()
            ->where('user_id', auth()->id())
            ->where('year', $year - 1)
            ->first();

        return (int) ($previous?->deferred_depreciation ?? 0);
    }

    private function getResultBeforeDepreciation(): int
    {
        return $this->getYearIncomeCents() - $this->getYearExpensesCents();
    }

    private function getTotalAvailableDepreciation(): int
    {
        return $this->getYearDepreciationCents() + $this->getPreviousDeferred();
    }

    private function getCappedDepreciation(): int
    {
        $resultBefore = $this->getResultBeforeDepreciation();
        $available    = $this->getTotalAvailableDepreciation();

        if ($resultBefore <= 0) {
            return 0;
        }

        return min($available, $resultBefore);
    }

    private function getDeferredDepreciation(): int
    {
        $resultBefore = $this->getResultBeforeDepreciation();
        $available    = $this->getTotalAvailableDepreciation();

        if ($resultBefore <= 0) {
            return $available;
        }

        return max(0, $available - $resultBefore);
    }

    private function getFiscalResult(): int
    {
        return max(0, $this->getResultBeforeDepreciation() - $this->getCappedDepreciation());
    }

    private function getIncomeCount(): int
    {
        $year       = $this->selectedYear();
        $properties = Property::all();
        $count      = 0;

        foreach ($properties as $property) {
            $count += $property->incomes()->whereYear('income_date', $year)->count();
        }

        return $count;
    }

    private function getExpenseCount(): int
    {
        $year       = $this->selectedYear();
        $properties = Property::all();
        $count      = 0;

        foreach ($properties as $property) {
            $count += $property->expenses()->whereYear('expense_date', $year)->count();
        }

        return $count;
    }

    private function hasAlerts(): bool
    {
        return $this->getYearIncomeCents() === 0
            || Property::count() === 0
            || $this->previousYearMissingError() !== null
            || $this->getPreviousYearStatus() === 'draft';
    }

    /**
     * Erreur de chaîne N-1 pour l'année sélectionnée (null si la création
     * est autorisée, notamment pour le premier exercice de la chaîne).
     */
    private function previousYearMissingError(): ?string
    {
        return app(FiscalYearService::class)
            ->missingPreviousYearError(auth()->user(), $this->selectedYear());
    }

    /**
     * Vérifie l'état de l'exercice N-1 : 'closed', 'draft', ou 'missing'.
     */
    private function getPreviousYearStatus(): string
    {
        $year     = $this->selectedYear();
        $previous = FiscalYear::withoutGlobalScopes()
            ->where('user_id', auth()->id())
            ->where('year', $year - 1)
            ->first();

        if (! $previous) {
            return 'missing';
        }

        return $previous->status;
    }

    /**
     * Vérifie si la chaîne de reports est cassée (trou entre le premier exercice et N-1).
     */
    private function hasChainGap(): bool
    {
        $year     = $this->selectedYear();
        $earliest = FiscalYear::withoutGlobalScopes()
            ->where('user_id', auth()->id())
            ->orderBy('year')
            ->first();

        if (! $earliest || $earliest->year >= $year) {
            return false;
        }

        // Vérifier s'il y a des trous entre le premier exercice et N-1
        $expectedCount = $year - $earliest->year;
        $actualCount   = FiscalYear::withoutGlobalScopes()
            ->where('user_id', auth()->id())
            ->where('year', '>=', $earliest->year)
            ->where('year', '<', $year)
            ->count();

        return $actualCount < $expectedCount;
    }

    // -------------------------------------------------------------------------
    // Aide au formatage
    // -------------------------------------------------------------------------

    private function formatEuros(int $cents): string
    {
        $euros = $cents / 100;
        $sign  = $euros < 0 ? '−&nbsp;' : '';
        return $sign . number_format(abs($euros), 2, ',', '&nbsp;') . '&nbsp;€';
    }

    private function buildYearOptions(int $currentYear): array
    {
        // La liste démarre à la première année de données de l'utilisateur
        // (au plus tard N-2) pour permettre de reconstruire toute la chaîne.
        $start = min(
            app(FiscalYearService::class)->firstDataYear(auth()->user()),
            $currentYear - 2
        );

        $options = [];
        for ($y = $currentYear + 2; $y >= $start; $y--) {
            $options[$y] = $y;
        }
        return $options;
    }

    private function resetComputedData(): void
    {
        // Livewire se charge de la réactivité via live()
    }

    // -------------------------------------------------------------------------
    // Constructeurs HTML des placeholders
    // -------------------------------------------------------------------------

    private function buildYearSummaryHtml(): HtmlString
    {
        $year          = $this->selectedYear();
        $propertyCount = Property::count();
        $incomeCount   = $this->getIncomeCount();
        $expenseCount  = $this->getExpenseCount();
        $existing      = FiscalYear::withoutGlobalScopes()
            ->where('user_id', auth()->id())
            ->where('year', $year)
            ->first();

        $existingBadge = $existing
            ? '<span class="wz-badge">Exercice existant — sera recalculé</span>'
            : '';

        return new HtmlString(
            '<div class="wz-box">'
            . '<p class="wz-box-title">'
            . 'Année ' . $year . $existingBadge
            . '</p>'
            . '<div class="wz-grid">'
            . $this->buildStatCard('Biens', $propertyCount, 'wz-indigo', 'bien(s) enregistré(s)')
            . $this->buildStatCard('Recettes', $incomeCount, 'wz-green', 'ligne(s) de revenu')
            . $this->buildStatCard('Charges', $expenseCount, 'wz-red', 'ligne(s) de charge')
            . '</div>'
            . '</div>'
        );
    }

    private function buildStatCard(string $label, int|string $value, string $colorClass, string $subtitle): string
    {
        return '<div class="wz-stat">'
            . '<div class="wz-stat-value ' . $colorClass . '">' . $value . '</div>'
            . '<div class="wz-stat-label">' . $subtitle . '</div>'
            . '</div>';
    }

    private function buildPlaceholderHtml(int $cents, string $detail, string $colorClass): HtmlString
    {
        return new HtmlString(
            '<div class="wz-amount">'
            . '<span class="wz-amount-value ' . $colorClass . '">' . $this->formatEuros($cents) . '</span>'
            . '<span class="wz-amount-detail">' . $detail . '</span>'
            . '</div>'
        );
    }

    private function buildResultLineHtml(int $cents, string $formula): HtmlString
    {
        $color = $cents >= 0
            ? 'wz-green-dark'
            : 'wz-red-dark';

        return new HtmlString(
            '<div class="wz-amount">'
            . '<span class="wz-amount-value ' . $color . '">' . $this->formatEuros($cents) . '</span>'
            . '<span class="wz-amount-detail">' . $formula . '</span>'
            . '</div>'
        );
    }

    private function buildFiscalResultHtml(): HtmlString
    {
        $result = $this->getFiscalResult();
        $color  = $result <= 0 ? 'wz-result-zero' : 'wz-result-positive';

        $label = $result === 0
            ? 'Résultat nul — aucune imposition au réel'
            : 'Montant imposable au régime réel simplifié';

        return new HtmlString(
            '<div class="wz-result ' . $color . '">'
            . '<div class="wz-result-value">' . $this->formatEuros($result) . '</div>'
            . '<div class="wz-result-label">' . $label . '</div>'
            . '</div>'
        );
    }

    private function buildMicroBicComparisonHtml(): HtmlString
    {
        $year        = $this->selectedYear();
        $properties  = Property::all();

        // CA brut pour le micro-BIC (sans déduire commissions)
        $grossIncome = 0;
        foreach ($properties as $property) {
            $grossIncome += (int) $property->incomes()
                ->whereYear('income_date', $year)
                ->sum('amount');
        }

        $microBic50 = (int) bcmul((string) $grossIncome, '0.5', 0);
        $microBic30 = (int) bcmul((string) $grossIncome, '0.7', 0); // abattement 30 %, 70 % imposable
        $realResult = $this->getFiscalResult();

        $advantageVs50 = $microBic50 - $realResult;
        $advantageVs30 = $microBic30 - $realResult;

        $recommended = $realResult < $microBic50 ? 'réel' : 'micro-BIC (50 %)';

        $rows = [
            ['Régime', 'Base imposable', 'Avantage vs réel'],
            ['Micro-BIC 50 % (classé)', $this->formatEuros($microBic50), $this->formatSignedEuros($advantageVs50)],
            ['Micro-BIC 30 % (non classé)', $this->formatEuros($microBic30), $this->formatSignedEuros($advantageVs30)],
            ['Régime réel simplifié', $this->formatEuros($realResult), '— référence —'],
        ];

        $headerRow = array_shift($rows);

        $tableHtml = '<table class="wz-table">'
            . '<thead><tr>'
            . implode('', array_map(
                fn ($h) => '<th>' . $h . '</th>',
                $headerRow
            ))
            . '</tr></thead><tbody>';

        foreach ($rows as $i => $row) {
            $tableHtml .= '<tr>';
            foreach ($row as $j => $cell) {
                $cls        = $j === 0 ? '' : ' class="wz-num"';
                $tableHtml .= '<td' . $cls . '>' . $cell . '</td>';
            }
            $tableHtml .= '</tr>';
        }

        $tableHtml .= '</tbody></table>';

        $verdictColor = $realResult < $microBic50 ? 'wz-verdict-good' : 'wz-verdict-bad';

        return new HtmlString(
            '<div style="display:flex;flex-direction:column;gap:1rem">'
            . '<div class="wz-table-wrap">' . $tableHtml . '</div>'
            . '<div class="wz-verdict ' . $verdictColor . '">'
            . 'Recommandation : <strong>' . $recommended . '</strong>'
            . '</div>'
            . '</div>'
        );
    }

    private function buildAlertsHtml(): HtmlString
    {
        $alerts  = []; // [message, severity: 'warning'|'danger']
        $year    = $this->selectedYear();

        if (Property::count() === 0) {
            $alerts[] = ['Aucun bien immobilier enregistré. Créez au moins un bien avant de lancer la clôture.', 'danger'];
        } elseif ($this->getYearIncomeCents() === 0) {
            $alerts[] = ['Aucune recette trouvée pour ' . $year . '. L\'exercice sera créé avec un résultat nul.', 'warning'];
        }

        $prevStatus = $this->getPreviousYearStatus();
        if ($prevStatus === 'missing' && $this->previousYearMissingError() !== null) {
            $alerts[] = [
                'L\'exercice ' . ($year - 1) . ' n\'existe pas. Les amortissements différés de ' . ($year - 1) . ' ne seront pas reportés. '
                . '<a href="' . route('filament.admin.pages.fiscal-year-wizard') . '" class="underline font-semibold">Créez d\'abord l\'exercice ' . ($year - 1) . '</a>.',
                'danger',
            ];
        } elseif ($prevStatus === 'draft') {
            $alerts[] = [
                'L\'exercice ' . ($year - 1) . ' est encore en brouillon. Le montant des amortissements différés reportés pourrait changer si vous le recalculez ou le modifiez.',
                'warning',
            ];
        }

        if ($this->hasChainGap()) {
            $alerts[] = [
                'Des exercices intermédiaires manquent dans la chaîne de reports. Les amortissements différés pourraient ne pas être correctement propagés.',
                'danger',
            ];
        }

        if (empty($alerts)) {
            return new HtmlString('');
        }

        $html = '';
        foreach ($alerts as [$message, $severity]) {
            $icon  = $severity === 'danger' ? '🔴' : '⚠';
            $cls   = $severity === 'danger' ? 'wz-alert-danger' : 'wz-alert-warning';

            $html .= '<div class="wz-alert ' . $cls . '">'
                . '<span class="wz-alert-icon">' . $icon . '</span>'
                . '<span>' . $message . '</span>'
                . '</div>';
        }

        return new HtmlString('<div class="wz-alerts">' . $html . '</div>');
    }

    private function buildConfirmationSummaryHtml(): HtmlString
    {
        $year    = $this->selectedYear();
        $status  = ($this->data['status'] ?? FiscalYear::STATUS_DRAFT) === FiscalYear::STATUS_CLOSED
            ? 'Clôturé'
            : 'Brouillon';

        $lines = [
            ['Année fiscale', (string) $year],
            ['Recettes nettes', $this->formatEuros($this->getYearIncomeCents())],
            ['Charges déductibles', $this->formatEuros($this->getYearExpensesCents())],
            ['Amortissements de l\'année', $this->formatEuros($this->getYearDepreciationCents())],
            ['Amortissements différés N−1', $this->formatEuros($this->getPreviousDeferred())],
            ['Amortissement plafonné (déduit)', $this->formatEuros($this->getCappedDepreciation())],
            ['Amortissement différé (reporté)', $this->formatEuros($this->getDeferredDepreciation())],
            ['Résultat fiscal', $this->formatEuros($this->getFiscalResult())],
            ['Statut', $status],
        ];

        $rows = implode('', array_map(function ($line) {
            [$label, $value] = $line;
            return '<tr>'
                . '<td>' . $label . '</td>'
                . '<td>' . $value . '</td>'
                . '</tr>';
        }, $lines));

        return new HtmlString(
            '<div class="wz-confirm">'
            . '<table><tbody>' . $rows . '</tbody></table>'
            . '</div>'
        );
    }

    private function formatSignedEuros(int $cents): string
    {
        if ($cents === 0) {
            return '<span class="wz-muted">0,00&nbsp;€</span>';
        }

        if ($cents > 0) {
            return '<span class="wz-green">+&nbsp;' . $this->formatEuros($cents) . '</span>';
        }

        return '<span class="wz-red">' . $this->formatEuros($cents) . '</span>';
    }

    private function buildStatusHintHtml(): HtmlString
    {
        $isClosed = ($this->data['status'] ?? FiscalYear::STATUS_DRAFT) === FiscalYear::STATUS_CLOSED;

        if ($isClosed) {
            return new HtmlString(
                '<div class="wz-alert wz-alert-warning">'
                . '<span class="wz-alert-icon">🔒</span>'
                . '<span>L\'exercice sera <strong>clôturé définitivement</strong>. '
                . 'Vous ne pourrez plus modifier les montants. '
                . 'La liasse fiscale et le FEC seront disponibles au téléchargement.</span>'
                . '</div>'
            );
        }

        return new HtmlString(
            '<div class="wz-status-info">'
            . '📝 L\'exercice sera enregistré en <strong>brouillon</strong>. '
            . 'Vous pourrez le modifier et le clôturer plus tard depuis la liste des exercices.'
            . '</div>'
        );
    }
}
