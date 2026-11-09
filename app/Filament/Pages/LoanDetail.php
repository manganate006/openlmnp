<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\NavigationAware;
use App\Filament\Resources\Loans\LoanResource;
use App\Models\Loan;
use App\Services\LoanService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use UnitEnum;

class LoanDetail extends Page
{
    use NavigationAware;

    protected static bool $shouldRegisterNavigation = false;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;
    protected static string | UnitEnum | null $navigationGroup = 'Mes biens';
    protected static ?string $navigationLabel = 'Détail emprunt';
    protected static ?string $title = 'Tableau d\'amortissement emprunt';
    protected static ?int $navigationSort = 6;

    protected static function getGuidedNavigationGroup(): string
    {
        return 'Mise en route';
    }
    protected string $view = 'filament.pages.loan-detail';

    #[Url]
    public ?int $loanId = null;

    public function mount(): void
    {
        // Le scope BelongsToUserThroughPropertyScope limite la requête aux
        // emprunts de l'utilisateur : un loanId étranger est donc introuvable.
        if ($this->loanId && ! Loan::whereKey($this->loanId)->exists()) {
            Notification::make()
                ->title('Emprunt introuvable')
                ->body('Cet emprunt n\'existe pas ou ne vous appartient pas.')
                ->warning()
                ->send();

            $this->redirect(LoanResource::getUrl());

            return;
        }

        if (! $this->loanId) {
            $this->loanId = Loan::query()->value('id');
        }
    }

    #[Computed]
    public function loanData(): ?array
    {
        if (! $this->loanId) {
            return null;
        }

        $loan = Loan::with(['property', 'payments'])->find($this->loanId);
        if (! $loan) {
            return null;
        }

        $payments = $loan->payments()->orderBy('month_number')->get();
        $today = now()->format('Y-m-d');

        // Statistiques globales
        $totalInterest = $payments->sum('interest_amount');
        $totalInsurance = $payments->sum('insurance_amount');
        $totalCost = $totalInterest + $totalInsurance;
        $paidPayments = $payments->filter(fn ($p) => $p->payment_date->format('Y-m-d') <= $today);
        $paidCapital = $paidPayments->sum('capital_amount');
        $paidInterest = $paidPayments->sum('interest_amount');
        $remainingCapital = $loan->amount - $paidCapital;
        $remainingInterest = $totalInterest - $paidInterest;
        $progressPct = $loan->amount > 0 ? round($paidCapital / $loan->amount * 100, 1) : 0;

        // Prochaines échéances (6 prochaines)
        $nextPayments = $payments->filter(fn ($p) => $p->payment_date->format('Y-m-d') > $today)->take(6);

        // Intérêts déductibles par année (avec quote-part, sauf emprunt dédié)
        $quotaShare = $loan->property?->quota_share ?? '1';
        $interestByYear = [];
        foreach ($payments->groupBy(fn ($p) => $p->payment_date->format('Y')) as $year => $yearPayments) {
            $yearInterest = $yearPayments->sum('interest_amount');
            $yearInsurance = $yearPayments->sum('insurance_amount');
            $deductible = (int) $loan->prorate((string) ($yearInterest + $yearInsurance), $quotaShare);
            $interestByYear[$year] = [
                'interest' => $yearInterest,
                'insurance' => $yearInsurance,
                'deductible' => $deductible,
            ];
        }

        return [
            'loan' => $loan,
            'property' => $loan->property,
            'quota_share' => $quotaShare,
            'payments' => $payments,
            'total_interest' => $totalInterest,
            'total_insurance' => $totalInsurance,
            'total_cost' => $totalCost,
            'paid_capital' => $paidCapital,
            'paid_interest' => $paidInterest,
            'remaining_capital' => $remainingCapital,
            'remaining_interest' => $remainingInterest,
            'progress_pct' => $progressPct,
            'next_payments' => $nextPayments,
            'interest_by_year' => $interestByYear,
            'paid_months' => $paidPayments->count(),
            'total_months' => $payments->count(),
        ];
    }

    public function updatedLoanId(): void
    {
        unset($this->loanData);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recomputeSchedule')
                ->label('Recalculer le tableau théorique')
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Remplacer les échéances importées ?')
                ->modalDescription(
                    'Ce prêt utilise le tableau d\'amortissement importé de votre banque : il porte les '
                    . 'montants réels (différé, assurance dégressive, modulation). Le recalcul les remplacera '
                    . 'par des échéances théoriques, ce qui peut faire varier les intérêts déductibles que '
                    . 'vous avez déjà déclarés.'
                )
                ->modalSubmitActionLabel('Recalculer quand même')
                // N'a de sens que sur un tableau importé : ailleurs, il est déjà théorique.
                ->visible(fn () => $this->currentLoan()?->hasImportedSchedule() ?? false)
                ->action(function () {
                    $loan = $this->currentLoan();

                    if ($loan === null) {
                        return;
                    }

                    app(LoanService::class)->generateSchedule($loan, force: true);

                    unset($this->loanData);

                    Notification::make()
                        ->title('Tableau théorique recalculé')
                        ->body('Les échéances importées ont été remplacées.')
                        ->success()
                        ->send();
                }),
        ];
    }

    private function currentLoan(): ?Loan
    {
        return $this->loanId ? Loan::find($this->loanId) : null;
    }

    /**
     * Écart entre les intérêts réellement facturés par la banque et ceux que
     * notre calcul théorique aurait produits, sur l'année en cours.
     *
     * C'est le chiffre qui justifie l'import : il dit à l'utilisateur combien
     * son tableau réel diffère de l'estimation.
     */
    #[Computed]
    public function theoreticalGap(): ?array
    {
        $loan = $this->currentLoan();

        if ($loan === null || ! $loan->hasImportedSchedule()) {
            return null;
        }

        $year = (int) date('Y');
        $reels = $loan->getInterestsForYear($year);

        // Tableau théorique calculé À BLANC : aucune écriture en base.
        $theoriques = $this->theoreticalInterests($loan, $year);

        return [
            'year' => $year,
            'imported' => $reels,
            'theoretical' => $theoriques,
            'gap' => (int) bcsub((string) $reels, (string) $theoriques, 0),
        ];
    }

    /**
     * Intérêts théoriques d'une année, simulés sans toucher aux LoanPayment
     * existants — les recalculer en base effacerait le tableau importé.
     */
    private function theoreticalInterests(Loan $loan, int $year): int
    {
        $remaining = (int) $loan->amount;
        $monthlyRate = ((float) $loan->annual_rate / 100) / 12;
        $monthly = (int) $loan->monthly_payment;
        $total = 0;

        for ($i = 1; $i <= (int) $loan->duration_months; $i++) {
            $interest = (int) round($remaining * $monthlyRate);
            $capital = $i === (int) $loan->duration_months ? $remaining : $monthly - $interest;

            if ((int) $loan->start_date->copy()->addMonths($i)->format('Y') === $year) {
                $total = (int) bcadd((string) $total, (string) $interest, 0);
            }

            $remaining = max(0, $remaining - $capital);
        }

        return $total;
    }
}
