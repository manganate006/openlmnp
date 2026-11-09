<?php

namespace App\Filament\Pages;

use App\Enums\AccountKind;
use App\Filament\Pages\Concerns\RequiresAdmin;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class AdminStats extends Page
{
    use RequiresAdmin;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;
    protected static string | UnitEnum | null $navigationGroup = 'Administration';
    protected static ?string $navigationLabel = 'Statistiques';
    protected static ?string $title = 'Statistiques globales';
    protected static ?int $navigationSort = 3;
    protected string $view = 'filament.pages.admin-stats';

    public function getStats(): array
    {
        return [
            'users' => $this->getUserStats(),
            'properties' => $this->getPropertyStats(),
            'financial' => $this->getFinancialStats(),
            'activity' => $this->getActivityStats(),
            'fiscal' => $this->getFiscalStats(),
        ];
    }

    private function getUserStats(): array
    {
        // Un dossier lié (App\Support\DossierContext) n'est pas un compte qui s'est
        // « inscrit » : il est créé par un principal, offert, sans connexion directe.
        // Le compter ici doublerait artificiellement la croissance de l'instance.
        $total = DB::table('users')->where('is_linked_dossier', false)->count();
        $lastMonth = DB::table('users')
            ->where('is_linked_dossier', false)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();
        $lastRegistered = DB::table('users')
            ->where('is_linked_dossier', false)
            ->orderByDesc('created_at')
            ->value('created_at');

        // Comptes « membres » (lot « dossiers membre », décision du 2026-10-15) : des
        // accès gratuits d'indivisaire, jamais des abonnés — comptés À PART pour ne
        // pas gonfler artificiellement la croissance payante de l'instance.
        $members = DB::table('users')
            ->where('is_linked_dossier', false)
            ->where('account_kind', AccountKind::Member->value)
            ->count();

        return [
            'total' => $total,
            'last_30_days' => $lastMonth,
            'last_registered' => $lastRegistered,
            'members' => $members,
        ];
    }

    private function getPropertyStats(): array
    {
        $total = DB::table('properties')->count();
        $byType = DB::table('properties')
            ->select('type', DB::raw('count(*) as count'))
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();
        $totalValue = DB::table('properties')->sum('market_value');
        $totalArea = DB::table('properties')->sum('rented_area');
        $withLoans = DB::table('loans')->distinct('property_id')->count('property_id');

        return [
            'total' => $total,
            'by_type' => $byType,
            'total_value_cents' => $totalValue,
            'total_rented_area' => $totalArea,
            'with_loans' => $withLoans,
        ];
    }

    private function getFinancialStats(): array
    {
        $currentYear = (int) date('Y');

        $totalIncome = DB::table('incomes')
            ->whereYear('income_date', $currentYear)
            ->sum('amount');
        $totalExpenses = DB::table('expenses')
            ->whereYear('expense_date', $currentYear)
            ->sum('amount');
        $incomeBySource = DB::table('incomes')
            ->whereYear('income_date', $currentYear)
            ->select('source', DB::raw('count(*) as count'), DB::raw('sum(amount) as total'))
            ->groupBy('source')
            ->get()
            ->keyBy('source')
            ->toArray();
        $expenseByCategory = DB::table('expenses')
            ->whereYear('expense_date', $currentYear)
            ->select('category', DB::raw('count(*) as count'), DB::raw('sum(amount) as total'))
            ->groupBy('category')
            ->get()
            ->keyBy('category')
            ->toArray();
        $totalLoansCapital = DB::table('loans')->sum('amount');
        $reservationCount = DB::table('incomes')
            ->whereYear('income_date', $currentYear)
            ->count();

        return [
            'year' => $currentYear,
            'total_income_cents' => $totalIncome,
            'total_expenses_cents' => $totalExpenses,
            'income_by_source' => $incomeBySource,
            'expense_by_category' => $expenseByCategory,
            'total_loans_capital_cents' => $totalLoansCapital,
            'reservation_count' => $reservationCount,
        ];
    }

    private function getActivityStats(): array
    {
        return [
            'total_incomes' => DB::table('incomes')->count(),
            'total_expenses' => DB::table('expenses')->count(),
            'total_loans' => DB::table('loans')->count(),
            'total_furniture' => DB::table('furniture')->count(),
            'total_works' => DB::table('property_works')->count(),
            'total_components' => DB::table('property_components')->count(),
            'total_accounting_entries' => DB::table('accounting_entries')->count(),
            // Analyses de justificatifs abouties (hors appels de suivi
            // automatiques, qui ne correspondent à aucun document de plus).
            'total_ai_documents' => DB::table('ai_usage_logs')
                ->where('feature', 'documents')
                ->where('status', 'ok')
                ->count(),
        ];
    }

    private function getFiscalStats(): array
    {
        $years = DB::table('fiscal_years')
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
        $withPdf = DB::table('fiscal_years')
            ->whereNotNull('tax_return_generated_at')
            ->count();
        $withFec = DB::table('fiscal_years')
            ->whereNotNull('fec_generated_at')
            ->count();
        $totalDeferred = DB::table('fiscal_years')
            ->sum('deferred_depreciation');

        return [
            'by_status' => $years,
            'with_pdf' => $withPdf,
            'with_fec' => $withFec,
            'total_deferred_cents' => $totalDeferred,
        ];
    }
}
