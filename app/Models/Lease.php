<?php

namespace App\Models;

use App\Models\Concerns\HasDocuments;
use App\Models\Scopes\BelongsToUserThroughPropertyScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bail d'une location meublée à l'année (issue #24).
 *
 * Saisi une fois sur le bien, il sert de modèle aux loyers mensuels. Un loyer généré
 * porte `amount` = loyer + charges, au forfait comme en provisions : en BIC, ce que
 * verse le locataire est une recette charges comprises, et les charges réellement
 * payées sont des dépenses. La part des charges est gardée à part sur la recette, pour
 * l'affichage seulement.
 *
 * @property int                 $id
 * @property int                 $property_id
 * @property string              $tenant_name
 * @property \Carbon\Carbon      $start_date
 * @property \Carbon\Carbon|null $end_date        vide tant que le bail court
 * @property int                 $rent_amount     centimes, loyer hors charges
 * @property int                 $charges_amount  centimes
 * @property string              $charges_mode    flat|provisions
 * @property int                 $due_day         jour d'échéance, de 1 à 31
 * @property string|null         $notes
 */
#[ScopedBy([BelongsToUserThroughPropertyScope::class])]
class Lease extends Model
{
    use HasDocuments;

    public const CHARGES_FLAT = 'flat';
    public const CHARGES_PROVISIONS = 'provisions';

    protected $fillable = [
        'property_id',
        'tenant_name',
        'start_date',
        'end_date',
        'rent_amount',
        'charges_amount',
        'charges_mode',
        'due_day',
        'notes',
    ];

    protected static function booted(): void
    {
        // Dernier rempart, quel que soit le chemin d'écriture (écran, MCP, import) : un
        // bail incohérent doublerait les loyers sans que rien ne le signale.
        static::saving(fn (Lease $lease) => $lease->assertValid());

        // incomes.lease_id n'a pas de clé étrangère (voir la migration) : c'est ici que les
        // loyers d'un bail supprimé perdent leur lien. Ce sont des recettes réelles, elles
        // restent. Sans les scopes globaux, pour ne pas dépendre de l'utilisateur connecté.
        static::deleting(fn (Lease $lease) => Income::withoutGlobalScopes()
            ->where('lease_id', $lease->getKey())
            ->update(['lease_id' => null]));
    }

    protected function casts(): array
    {
        return [
            'start_date'     => 'date',
            'end_date'       => 'date',
            'rent_amount'    => 'integer',
            'charges_amount' => 'integer',
            'due_day'        => 'integer',
        ];
    }

    public static function chargesModeLabels(): array
    {
        return [
            self::CHARGES_PROVISIONS => 'Provisions sur charges',
            self::CHARGES_FLAT       => 'Forfait de charges',
        ];
    }

    /** Ce que verse le locataire à chaque échéance, en centimes : loyer + charges. */
    public function getMonthlyTotalAttribute(): int
    {
        return (int) bcadd((string) $this->rent_amount, (string) $this->charges_amount, 0);
    }

    public function isActiveOn(CarbonInterface $day): bool
    {
        $day = CarbonImmutable::parse($day)->startOfDay();

        return $this->startDay()->lessThanOrEqualTo($day)
            && ($this->endDay() === null || $day->lessThanOrEqualTo($this->endDay()));
    }

    /**
     * Les autres baux du même bien dont la période croise celle-ci, bornes comprises.
     *
     * Le chevauchement le plus probable n'est pas une colocation : c'est un nouveau bail
     * saisi sans avoir renseigné la fin du précédent. Il doublerait les loyers.
     *
     * ⚠️ Comparaison en PHP et non en SQL : le cast `date` persiste `Y-m-d H:i:s`, et
     * comparer cette chaîne à une date nue ne se comporte pas pareil d'un moteur à
     * l'autre. Un bien n'a que quelques baux, les ramener coûte peu.
     *
     * `withoutGlobalScopes()` + filtre explicite sur le bien : la règle doit valoir aussi
     * en console et à l'import, sans session.
     *
     * @return Collection<int, Lease>
     */
    public function overlappingLeases(): Collection
    {
        $start = $this->startDay();
        $end = $this->endDay();

        return static::withoutGlobalScopes()
            ->where('property_id', $this->property_id)
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
            ->orderBy('start_date')
            ->get()
            ->filter(fn (Lease $other) => ($end === null || $other->startDay()->lessThanOrEqualTo($end))
                && ($other->endDay() === null || $start->lessThanOrEqualTo($other->endDay())))
            ->values();
    }

    /**
     * @throws \RuntimeException
     */
    public function assertValid(): void
    {
        if (! array_key_exists((string) $this->charges_mode, self::chargesModeLabels())) {
            throw new \RuntimeException('Mode de charges inconnu : « ' . $this->charges_mode . ' ».');
        }

        if ((int) $this->due_day < 1 || (int) $this->due_day > 31) {
            throw new \RuntimeException('Le jour d\'échéance doit être compris entre 1 et 31.');
        }

        if ((int) $this->rent_amount <= 0 || (int) $this->charges_amount < 0) {
            throw new \RuntimeException('Le loyer doit être positif, et les charges ne peuvent pas être négatives.');
        }

        if ($this->endDay() !== null && $this->endDay()->lessThan($this->startDay())) {
            throw new \RuntimeException('La fin du bail ne peut pas précéder son début.');
        }

        $other = $this->overlappingLeases()->first();

        if ($other !== null) {
            throw new \RuntimeException(static::overlapMessage($other));
        }
    }

    /**
     * Le refus de chevauchement, partagé avec la règle du formulaire : l'écran doit dire
     * la même chose que la garde du modèle, qui reste le dernier rempart.
     */
    public static function overlapMessage(Lease $other): string
    {
        return sprintf(
            'Ce bail chevauche celui de %s, commencé le %s%s. Renseignez la fin du bail précédent avant d\'en saisir un nouveau.',
            $other->tenant_name,
            $other->startDay()->format('d/m/Y'),
            $other->endDay() === null ? ' et toujours en cours' : ' et terminé le ' . $other->endDay()->format('d/m/Y'),
        );
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function incomes(): HasMany
    {
        return $this->hasMany(Income::class)->orderBy('income_date');
    }

    private function startDay(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->start_date)->startOfDay();
    }

    private function endDay(): ?CarbonImmutable
    {
        return $this->end_date === null ? null : CarbonImmutable::parse($this->end_date)->startOfDay();
    }
}
