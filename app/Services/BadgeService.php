<?php

namespace App\Services;

use App\Models\BadgeDefinition;
use App\Models\BadgeProgress;
use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Loan;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;
use App\Models\UserBadge;
use Filament\Notifications\Notification;

class BadgeService
{
    /**
     * Evaluate badges relevant to a specific trigger context.
     */
    public function evaluate(User $user, string $trigger, array $context = []): void
    {
        $badges = BadgeDefinition::active()->orderBy('sort_order')->get();

        foreach ($badges as $badge) {
            $this->evaluateBadge($user, $badge, $trigger, $context);
        }
    }

    /**
     * Evaluate all badges for a user (used for backdating).
     */
    public function evaluateAll(User $user, bool $silent = false): int
    {
        $awarded = 0;
        $badges = BadgeDefinition::active()->orderBy('sort_order')->get();

        foreach ($badges as $badge) {
            if ($badge->is_yearly) {
                $years = $this->getActiveYears($user);
                foreach ($years as $year) {
                    if ($this->checkConditions($user, $badge, $year) && !$user->hasBadge($badge->code, $year)) {
                        $this->award($user, $badge, $year, ['backdated' => true], $silent);
                        $awarded++;
                    }
                }
            } else {
                if ($this->checkConditions($user, $badge) && !$user->hasBadge($badge->code)) {
                    $this->award($user, $badge, null, ['backdated' => true], $silent);
                    $awarded++;
                }
            }
        }

        return $awarded;
    }

    /**
     * Get fiscal year completeness score (0-100).
     */
    public function getCompletenessScore(User $user, int $year): array
    {
        $propertyIds = $user->properties()->pluck('id');

        $hasIncomes = Income::whereIn('property_id', $propertyIds)
            ->whereYear('income_date', $year)->exists();

        $hasExpenses = Expense::whereIn('property_id', $propertyIds)
            ->whereYear('expense_date', $year)->exists();

        $expenseCount = Expense::whereIn('property_id', $propertyIds)
            ->whereYear('expense_date', $year)->count();
        $withReceipt = Expense::whereIn('property_id', $propertyIds)
            ->whereYear('expense_date', $year)->whereNotNull('receipt_path')->count();

        $hasComponents = PropertyComponent::whereIn('property_id', $propertyIds)->exists();

        $scores = [
            'incomes' => $hasIncomes ? 25 : 0,
            'expenses' => $hasExpenses ? 25 : 0,
            'depreciation' => $hasComponents ? 25 : 0,
            'receipts' => $expenseCount > 0 ? (int) round($withReceipt / $expenseCount * 25) : 0,
        ];

        $scores['total'] = array_sum($scores);

        return $scores;
    }

    /**
     * Get monthly data presence for a year (heatmap).
     */
    public function getMonthlyHeatmap(User $user, int $year): array
    {
        $propertyIds = $user->properties()->pluck('id');
        $heatmap = [];

        for ($m = 1; $m <= 12; $m++) {
            $hasIncome = Income::whereIn('property_id', $propertyIds)
                ->whereYear('income_date', $year)
                ->whereMonth('income_date', $m)
                ->exists();

            $hasExpense = Expense::whereIn('property_id', $propertyIds)
                ->whereYear('expense_date', $year)
                ->whereMonth('expense_date', $m)
                ->exists();

            $heatmap[$m] = match (true) {
                $hasIncome && $hasExpense => 'complete',
                $hasIncome || $hasExpense => 'partial',
                default => 'empty',
            };
        }

        return $heatmap;
    }

    /**
     * Get the next achievable badge for a user.
     */
    public function getNextAchievable(User $user): ?array
    {
        $badges = BadgeDefinition::active()->orderBy('sort_order')->get();
        $year = (int) date('Y');

        foreach ($badges as $badge) {
            $fy = $badge->is_yearly ? $year : null;

            if ($user->hasBadge($badge->code, $fy)) {
                continue;
            }

            $progress = $this->getProgress($user, $badge, $fy);
            if ($progress !== null && $progress['percentage'] > 0) {
                return [
                    'badge' => $badge,
                    'progress' => $progress,
                ];
            }
        }

        return null;
    }

