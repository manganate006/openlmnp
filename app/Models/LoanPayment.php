<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne du tableau d'amortissement d'un emprunt.
 *
 * Chaque ligne correspond à une échéance mensuelle.
 * capital_amount + interest_amount = mensualité hors assurance.
 *
 * @property int         $id
 * @property int         $loan_id
 * @property \Carbon\Carbon $payment_date
 * @property int         $month_number       numéro de l'échéance (1…N)
 * @property int         $capital_amount     centimes - part capital
 * @property int         $interest_amount    centimes - part intérêts
 * @property int         $insurance_amount   centimes - assurance emprunteur
 * @property int         $remaining_capital  centimes - capital restant dû après paiement
 */
class LoanPayment extends Model
{
    protected $fillable = [
        'loan_id',
        'payment_date',
        'month_number',
        'capital_amount',
        'interest_amount',
        'insurance_amount',
        'remaining_capital',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
        ];
    }

    // -------------------------------------------------------------------------
    // Accesseurs euros
    // -------------------------------------------------------------------------

    public function getCapitalAmountEurosAttribute(): string
    {
        return bcdiv((string) $this->capital_amount, '100', 2);
    }

    public function getInterestAmountEurosAttribute(): string
    {
        return bcdiv((string) $this->interest_amount, '100', 2);
    }

    public function getInsuranceAmountEurosAttribute(): string
    {
        return bcdiv((string) $this->insurance_amount, '100', 2);
    }

    public function getRemainingCapitalEurosAttribute(): string
    {
        return bcdiv((string) $this->remaining_capital, '100', 2);
    }

    /**
     * Montant total de l'échéance (capital + intérêts + assurance).
     */
    public function getTotalAmountAttribute(): int
    {
        return (int) bcadd(
            bcadd((string) $this->capital_amount, (string) $this->interest_amount, 0),
            (string) $this->insurance_amount,
            0
        );
    }

    public function getTotalAmountEurosAttribute(): string
    {
        return bcdiv((string) $this->total_amount, '100', 2);
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
