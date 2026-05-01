<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Emprunt immobilier associé à un bien LMNP.
 *
 * Les intérêts d'emprunt sont déductibles en charges (compte 661).
 * L'assurance emprunteur est déductible séparément (compte 616).
 *
 * @property int         $id
 * @property int         $property_id
 * @property string|null $bank_name
 * @property int         $amount             centimes - capital emprunté
 * @property float       $annual_rate        taux annuel (ex : 1.500 pour 1,5 %)
 * @property int         $duration_months
 * @property \Carbon\Carbon $start_date
 * @property int         $monthly_payment    centimes
 * @property int         $insurance_monthly  centimes
 */
class Loan extends Model
{
    public const INSURANCE_FIXED = 'fixed';
    public const INSURANCE_VARIABLE = 'variable';

    protected $fillable = [
        'property_id',
        'bank_name',
        'amount',
        'annual_rate',
        'duration_months',
        'start_date',
        'monthly_payment',
        'insurance_monthly',
        'insurance_type',
        'insurance_rate',
    ];

    public static function insuranceTypeLabels(): array
    {
        return [
            self::INSURANCE_FIXED => 'Montant fixe mensuel',
            self::INSURANCE_VARIABLE => 'Taux sur capital restant dû',
        ];
    }

    protected function casts(): array
    {
        return [
            'start_date'  => 'date',
            'annual_rate' => 'decimal:3',
        ];
    }

    // -------------------------------------------------------------------------
    // Accesseurs euros
    // -------------------------------------------------------------------------

    public function getAmountEurosAttribute(): string
    {
        return bcdiv((string) $this->amount, '100', 2);
    }

    public function getMonthlyPaymentEurosAttribute(): string
    {
        return bcdiv((string) $this->monthly_payment, '100', 2);
    }

    public function getInsuranceMonthlyEurosAttribute(): string
    {
        return bcdiv((string) $this->insurance_monthly, '100', 2);
    }

    // -------------------------------------------------------------------------
    // Accesseurs calculés
    // -------------------------------------------------------------------------

    /**
     * Date de fin théorique de l'emprunt.
     */
    public function getEndDateAttribute(): \Carbon\Carbon
    {
        return $this->start_date->copy()->addMonths($this->duration_months);
    }

    /**
     * Durée en années (arrondie).
     */
    public function getDurationYearsAttribute(): int
    {
        return (int) ceil($this->duration_months / 12);
    }

    /**
     * Coût total de l'emprunt (intérêts + assurances).
     * Calculé à partir du tableau d'amortissement si disponible,
     * sinon estimé.
     */
    public function getTotalCostAttribute(): int
    {
        if ($this->payments()->exists()) {
            $totalInterests  = $this->payments()->sum('interest_amount');
            $totalInsurances = $this->payments()->sum('insurance_amount');

            return (int) bcadd((string) $totalInterests, (string) $totalInsurances, 0);
        }

        // Estimation simple
        $monthlyInsurance = (int) bcmul(
            (string) $this->insurance_monthly,
            (string) $this->duration_months,
            0
        );

        $estimatedTotal = (int) bcmul(
            (string) $this->monthly_payment,
            (string) $this->duration_months,
            0
        );

        return (int) bcsub(
            bcadd((string) $estimatedTotal, (string) $monthlyInsurance, 0),
            (string) $this->amount,
            0
        );
    }

    // -------------------------------------------------------------------------
    // Méthodes métier
    // -------------------------------------------------------------------------

    /**
     * Retourne le total des intérêts déductibles pour une année fiscale.
     */
    public function getInterestsForYear(int $year): int
    {
        return (int) $this->payments()
            ->whereYear('payment_date', $year)
            ->sum('interest_amount');
    }

    /**
     * Retourne le total des assurances déductibles pour une année fiscale.
     */
    public function getInsuranceForYear(int $year): int
    {
        return (int) $this->payments()
            ->whereYear('payment_date', $year)
            ->sum('insurance_amount');
    }

    /**
     * Capital restant dû à la fin d'une année donnée.
     */
    public function getRemainingCapitalAtEndOfYear(int $year): int
    {
        $lastPayment = $this->payments()
            ->whereYear('payment_date', '<=', $year)
            ->orderByDesc('payment_date')
            ->orderByDesc('month_number')
            ->first();

        return $lastPayment?->remaining_capital ?? $this->amount;
    }

    // -------------------------------------------------------------------------
    // Query scopes
    // -------------------------------------------------------------------------

    /**
     * Emprunts actifs (non remboursés intégralement).
     */
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where('start_date', '<=', now()->toDateString())
              ->where(function ($q) {
                  $q->whereRaw('DATE_ADD(start_date, INTERVAL duration_months MONTH) >= ?', [now()->toDateString()])
                    ->orWhereDoesntHave('payments', function ($pq) {
                        $pq->where('remaining_capital', 0);
                    });
              });
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(LoanPayment::class)->orderBy('month_number');
    }
}