    private function evaluateBadge(User $user, BadgeDefinition $badge, string $trigger, array $context): void
    {
        if ($badge->is_yearly) {
            $year = $context['fiscal_year'] ?? (int) date('Y');
            if ($this->checkConditions($user, $badge, $year) && !$user->hasBadge($badge->code, $year)) {
                $this->award($user, $badge, $year, $context);
            }
            $this->updateProgress($user, $badge, $year);
        } else {
            if ($this->checkConditions($user, $badge) && !$user->hasBadge($badge->code)) {
                $this->award($user, $badge, null, $context);
            }
        }
    }

    private function checkConditions(User $user, BadgeDefinition $badge, ?int $year = null): bool
    {
        $conditions = $badge->unlock_conditions;
        $type = $conditions['type'] ?? '';

        return match ($type) {
            'property_count' => $this->checkPropertyCount($user, $conditions['min'] ?? 1),
            'component_count' => $this->checkComponentCount($user, $conditions['min'] ?? 5),
            'csv_imported' => $this->checkCsvImported($user),
            'loan_count' => $this->checkLoanCount($user, $conditions['min'] ?? 1),
            'fiscal_year_count' => $this->checkFiscalYearCount($user, $conditions['min'] ?? 1),
            'complete_months' => $this->checkCompleteMonths($user, $year, $conditions['min'] ?? 1),
            'consecutive_months' => $this->checkConsecutiveMonths($user, $year, $conditions['min'] ?? 3),
            'closed_before_date' => $this->checkClosedBeforeDate($user, $year, $conditions['month'] ?? 4, $conditions['day'] ?? 1),
            'receipt_coverage' => $this->checkReceiptCoverage($user, $year, $conditions['min_percent'] ?? 100),
            'no_other_category' => $this->checkNoOtherCategory($user, $year),
            'fec_generated' => $this->checkFecGenerated($user, $year),
            'tax_return_generated' => $this->checkTaxReturnGenerated($user, $year),
            'all_incomes_imported' => $this->checkAllIncomesImported($user, $year),
            'simulator_used' => true, // Triggered directly from the page
            'projection_used' => true, // Triggered directly from the page
            'deferred_depreciation' => $this->checkDeferredDepreciation($user),
            'closed_fiscal_years' => $this->checkClosedFiscalYears($user, $conditions['min'] ?? 3),
            default => false,
        };
    }

    private function award(User $user, BadgeDefinition $badge, ?int $fiscalYear, array $context = [], bool $silent = false): void
    {
        $userBadge = $user->awardBadge($badge, $fiscalYear, $context);

        if ($userBadge && !$silent) {
            $name = $badge->name;
            if ($fiscalYear) {
                $name .= " {$fiscalYear}";
            }

            Notification::make()
                ->title($name)
                ->body($badge->description)
                ->icon($badge->icon)
                ->iconColor($badge->color)
                ->success()
                ->send();
        }
    }

    private function updateProgress(User $user, BadgeDefinition $badge, int $year): void
    {
        $conditions = $badge->unlock_conditions;
        $type = $conditions['type'] ?? '';

        $progressData = match ($type) {
            'complete_months' => $this->getCompleteMonthsProgress($user, $year, $conditions['min'] ?? 1),
            'consecutive_months' => $this->getConsecutiveMonthsProgress($user, $year, $conditions['min'] ?? 3),
            'receipt_coverage' => $this->getReceiptProgress($user, $year),
            default => null,
        };

        if ($progressData === null) {
            return;
        }

        BadgeProgress::updateOrCreate(
            [
                'user_id' => $user->id,
                'badge_definition_id' => $badge->id,
                'fiscal_year' => $year,
            ],
            [
                'current_value' => $progressData['current'],
                'target_value' => $progressData['target'],
                'metadata' => $progressData['metadata'] ?? null,
            ],
        );
    }

