<?php

namespace App\Models;

use App\Models\Concerns\HasDocuments;
use App\Models\Scopes\BelongsToUserThroughPropertyScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
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
 * @property bool        $is_dedicated       finance exclusivement le logement loué (pas de quote-part)
 * @property int         $fees_amount        centimes - frais d'emprunt (dossier, courtage, caution non restituable)
 * @property \Carbon\Carbon|null $fees_paid_on date effective de paiement des frais (défaut : start_date)
 * @property string      $fees_spread_method méthode d'étalement PAR EMPRUNT : linear (défaut) ou interest
 * @property \Carbon\Carbon|null $early_repayment_date   date d'un remboursement anticipé, le cas échéant
 * @property int|null    $early_repayment_amount centimes - capital remboursé par anticipation à cette date
 */
#[ScopedBy([BelongsToUserThroughPropertyScope::class])]
class Loan extends Model
{
    use HasDocuments;

    public const INSURANCE_FIXED = 'fixed';
    public const INSURANCE_VARIABLE = 'variable';

    /** Tableau d'amortissement recalculable à volonté par LoanService. */
    public const SCHEDULE_COMPUTED = 'computed';

    /** Échéances réelles de la banque : à ne jamais écraser sans confirmation. */
    public const SCHEDULE_IMPORTED = 'imported';

    /** Étalement en fractions annuelles égales, sans prorata temporis (BOI-BIC-CHG-20-30-40 § 70). */
    public const FEES_METHOD_LINEAR = 'linear';

    /** Étalement proratisé sur les intérêts courus de chaque année (§ 100-110). */
    public const FEES_METHOD_INTEREST = 'interest';

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
        'schedule_source',
        'is_dedicated',
        'fees_amount',
        'fees_paid_on',
        'fees_spread_method',
        'early_repayment_date',
        'early_repayment_amount',
    ];

    public static function insuranceTypeLabels(): array
    {
        return [
            self::INSURANCE_FIXED => 'Montant fixe mensuel',
            self::INSURANCE_VARIABLE => 'Taux sur capital restant dû',
        ];
    }

    public static function feesSpreadMethodLabels(): array
    {
        return [
            self::FEES_METHOD_LINEAR => 'Fractions annuelles égales',
            self::FEES_METHOD_INTEREST => 'Au prorata des intérêts courus',
        ];
    }

    protected function casts(): array
    {
        return [
            'start_date'    => 'date',
            'annual_rate'   => 'decimal:3',
            'is_dedicated'  => 'boolean',
            'fees_paid_on'  => 'date',
            'early_repayment_date' => 'date',
        ];
    }

    /**
     * Date effective de paiement des frais d'emprunt : `fees_paid_on` si saisie,
     * sinon la date de mise en place de l'emprunt (BOI-BIC-CHG-20-30-40 § 30-50 : l'option
     * s'exerce par la première inscription en charges à répartir, il faut donc une date
     * certaine même quand l'utilisateur ne l'a pas renseignée).
     */
    public function feesEffectiveDate(): \Carbon\Carbon
    {
        return $this->fees_paid_on ?? $this->start_date;
    }

    public function getFeesAmountEurosAttribute(): string
    {
        return bcdiv((string) $this->fees_amount, '100', 2);
    }

    public function getEarlyRepaymentAmountEurosAttribute(): ?string
    {
        return $this->early_repayment_amount === null
            ? null
            : bcdiv((string) $this->early_repayment_amount, '100', 2);
    }

    /**
     * Applique la quote-part à un montant, sauf si l'emprunt est dédié (il finance
     * alors exclusivement le logement loué). Même logique que `is_dedicated` sur
     * Expense/Furniture/PropertyWork — centralisée ici pour n'être écrite qu'une
     * fois, et ne jamais être appliquée deux fois par ses appelants.
     */
    public function prorate(string $amountCents, string $quotaShare): string
    {
        return $this->is_dedicated ? $amountCents : bcmul($amountCents, $quotaShare, 0);
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

    /**
     * Le tableau vient-il de la banque plutôt que de notre calcul ?
     *
     * Les échéances importées portent le réel (différé, assurance dégressive,
     * modulation) : les recalculer ferait varier les intérêts déductibles déjà
     * déclarés, d'où la garde dans LoanService::generateSchedule().
     */
    public function hasImportedSchedule(): bool
    {
        return $this->schedule_source === self::SCHEDULE_IMPORTED;
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
        // reorder() : la relation trie déjà par month_number ASC, et ce tri passerait avant
        // les orderByDesc() ci-dessous — first() renverrait alors toujours l'échéance n° 1.
        $lastPayment = $this->payments()
            ->reorder()
            ->whereYear('payment_date', '<=', $year)
            ->orderByDesc('payment_date')
            ->orderByDesc('month_number')
            ->first();

        return $lastPayment?->remaining_capital ?? $this->amount;
    }

    /**
     * Capital restant dû juste AVANT une date donnée — utilisé pour le remboursement
     * anticipé des frais d'emprunt (BOI-BIC-CHG-20-30-40 § 120-130) : la dernière échéance
     * strictement antérieure à la date, ou le capital initial si elle précède la toute
     * première échéance (ou si l'emprunt ne porte encore aucun tableau).
     */
    public function capitalOutstandingBefore(\Carbon\Carbon $date): int
    {
        $lastPayment = $this->payments()
            ->reorder()
            ->where('payment_date', '<', $date->toDateString())
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
