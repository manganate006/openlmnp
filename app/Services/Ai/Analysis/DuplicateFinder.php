<?php

namespace App\Services\Ai\Analysis;

use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Income;
use App\Models\PropertyWork;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Détection d'une écriture déjà saisie (CDC § 3.1) : même bien, même montant,
 * à trois jours près.
 *
 * La double saisie est l'erreur la plus coûteuse d'une comptabilité tenue à la
 * main — une charge comptée deux fois fausse le résultat fiscal. On ne bloque
 * jamais : on affiche l'écriture trouvée et l'utilisateur tranche.
 *
 * Toutes les requêtes passent par les modèles, donc par leurs global scopes :
 * un doublon ne peut jamais être cherché — ni révélé — hors du compte courant.
 */
final class DuplicateFinder
{
    /** Fenêtre de rapprochement, en jours (CDC § 3.1). */
    public const WINDOW_DAYS = 3;

    /** Entité visée -> [classe, colonne de date]. */
    private const TARGETS = [
        'expense' => [Expense::class, 'expense_date'],
        'furniture' => [Furniture::class, 'purchase_date'],
        'work' => [PropertyWork::class, 'work_date'],
        'income' => [Income::class, 'income_date'],
    ];

    /**
     * @return array<string, mixed>|null Résumé de l'écriture existante, ou null.
     */
    public function find(string $entity, ?int $propertyId, ?int $amountCents, ?string $date): ?array
    {
        $match = $this->query($entity, $propertyId, $amountCents, $date)?->first();

        if ($match === null) {
            return null;
        }

        return [
            'id' => $match->id,
            'label' => $this->labelOf($match),
            'date' => $this->dateOf($match, $entity)?->format('d/m/Y'),
            'amount' => (int) $match->amount,
        ];
    }

    /** Y a-t-il un doublon ? Variante sans lecture, pour le traitement en lot d'un relevé. */
    public function exists(string $entity, ?int $propertyId, ?int $amountCents, ?string $date): bool
    {
        return (bool) $this->query($entity, $propertyId, $amountCents, $date)?->exists();
    }

    private function query(string $entity, ?int $propertyId, ?int $amountCents, ?string $date): ?\Illuminate\Database\Eloquent\Builder
    {
        if (! isset(self::TARGETS[$entity]) || $propertyId === null || $amountCents === null || $date === null) {
            return null;
        }

        [$class, $dateColumn] = self::TARGETS[$entity];
        $pivot = CarbonImmutable::parse($date);

        return $class::query()
            ->where('property_id', $propertyId)
            ->where('amount', $amountCents)
            ->whereBetween($dateColumn, [
                $pivot->subDays(self::WINDOW_DAYS)->toDateString(),
                $pivot->addDays(self::WINDOW_DAYS)->toDateString(),
            ]);
    }

    private function labelOf(Model $match): string
    {
        return (string) ($match->description ?? $match->guest_name ?? $match->reservation_ref ?? 'Écriture existante');
    }

    private function dateOf(Model $match, string $entity): ?CarbonImmutable
    {
        $column = self::TARGETS[$entity][1];
        $value = $match->{$column};

        return $value ? CarbonImmutable::parse($value) : null;
    }
}
