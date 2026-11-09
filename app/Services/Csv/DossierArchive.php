<?php

namespace App\Services\Csv;

use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Income;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Services\LoanService;
use Illuminate\Database\Eloquent\Model;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\PropertyWork;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sérialisation et relecture d'un dossier complet (un utilisateur, tous ses biens).
 *
 * Sert deux besoins qui n'en font qu'un : migrer d'une instance auto-hébergée vers le
 * cloud (ou l'inverse), et pouvoir partir. Sur un logiciel AGPL, la réversibilité n'est
 * pas un argument commercial, c'est une promesse — encore faut-il qu'elle soit outillée.
 *
 * ⚠️ **`SCHEMA_VERSION` est un contrat.** Un fichier produit par une version future n'est
 * pas relu « au mieux » : il est refusé. Importer à moitié une comptabilité, c'est la
 * corrompre sans que rien ne le signale, et l'utilisateur ne s'en apercevra qu'à la
 * déclaration.
 *
 * ⚠️ Ce que l'archive NE contient PAS, et pourquoi :
 *   - les **exercices fiscaux** : leurs totaux sont figés et dépendent de la chaîne des
 *     reports. Les rejouer sur une autre instance produirait des exercices clôturés que
 *     personne n'a déclarés. Ils se recalculent après import.
 *   - les **écritures comptables** : dérivées, régénérées à la demande.
 *   - le **tableau d'amortissement d'un emprunt CALCULÉ** : dérivé lui aussi, il est
 *     RÉGÉNÉRÉ à l'import. Jusqu'au format 2, il ne l'était pas : un dossier importé
 *     n'avait plus aucune échéance, donc plus d'intérêts (294) ni d'assurance (242), et la
 *     liasse surévaluait le résultat sans rien signaler (constaté en rejouant un dossier
 *     réel le 2026-10-29). Un tableau IMPORTÉ de la banque, lui, n'est pas recalculable :
 *     ses échéances voyagent dans l'archive.
 *   - le **mot de passe** et les jetons : une archive n'est pas une sauvegarde de compte.
 */
class DossierArchive
{
    /**
     * Version du format.
     *
     * 1 — format initial (2026-09-04) : biens, composants, travaux, mobilier, recettes,
     *     charges, emprunts. Toute évolution non rétrocompatible incrémente ce nombre.
     * 2 — 2026-10-29 : origine du tableau d'amortissement (`schedule_source`) et, pour un
     *     tableau importé de la banque, ses échéances (`payments`). Une version qui ne lit
     *     que le format 1 les ignorerait en silence : d'où le changement de numéro.
     * 3 — 2026-10-30 : première mise en location du bien (`activity_start_date`), distincte
     *     du début du réel. Une version qui l'ignorerait perdrait les amortissements réputés
     *     pratiqués des années au micro-BIC : le cumul (030) changerait sans rien signaler.
     *     Même format : l'année couverte par un cumul repris (`opening_accumulated_year`), qui
     *     manquait — sans elle, l'import recomptait les années déjà dans le cumul.
     */
    public const SCHEMA_VERSION = 3;

    /** Colonnes d'une échéance, pour un tableau importé (un tableau calculé se régénère). */
    private const PAYMENT_COLUMNS = [
        'payment_date', 'month_number', 'capital_amount', 'interest_amount',
        'insurance_amount', 'remaining_capital',
    ];

    /**
     * Colonnes exportées par table.
     *
     * Liste EXPLICITE, jamais `$model->toArray()` : une colonne technique ajoutée demain
     * entrerait sinon dans l'archive sans que personne l'ait décidé, et l'aller-retour
     * cesserait d'être identique au premier ajout de colonne.
     */
    private const COLUMNS = [
        'properties' => [
            'name', 'address', 'city', 'postal_code', 'type', 'total_area', 'rented_area',
            'acquisition_date', 'acquisition_price', 'notary_fees', 'agency_fees',
            'acquisition_fees_treatment', 'acquisition_fees_duration',
            'market_value', 'market_value_date', 'land_percentage', 'rental_start_date',
            'activity_start_date', 'airbnb_commission_rate', 'rental_type', 'tva_regime', 'is_primary_residence',
            'residence_situation', 'tourism_classification', 'notes',
        ],
        'components' => [
            'name', 'percentage', 'duration_years', 'base_amount', 'annual_depreciation',
            'base_source', 'depreciation_start_date', 'cerfa_category',
            'opening_accumulated_depreciation', 'opening_accumulated_year', 'sort_order',
        ],
        'works' => [
            'description', 'amount', 'tva_rate', 'work_date', 'duration_years',
            'is_dedicated', 'annual_depreciation', 'depreciation_source',
            'opening_accumulated_depreciation', 'opening_accumulated_year',
        ],
        'furniture' => [
            'description', 'amount', 'tva_rate', 'purchase_date', 'duration_years',
            'is_dedicated', 'is_second_hand', 'annual_depreciation', 'depreciation_source',
            'opening_accumulated_depreciation', 'opening_accumulated_year',
        ],
        'incomes' => [
            'income_date', 'amount', 'tva_rate', 'platform_fee', 'tourist_tax', 'source',
            'reservation_ref', 'guest_name', 'checkin_date', 'checkout_date', 'notes',
        ],
        'expenses' => [
            'expense_date', 'amount', 'tva_rate', 'category', 'description',
            'is_dedicated', 'business_use_bp', 'recurring_type', 'notes',
        ],
        'loans' => [
            'bank_name', 'amount', 'annual_rate', 'duration_months', 'start_date',
            'monthly_payment', 'insurance_monthly', 'insurance_type', 'insurance_rate',
            'is_dedicated', 'schedule_source',
        ],
    ];

    /**
     * Construit la représentation JSON-able du dossier d'un utilisateur.
     *
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        $properties = Property::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->with([
                'components' => fn ($q) => $q->withoutGlobalScopes()->reorder()->orderBy('sort_order')->orderBy('id'),
                'works' => fn ($q) => $q->withoutGlobalScopes()->reorder()->orderBy('id'),
                'furniture' => fn ($q) => $q->withoutGlobalScopes()->reorder()->orderBy('id'),
                'incomes' => fn ($q) => $q->withoutGlobalScopes()->reorder()->orderBy('id'),
                'expenses' => fn ($q) => $q->withoutGlobalScopes()->reorder()->orderBy('id'),
                'loans' => fn ($q) => $q->withoutGlobalScopes()->reorder()->orderBy('id'),
            ])
            ->get();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'exported_at'    => now()->toIso8601String(),
            'application'    => 'openlmnp',
            // L'e-mail sert au CONTRÔLE D'APPARTENANCE à l'import, jamais à créer un compte.
            'owner'          => ['email' => $user->email, 'name' => $user->name],
            'properties'     => $properties->map(fn (Property $p) => [
                ...$this->pick($p, self::COLUMNS['properties']),
                'components' => $p->components->map(fn ($c) => $this->pick($c, self::COLUMNS['components']))->values()->all(),
                'works'      => $p->works->map(fn ($w) => $this->pick($w, self::COLUMNS['works']))->values()->all(),
                'furniture'  => $p->furniture->map(fn ($f) => $this->pick($f, self::COLUMNS['furniture']))->values()->all(),
                'incomes'    => $p->incomes->map(fn ($i) => $this->pick($i, self::COLUMNS['incomes']))->values()->all(),
                'expenses'   => $p->expenses->map(fn ($e) => $this->pick($e, self::COLUMNS['expenses']))->values()->all(),
                'loans'      => $p->loans->map(fn (Loan $l) => $this->pick($l, self::COLUMNS['loans']) + ($l->hasImportedSchedule()
                    ? ['payments' => $l->payments()->withoutGlobalScopes()->reorder()->orderBy('month_number')->get()
                        ->map(fn ($payment) => $this->pick($payment, self::PAYMENT_COLUMNS))->values()->all()]
                    : []))->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Recrée le dossier décrit par l'archive sur le compte indiqué.
     *
     * @param  array<string, mixed>  $payload
     * @return array{properties: int, components: int, works: int, furniture: int, incomes: int, expenses: int, loans: int}
     *
     * @throws \RuntimeException archive illisible, version inconnue, ou appartenance en défaut
     */
    public function import(User $user, array $payload, bool $allowForeignOwner = false): array
    {
        $version = $payload['schema_version'] ?? null;

        if (! is_int($version)) {
            throw new \RuntimeException('Archive invalide : aucun numéro de version (`schema_version`).');
        }

        if ($version > self::SCHEMA_VERSION) {
            throw new \RuntimeException(sprintf(
                'Archive en version %d, cette instance ne sait lire que jusqu\'à la version %d. '
                . 'Mettez OpenLMNP à jour plutôt que d\'importer partiellement.',
                $version,
                self::SCHEMA_VERSION,
            ));
        }

        // Contrôle d'appartenance : une archive porte l'e-mail de son propriétaire. Importer
        // le dossier de quelqu'un d'autre est possible (migration entre instances), mais
        // jamais par mégarde — il faut le dire explicitement.
        $owner = $payload['owner']['email'] ?? null;

        if ($owner !== null && $owner !== $user->email && ! $allowForeignOwner) {
            throw new \RuntimeException(sprintf(
                'Cette archive appartient à %s, et vous importez dans le compte %s. '
                . 'Relancez avec --force si c\'est voulu.',
                $owner,
                $user->email,
            ));
        }

        $counts = array_fill_keys(
            ['properties', 'components', 'works', 'furniture', 'incomes', 'expenses', 'loans'],
            0,
        );

        // Tout ou rien : une comptabilité à moitié importée est pire qu'un import refusé.
        DB::transaction(function () use ($user, $payload, &$counts) {
            foreach ($payload['properties'] ?? [] as $row) {
                $property = Property::forceCreate(
                    ['user_id' => $user->id] + $this->only($row, self::COLUMNS['properties']),
                );
                $counts['properties']++;

                foreach ($row['components'] ?? [] as $child) {
                    $this->write(PropertyComponent::class, $property, $child, self::COLUMNS['components']);
                    $counts['components']++;
                }

                foreach ($row['works'] ?? [] as $child) {
                    $this->write(PropertyWork::class, $property, $child, self::COLUMNS['works']);
                    $counts['works']++;
                }

                foreach ($row['furniture'] ?? [] as $child) {
                    $this->write(Furniture::class, $property, $child, self::COLUMNS['furniture']);
                    $counts['furniture']++;
                }

                foreach ($row['incomes'] ?? [] as $child) {
                    $this->write(Income::class, $property, $child, self::COLUMNS['incomes']);
                    $counts['incomes']++;
                }

                foreach ($row['expenses'] ?? [] as $child) {
                    $this->write(Expense::class, $property, $child, self::COLUMNS['expenses']);
                    $counts['expenses']++;
                }

                foreach ($row['loans'] ?? [] as $child) {
                    $loan = $this->write(Loan::class, $property, $child, self::COLUMNS['loans']);
                    $this->restoreSchedule($loan, $child['payments'] ?? []);
                    $counts['loans']++;
                }
            }
        });

        return $counts;
    }

    /**
     * Écrit une ligne fille en `forceCreate`.
     *
     * ⚠️ `forceCreate` et non `create` : plusieurs de ces colonnes ne sont pas assignables
     * en masse (`annual_depreciation` l'est, mais pas toutes), et une colonne ignorée en
     * silence par le `$fillable` rendrait l'aller-retour non identique — sans lever la
     * moindre erreur.
     */
    private function write(string $model, Property $property, array $row, array $columns): Model
    {
        return $model::forceCreate(['property_id' => $property->id] + $this->only($row, $columns));
    }

    /**
     * Le tableau d'amortissement d'un emprunt importé : celui de la banque s'il voyage dans
     * l'archive, sinon recalculé — un emprunt sans échéance ne porte ni intérêts ni assurance.
     *
     * @param  list<array<string, mixed>>  $payments
     */
    private function restoreSchedule(Loan $loan, array $payments): void
    {
        if ($payments === []) {
            // Sans échéances dans l'archive (format 1, ou tableau calculé), on le calcule.
            $loan->forceFill(['schedule_source' => Loan::SCHEDULE_COMPUTED])->save();
            app(LoanService::class)->generateSchedule($loan, force: true);

            return;
        }

        foreach ($payments as $payment) {
            LoanPayment::forceCreate(['loan_id' => $loan->id] + $this->only($payment, self::PAYMENT_COLUMNS));
        }
    }

    /**
     * Valeurs exportables d'un modèle, dates normalisées en `Y-m-d`.
     *
     * @return array<string, mixed>
     */
    private function pick(object $model, array $columns): array
    {
        $data = [];

        foreach ($columns as $column) {
            $value = $model->$column;

            $data[$column] = $value instanceof \DateTimeInterface
                ? $value->format('Y-m-d')
                : $value;
        }

        return $data;
    }

    /**
     * Colonnes retenues d'une ligne d'archive, dans l'ordre du contrat.
     *
     * Une colonne absente du fichier vaut `null` plutôt que de faire échouer l'import :
     * c'est ce qui permet de relire une archive produite par une version ANTÉRIEURE, qui
     * ne connaissait pas encore la colonne.
     *
     * @return array<string, mixed>
     */
    private function only(array $row, array $columns): array
    {
        $data = [];

        foreach ($columns as $column) {
            if (array_key_exists($column, $row)) {
                $data[$column] = $row[$column];
            }
        }

        return $data;
    }
}