    private function getProgress(User $user, BadgeDefinition $badge, ?int $fiscalYear): ?array
    {
        $progress = BadgeProgress::where('user_id', $user->id)
            ->where('badge_definition_id', $badge->id)
            ->when($fiscalYear !== null, fn ($q) => $q->where('fiscal_year', $fiscalYear))
            ->when($fiscalYear === null, fn ($q) => $q->whereNull('fiscal_year'))
            ->first();

        if (!$progress) {
            return null;
        }

        return [
            'current' => $progress->current_value,
            'target' => $progress->target_value,
            'percentage' => $progress->percentage,
        ];
    }

    // === Condition Checkers ===

    private function checkPropertyCount(User $user, int $min): bool
    {
        return $user->properties()->count() >= $min;
    }

    private function checkComponentCount(User $user, int $min): bool
    {
        return PropertyComponent::whereIn('property_id', $user->properties()->pluck('id'))
            ->count() >= $min;
    }

    private function checkCsvImported(User $user): bool
    {
        return Income::whereIn('property_id', $user->properties()->pluck('id'))
            ->whereNotNull('reservation_ref')
            ->exists();
    }

    private function checkLoanCount(User $user, int $min): bool
    {
        return Loan::whereIn('property_id', $user->properties()->pluck('id'))
            ->count() >= $min;
    }

    private function checkFiscalYearCount(User $user, int $min): bool
    {
        return $user->fiscalYears()->count() >= $min;
    }

    private function checkCompleteMonths(User $user, ?int $year, int $min): bool
    {
        if (!$year) {
            return false;
        }

        $count = $this->countCompleteMonths($user, $year);

        return $count >= $min;
    }

    private function checkConsecutiveMonths(User $user, ?int $year, int $min): bool
    {
        if (!$year) {
            return false;
        }

        $maxConsecutive = $this->maxConsecutiveCompleteMonths($user, $year);

        return $maxConsecutive >= $min;
    }

    private function checkClosedBeforeDate(User $user, ?int $year, int $month, int $day): bool
    {
        if (!$year) {
            return false;
        }

        $fiscalYear = $user->fiscalYears()
            ->where('year', $year)
            ->where('status', FiscalYear::STATUS_CLOSED)
            ->first();

        if (!$fiscalYear) {
            return false;
        }

        $deadline = \Carbon\Carbon::create($year + 1, $month, $day);

        return $fiscalYear->updated_at->lt($deadline);
    }

    private function checkReceiptCoverage(User $user, ?int $year, int $minPercent): bool
    {
        if (!$year) {
            return false;
        }

        $propertyIds = $user->properties()->pluck('id');
        $total = Expense::whereIn('property_id', $propertyIds)
            ->whereYear('expense_date', $year)->count();

        if ($total === 0) {
            return false;
        }

        $withReceipt = Expense::whereIn('property_id', $propertyIds)
            ->whereYear('expense_date', $year)
            ->whereNotNull('receipt_path')
            ->count();

        return ($withReceipt / $total * 100) >= $minPercent;
    }

    private function checkNoOtherCategory(User $user, ?int $year): bool
    {
        if (!$year) {
            return false;
        }

        $propertyIds = $user->properties()->pluck('id');

        $total = Expense::whereIn('property_id', $propertyIds)
            ->whereYear('expense_date', $year)->count();

        if ($total === 0) {
            return false;
        }

        return !Expense::whereIn('property_id', $propertyIds)
            ->whereYear('expense_date', $year)
            ->where('category', Expense::CATEGORY_OTHER)
            ->exists();
    }

    private function checkFecGenerated(User $user, ?int $year): bool
    {
        if (!$year) {
            return false;
        }

        return $user->fiscalYears()
            ->where('year', $year)
            ->whereNotNull('fec_path')
            ->exists();
    }

    private function checkTaxReturnGenerated(User $user, ?int $year): bool
    {
        if (!$year) {
            return false;
        }

        return $user->fiscalYears()
            ->where('year', $year)
            ->whereNotNull('pdf_path')
            ->exists();
    }

