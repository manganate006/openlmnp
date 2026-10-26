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
 *   1. `preview()` lit le fichier et rend DEUX choses : les dix premières lignes BRUTES
 *      avec l'en-tête réel — ce que l'écran montre avant tout le reste — et dix lignes
 *      déjà converties par le mappage PROPOSÉ, doublons repérés ;
 *   2. l'utilisateur corrige le mappage — jamais une devinette appliquée en silence ;
 *   3. `import()` écrit, en sautant les doublons et les lignes illisibles, et rend le
 *      compte de chaque catégorie.
 *
 * ⚠️ Les lignes brutes ne sont pas un luxe. Sans elles, l'écran demandait de vérifier
 * une correspondance de colonnes sans jamais montrer les colonnes : l'en-tête du fichier
 * ne servait qu'à deviner le mappage, et disparaissait ensuite.
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

    /** Lignes brutes du fichier rendues telles quelles, avant tout mappage. */
    public const RAW_PREVIEW_ROWS = 10;

    /** Lignes parcourues au plus pour trouver un exemple de valeur par colonne. */
    private const SAMPLE_SCAN_ROWS = 50;

    /**
     * Lit le fichier et rend de quoi construire l'écran de mappage.
     *
     * Les quatre compteurs se partagent EXACTEMENT le total, et c'est la propriété qui
     * les rend lisibles : `importable + duplicates + errors_total + empty === total`.
     *
     * ⚠️ Ce n'était pas le cas. L'écran affichait « à importer » comme `total - doublons`,
     * donc en y comptant les lignes en erreur et les lignes vides ; et « lignes en erreur »
     * comme la taille de `errors`, tronquée à vingt — un PLAFOND présenté comme un
     * décompte. Sur un fichier de 121 lignes cela donnait « 60 à importer / 61 doublons /
     * 20 en erreur », soit 141 lignes pour un fichier qui n'en a que 121. Les trois
     * pastilles mentaient, chacune à sa façon.
     *
     * @param  array<string, int>|null  $mapping  mappage imposé, sinon deviné
     * @return array{
     *     header: list<string>, mapping: array<string, int>, delimiter: string,
     *     rows: list<array<string, mixed>>, raw_rows: list<list<string>>,
     *     samples: array<int, string>, total: int, importable: int, duplicates: int,
     *     repeats: int, empty: int, errors: list<string>, errors_total: int,
     *     missing: list<string>, suspected_target: string|null
     * }
     */
    public function preview(UploadedFile|string $file, Property $property, string $target, ?array $mapping = null): array
    {
        $parsed = $this->reader->read($file);
        $mapping = $mapping ?? CsvProfile::guessMapping($target, $parsed['header']);
        $preferMonthFirst = $this->detectPreferMonthFirst($parsed['rows'], $mapping, $target);

        $rows = [];
        $errors = [];
        $duplicates = 0;
        $repeats = 0;
        $importable = 0;
        $empty = 0;
        // Signatures des lignes « nouvelles » déjà vues dans CE fichier. L'import, lui,
        // écarte une répétition parce qu'il trouve en base la ligne qu'il vient d'écrire ;
        // l'aperçu n'écrit rien et ne regardait que la base : il annonçait une ligne de
        // trop par répétition interne.
        $seen = [];

        foreach ($parsed['rows'] as $index => $row) {
            try {
                $data = $this->extract($row, $mapping, $target, $preferMonthFirst);
            } catch (\RuntimeException $e) {
                $errors[] = 'Ligne ' . ($index + 2) . ' : ' . $e->getMessage();
                continue;
            }

            if ($data === null) {
                // Ligne sans aucun contenu exploitable : ni une erreur, ni une écriture.
                // Elle a longtemps disparu des comptes, ce qui gonflait « à importer ».
                $empty++;
                continue;
            }

            $data['duplicate'] = $this->isDuplicate($data, $property, $target);
            $data['repeat'] = false;

            if (! $data['duplicate']) {
                $signature = $this->signature($data, $target);
                $data['repeat'] = $data['duplicate'] = isset($seen[$signature]);
                $seen[$signature] = true;
                $repeats += $data['repeat'] ? 1 : 0;
            }

            $duplicates += $data['duplicate'] ? 1 : 0;
            $importable += $data['duplicate'] ? 0 : 1;

            if (count($rows) < self::PREVIEW_ROWS) {
                $rows[] = $data;
            }
        }

        return [
            'header'     => $parsed['header'],
            'mapping'    => $mapping,
            'delimiter'  => $parsed['delimiter'],
            'rows'       => $rows,
            // Le fichier TEL QU'IL EST, avant tout mappage. L'en-tête réel ne servait
            // qu'à deviner la correspondance et n'était jamais montré : l'utilisateur
            // devait vérifier un mappage sans voir ce qu'il mappait.
            'raw_rows'   => array_slice($parsed['rows'], 0, self::RAW_PREVIEW_ROWS),
            'samples'    => self::columnSamples($parsed['rows']),
            'total'      => count($parsed['rows']),
            'importable' => $importable,
            'duplicates' => $duplicates,
            // Sous-ensemble de `duplicates` : lignes répétées dans le fichier lui-même, et
            // non déjà présentes en base. Affiché à part, parce que deux versements
            // identiques le même jour existent — l'utilisateur doit pouvoir le vérifier.
            'repeats'    => $repeats,
            'empty'      => $empty,
            'errors'     => array_slice($errors, 0, 20),
            // Le décompte RÉEL, à côté de la liste tronquée : afficher `count($errors)`
            // revenait à afficher « 20 » dès qu'il y en avait davantage.
            'errors_total' => count($errors),
            'missing'    => $this->missingRequired($target, $mapping),
            // Contrôle de cohérence : les intitulés du fichier contredisent-ils la nature
            // choisie ? On POSE LA QUESTION, on ne tranche pas — importer des recettes en
            // « Charges » inverse le signe du résultat fiscal, et personne ne relit.
            'suspected_target' => CsvProfile::suspectedTarget($target, $parsed['header']),
        ];
    }

    /**
     * Écrit les lignes du fichier.
     *
     * @param  array<string, int>|null  $mapping
     * @return array{imported: int, duplicates: int, skipped: int, empty: int,
     *     errors: list<string>, errors_total: int}
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

        $preferMonthFirst = $this->detectPreferMonthFirst($parsed['rows'], $mapping, $target);
        $imported = 0;
        $duplicates = 0;
        $skipped = 0;
        $empty = 0;
        $errors = [];

        // Une transaction : un fichier de cabinet à moitié importé est pire qu'un import
        // refusé — l'utilisateur ne sait pas où reprendre, et rejouer crée des doublons.
        DB::transaction(function () use (
            $parsed, $mapping, $target, $property, $preferMonthFirst,
            &$imported, &$duplicates, &$skipped, &$empty, &$errors,
        ) {
            foreach ($parsed['rows'] as $index => $row) {
                try {
                    $data = $this->extract($row, $mapping, $target, $preferMonthFirst);
                } catch (\RuntimeException $e) {
                    $errors[] = 'Ligne ' . ($index + 2) . ' : ' . $e->getMessage();
                    $skipped++;
                    continue;
                }

                if ($data === null) {
                    $skipped++;
                    $empty++;
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
            // `skipped` compte les lignes illisibles ET les lignes vides : il reste tel
            // quel pour ne pas changer ce que les appelants en attendent, mais l'écran
            // affiche désormais les deux séparément — une ligne vide n'est pas une erreur.
            'skipped'    => $skipped,
            'empty'      => $empty,
            'errors'     => array_slice($errors, 0, 20),
            'errors_total' => count($errors),
        ];
    }

    /**
     * Un fichier doit-il être lu en `m/d/Y` plutôt qu'en `d/m/Y` ?
     *
     * Décision au niveau du FICHIER ENTIER, prise une fois avant la boucle de
     * lignes, sur TOUTES les colonnes mappées à un champ de type `date` (date de
     * l'opération, début/fin de séjour...) : voir `CsvValues::detectPreferMonthFirst()`.
     *
     * @param  list<array<int, string>>  $rows
     * @param  array<string, int>  $mapping
     */
    private function detectPreferMonthFirst(array $rows, array $mapping, string $target): bool
    {
        $dateFields = array_keys(array_filter(
            CsvProfile::fields($target),
            fn (array $spec) => $spec['type'] === 'date',
        ));
        $dateColumns = array_intersect_key($mapping, array_flip($dateFields));

        if ($dateColumns === []) {
            return false;
        }

        $rawDates = (function () use ($rows, $dateColumns): \Generator {
            foreach ($rows as $row) {
                foreach ($dateColumns as $column) {
                    $raw = trim((string) ($row[$column] ?? ''));
                    if ($raw !== '') {
                        yield $raw;
                    }
                }
            }
        })();

        return CsvValues::detectPreferMonthFirst($rawDates);
    }

    /**
     * Première valeur non vide rencontrée dans chaque colonne du fichier.
     *
     * C'est ce qui lève l'ambiguïté de l'écran de correspondance d'un coup d'œil : en
     * face de « Date de la charge », lire `15/03/2024` dit immédiatement si la colonne
     * choisie est la bonne. Sans exemple, l'écran demande de vérifier un mappage sans
     * rien donner à vérifier.
     *
     * @param  list<list<string>>  $rows
     * @return array<int, string> index de colonne => exemple
     */
    private static function columnSamples(array $rows): array
    {
        $samples = [];

        foreach (array_slice($rows, 0, self::SAMPLE_SCAN_ROWS) as $row) {
            foreach ($row as $index => $value) {
                $value = trim((string) $value);

                if ($value !== '' && ! isset($samples[$index])) {
                    $samples[$index] = $value;
                }
            }
        }

        ksort($samples);

        return $samples;
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
    private function extract(array $row, array $mapping, string $target, bool $preferMonthFirst = false): ?array
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
                'date'    => CsvValues::date($raw, $preferMonthFirst),
                'integer' => CsvValues::integer($raw),
                'boolean' => CsvValues::boolean($raw),
                default   => $raw,
            };
        }

        // Rien ne le faisait avant ce correctif : un séjour dont la fin précède le
        // début passait tel quel — le symptôme d'une date lue dans le mauvais sens
        // sur une seule des deux colonnes.
        if (! empty($data['checkin_date']) && ! empty($data['checkout_date']) && $data['checkout_date'] < $data['checkin_date']) {
            throw new \RuntimeException(
                "Date de fin de séjour ({$data['checkout_date']}) antérieure à la date de début ({$data['checkin_date']})."
            );
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

    /**
     * Clé de dédoublonnage d'une ligne, calquée champ pour champ sur `isDuplicate()` :
     * deux lignes de même signature sont celles que l'import tiendra pour identiques.
     */
    private function signature(array $data, string $target): string
    {
        $parts = match ($target) {
            CsvProfile::TARGET_INCOME => ! empty($data['reservation_ref'])
                ? ['ref', $data['reservation_ref']]
                : [$data['date'], $data['amount']],
            CsvProfile::TARGET_EXPENSE => [
                $data['date'], $data['amount'], CsvProfile::expenseCategory($data['category'] ?? null),
            ],
            CsvProfile::TARGET_FURNITURE, CsvProfile::TARGET_WORK => [
                $data['date'], $data['amount'], $data['description'],
            ],
            default => [uniqid('', true)],
        };

        return json_encode($parts);
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
