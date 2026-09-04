<?php

namespace App\Services\Csv;

use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Income;
use App\Models\Property;
use App\Models\PropertyWork;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Import CSV générique : recettes, charges, mobilier, travaux.
 *
 * Le parcours est en trois temps, et c'est délibéré :
 *   1. `preview()` lit le fichier, PROPOSE un mappage de colonnes et rend dix lignes
 *      déjà converties, doublons repérés ;
 *   2. l'utilisateur corrige le mappage — jamais une devinette appliquée en silence ;
 *   3. `import()` écrit, en sautant les doublons et les lignes illisibles, et rend le
 *      compte de chaque catégorie.
 *
 * ⚠️ `AirbnbImportService` n'est PAS remplacé : il reste le chemin recommandé pour un
 * export Airbnb (il reconstitue le brut depuis le net et connaît les deux formats
 * d'export). Les deux partagent désormais `CsvValues` pour la lecture des montants et
 * des dates — les dupliquer ferait diverger deux lectures de « 1 234,56 € ».
 */
class CsvImportService
{
    /** Nombre de lignes rendues par l'aperçu. */
    public const PREVIEW_ROWS = 10;

    public function __construct(private CsvReader $reader = new CsvReader) {}

    /**
     * Lit le fichier et rend de quoi construire l'écran de mappage.
     *
     * @param  array<string, int>|null  $mapping  mappage imposé, sinon deviné
     * @return array{
     *     header: list<string>, mapping: array<string, int>, delimiter: string,
     *     rows: list<array<string, mixed>>, total: int, duplicates: int,
     *     errors: list<string>, missing: list<string>
     * }
     */
    public function preview(UploadedFile|string $file, Property $property, string $target, ?array $mapping = null): array
    {
        $parsed = $this->reader->read($file);
        $mapping = $mapping ?? CsvProfile::guessMapping($target, $parsed['header']);

        $rows = [];
        $errors = [];
        $duplicates = 0;

        foreach ($parsed['rows'] as $index => $row) {
            try {
                $data = $this->extract($row, $mapping, $target);
            } catch (\RuntimeException $e) {
                $errors[] = 'Ligne ' . ($index + 2) . ' : ' . $e->getMessage();
                continue;
            }

            if ($data === null) {
                continue;
            }

            $data['duplicate'] = $this->isDuplicate($data, $property, $target);
            $duplicates += $data['duplicate'] ? 1 : 0;

            if (count($rows) < self::PREVIEW_ROWS) {
                $rows[] = $data;
            }
        }

        return [
            'header'     => $parsed['header'],
            'mapping'    => $mapping,
            'delimiter'  => $parsed['delimiter'],
            'rows'       => $rows,
            'total'      => count($parsed['rows']),
            'duplicates' => $duplicates,
            'errors'     => array_slice($errors, 0, 20),
            'missing'    => $this->missingRequired($target, $mapping),
        ];
    }

    /**
     * Écrit les lignes du fichier.
     *
     * @param  array<string, int>|null  $mapping
     * @return array{imported: int, duplicates: int, skipped: int, errors: list<string>}
     *
     * @throws \RuntimeException si un champ obligatoire n'est mappé à aucune colonne
     */
    public function import(UploadedFile|string $file, Property $property, string $target, ?array $mapping = null): array
    {
        $parsed = $this->reader->read($file);
        $mapping = $mapping ?? CsvProfile::guessMapping($target, $parsed['header']);

        if ($missing = $this->missingRequired($target, $mapping)) {
            throw new \RuntimeException(
                'Colonnes obligatoires non renseignées : ' . implode(', ', $missing) . '.'
            );
        }

        $imported = 0;
        $duplicates = 0;
        $skipped = 0;
        $errors = [];

        // Une transaction : un fichier de cabinet à moitié importé est pire qu'un import
        // refusé — l'utilisateur ne sait pas où reprendre, et rejouer crée des doublons.
        DB::transaction(function () use (
            $parsed, $mapping, $target, $property,
            &$imported, &$duplicates, &$skipped, &$errors,
        ) {
            foreach ($parsed['rows'] as $index => $row) {
                try {
                    $data = $this->extract($row, $mapping, $target);
                } catch (\RuntimeException $e) {
                    $errors[] = 'Ligne ' . ($index + 2) . ' : ' . $e->getMessage();
                    $skipped++;
                    continue;
                }

                if ($data === null) {
                    $skipped++;
                    continue;
                }

                if ($this->isDuplicate($data, $property, $target)) {
                    $duplicates++;
                    continue;
                }

                $this->create($data, $property, $target);
                $imported++;
            }
        });

        return [
            'imported'   => $imported,
            'duplicates' => $duplicates,
            'skipped'    => $skipped,
            'errors'     => array_slice($errors, 0, 20),
        ];
    }

    /** @return list<string> libellés français des champs obligatoires non mappés */
    private function missingRequired(string $target, array $mapping): array
    {
        $missing = [];

        foreach (CsvProfile::fields($target) as $field => $spec) {
            if ($spec['required'] && ! isset($mapping[$field])) {
                $missing[] = $spec['label'];
            }
        }

        return $missing;
    }

    /**
     * Convertit une ligne brute en valeurs typées.
     *
     * Rend `null` pour une ligne sans aucun contenu exploitable (dernière ligne d'un
     * tableur, ligne de total vide) : ce n'est pas une erreur, et la signaler comme
     * telle noierait les vraies.
     *
     * @return array<string, mixed>|null
     *
     * @throws \RuntimeException valeur illisible dans un champ obligatoire
     */
    private function extract(array $row, array $mapping, string $target): ?array
    {
        $fields = CsvProfile::fields($target);
        $data = [];

        foreach ($fields as $field => $spec) {
            $raw = isset($mapping[$field]) ? trim((string) ($row[$mapping[$field]] ?? '')) : '';

            if ($raw === '') {
                $data[$field] = null;
                continue;
            }

            $data[$field] = match ($spec['type']) {
                'money'   => CsvValues::money($raw),
                'date'    => CsvValues::date($raw),
                'integer' => CsvValues::integer($raw),
                'boolean' => CsvValues::boolean($raw),
                default   => $raw,
            };
        }

        foreach ($fields as $field => $spec) {
            if ($spec['required'] && ($data[$field] === null || $data[$field] === '')) {
                // Ligne entièrement vide : on l'ignore. Ligne partiellement remplie :
                // c'est une vraie erreur, et la taire perdrait une écriture.
                return array_filter($data, fn ($v) => $v !== null && $v !== '') === []
                    ? null
                    : throw new \RuntimeException("« {$spec['label']} » est vide.");
            }
        }

        // Un montant négatif est un remboursement ou un avoir : on le prend en valeur
        // absolue plutôt que de le perdre, et le sens vient de la cible choisie.
        if (isset($data['amount'])) {
            $data['amount'] = abs((int) $data['amount']);

            if ($data['amount'] === 0) {
                return null;
            }
        }

        return $data;
    }

    /**
     * Un enregistrement identique existe-t-il déjà pour ce bien ?
     *
     * Pour une recette, la référence de réservation fait foi quand elle existe : c'est
     * le seul identifiant stable d'un séjour. À défaut, et pour les autres cibles, le
     * triplet date + montant + libellé sert de signature.
     */
    private function isDuplicate(array $data, Property $property, string $target): bool
    {
        return match ($target) {
            CsvProfile::TARGET_INCOME => $this->incomeExists($data, $property),
            CsvProfile::TARGET_EXPENSE => Expense::withoutGlobalScopes()
                ->where('property_id', $property->id)
                ->whereDate('expense_date', $data['date'])
                ->where('amount', $data['amount'])
                ->where('category', CsvProfile::expenseCategory($data['category'] ?? null))
                ->exists(),
            CsvProfile::TARGET_FURNITURE => Furniture::withoutGlobalScopes()
                ->where('property_id', $property->id)
                ->whereDate('purchase_date', $data['date'])
                ->where('amount', $data['amount'])
                ->where('description', $data['description'])
                ->exists(),
            CsvProfile::TARGET_WORK => PropertyWork::withoutGlobalScopes()
                ->where('property_id', $property->id)
                ->whereDate('work_date', $data['date'])
                ->where('amount', $data['amount'])
                ->where('description', $data['description'])
                ->exists(),
            default => false,
        };
    }

    private function incomeExists(array $data, Property $property): bool
    {
        $query = Income::withoutGlobalScopes()->where('property_id', $property->id);

        if (! empty($data['reservation_ref'])) {
            return (clone $query)->where('reservation_ref', $data['reservation_ref'])->exists();
        }

        return $query->whereDate('income_date', $data['date'])
            ->where('amount', $data['amount'])
            ->exists();
    }

    /** Écrit une ligne. Les hooks des modèles s'occupent de la TVA et des dotations. */
    private function create(array $data, Property $property, string $target): void
    {
        match ($target) {
            CsvProfile::TARGET_INCOME => Income::create([
                'property_id'     => $property->id,
                'income_date'     => $data['date'],
                'amount'          => $data['amount'],
                'tva_rate'        => $property->isTvaLiable() ? 1000 : 0,
                'platform_fee'    => (int) ($data['platform_fee'] ?? 0),
                'tourist_tax'     => (int) ($data['tourist_tax'] ?? 0),
                'source'          => Income::SOURCE_OTHER,
                'reservation_ref' => $data['reservation_ref'] ?? null,
                'guest_name'      => $data['guest_name'] ?? null,
                'checkin_date'    => $data['checkin_date'] ?? null,
                'checkout_date'   => $data['checkout_date'] ?? null,
                'notes'           => $data['notes'] ?? null,
            ]),
            CsvProfile::TARGET_EXPENSE => Expense::create([
                'property_id'  => $property->id,
                'expense_date' => $data['date'],
                'amount'       => $data['amount'],
                'tva_rate'     => (int) ($data['tva_rate'] ?? 0),
                'category'     => CsvProfile::expenseCategory($data['category'] ?? null),
                'description'  => $data['description'] ?: 'Import CSV',
                'is_dedicated' => true,
                'notes'        => $data['notes'] ?? null,
            ]),
            CsvProfile::TARGET_FURNITURE => Furniture::create([
                'property_id'    => $property->id,
                'description'    => $data['description'],
                'amount'         => $data['amount'],
                'tva_rate'       => (int) ($data['tva_rate'] ?? 0),
                'purchase_date'  => $data['date'],
                'duration_years' => $this->duration($data, CsvProfile::TARGET_FURNITURE),
                'is_dedicated'   => true,
                'is_second_hand' => (bool) ($data['is_second_hand'] ?? false),
            ]),
            CsvProfile::TARGET_WORK => PropertyWork::create([
                'property_id'    => $property->id,
                'description'    => $data['description'],
                'amount'         => $data['amount'],
                'tva_rate'       => (int) ($data['tva_rate'] ?? 0),
                'work_date'      => $data['date'],
                'duration_years' => $this->duration($data, CsvProfile::TARGET_WORK),
                'is_dedicated'   => true,
            ]),
            default => throw new \InvalidArgumentException("Cible d'import inconnue : {$target}"),
        };
    }

    /** Durée saisie si elle est plausible, sinon celle du profil. */
    private function duration(array $data, string $target): int
    {
        $value = (int) ($data['duration_years'] ?? 0);

        return $value > 0 && $value <= 100 ? $value : CsvProfile::defaultDuration($target);
    }
}