    private function checkAllIncomesImported(User $user, ?int $year): bool
    {
        if (!$year) {
            return false;
        }

        $propertyIds = $user->properties()->pluck('id');
        $total = Income::whereIn('property_id', $propertyIds)
            ->whereYear('income_date', $year)->count();

        if ($total === 0) {
            return false;
        }

        $imported = Income::whereIn('property_id', $propertyIds)
            ->whereYear('income_date', $year)
            ->whereNotNull('reservation_ref')
            ->count();

        return $imported === $total;
    }

    private function checkDeferredDepreciation(User $user): bool
    {
        return $user->fiscalYears()
            ->where('deferred_depreciation', '>', 0)
            ->exists();
    }

    private function checkClosedFiscalYears(User $user, int $min): bool
    {
        return $user->fiscalYears()
            ->where('status', FiscalYear::STATUS_CLOSED)
            ->count() >= $min;
    }

    // === Progress Helpers ===

    private function countCompleteMonths(User $user, int $year): int
    {
        $propertyIds = $user->properties()->pluck('id');
        $count = 0;

        for ($m = 1; $m <= 12; $m++) {
            $hasIncome = Income::whereIn('property_id', $propertyIds)
                ->whereYear('income_date', $year)
                ->whereMonth('income_date', $m)
                ->exists();

            $hasExpense = Expense::whereIn('property_id', $propertyIds)
                ->whereYear('expense_date', $year)
                ->whereMonth('expense_date', $m)
                ->exists();

            if ($hasIncome && $hasExpense) {
                $count++;
            }
        }

        return $count;
    }

    private function maxConsecutiveCompleteMonths(User $user, int $year): int
    {
        $propertyIds = $user->properties()->pluck('id');
        $max = 0;
        $current = 0;

        for ($m = 1; $m <= 12; $m++) {
            $hasIncome = Income::whereIn('property_id', $propertyIds)
                ->whereYear('income_date', $year)
                ->whereMonth('income_date', $m)
                ->exists();

            $hasExpense = Expense::whereIn('property_id', $propertyIds)
                ->whereYear('expense_date', $year)
                ->whereMonth('expense_date', $m)
                ->exists();

            if ($hasIncome && $hasExpense) {
                $current++;
                $max = max($max, $current);
            } else {
                $current = 0;
            }
        }

        return $max;
    }

    private function getCompleteMonthsProgress(User $user, int $year, int $target): array
    {
        $count = $this->countCompleteMonths($user, $year);

        return [
            'current' => $count,
            'target' => $target,
        ];
    }

    private function getConsecutiveMonthsProgress(User $user, int $year, int $target): array
    {
        $max = $this->maxConsecutiveCompleteMonths($user, $year);

        return [
            'current' => $max,
            'target' => $target,
        ];
    }

    private function getReceiptProgress(User $user, int $year): array
    {
        $propertyIds = $user->properties()->pluck('id');
        $total = Expense::whereIn('property_id', $propertyIds)
            ->whereYear('expense_date', $year)->count();

        $withReceipt = Expense::whereIn('property_id', $propertyIds)
            ->whereYear('expense_date', $year)
            ->whereNotNull('receipt_path')
            ->count();

        return [
            'current' => $withReceipt,
            'target' => max($total, 1),
        ];
    }

    private function getActiveYears(User $user): array
    {
        $propertyIds = $user->properties()->pluck('id');

        $incomeYears = Income::whereIn('property_id', $propertyIds)
            ->selectRaw("DISTINCT strftime('%Y', income_date) as y")
            ->pluck('y')
            ->toArray();

        $expenseYears = Expense::whereIn('property_id', $propertyIds)
            ->selectRaw("DISTINCT strftime('%Y', expense_date) as y")
            ->pluck('y')
            ->toArray();

        $fiscalYears = $user->fiscalYears()->pluck('year')->toArray();

        $years = array_unique(array_merge($incomeYears, $expenseYears, array_map('strval', $fiscalYears)));
        sort($years);

        return array_map('intval', $years);
    }
}
