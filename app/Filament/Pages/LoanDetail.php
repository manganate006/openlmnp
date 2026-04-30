<?php

namespace App\Filament\Pages;

use App\Models\Loan;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use UnitEnum;

class LoanDetail extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;
    protected static string | UnitEnum | null $navigationGroup = 'Comptabilité';
    protected static ?string $navigationLabel = 'Détail emprunt';
    protected static ?string $title = 'Tableau d\'amortissement emprunt';
    protected static ?int $navigationSort = 4;
    protected string $view = 'filament.pages.loan-detail';

    public ?int $loanId = null;

    public function mount(): void
    {
        $loan = Loan::whereHas('property', fn ($q) => $q->where('user_id', auth()->id()))->first();
        $this->loanId = $loan?->id;
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

        // Intérêts déductibles par année (avec quote-part)
        $quotaShare = $loan->property->quota_share;
        $interestByYear = [];
        foreach ($payments->groupBy(fn ($p) => $p->payment_date->format('Y')) as $year => $yearPayments) {
            $yearInterest = $yearPayments->sum('interest_amount');
            $yearInsurance = $yearPayments->sum('insurance_amount');
            $deductible = (int) bcmul((string) ($yearInterest + $yearInsurance), $quotaShare, 0);
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
}
