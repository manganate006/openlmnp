<?php

namespace App\Services;

use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\PropertyWork;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Service de calcul des amortissements LMNP.
 *
 * Tous les montants sont en centimes (entiers).
 * Tous les calculs utilisent bcmath pour la précision.
 */
class DepreciationService
{
    /**
     * Composants par défaut avec pourcentage et durée.
     */
    public const DEFAULT_COMPONENTS = [
        ['name' => 'Gros œuvre',                'percentage' => 50, 'duration_years' => 50, 'sort_order' => 1],
        ['name' => 'Toiture',                   'percentage' => 10, 'duration_years' => 25, 'sort_order' => 2],
        ['name' => 'Installations électriques', 'percentage' => 10, 'duration_years' => 25, 'sort_order' => 3],
        ['name' => 'Étanchéité',                'percentage' =>  5, 'duration_years' => 15, 'sort_order' => 4],
        ['name' => 'Agencements intérieurs',    'percentage' => 15, 'duration_years' => 15, 'sort_order' => 5],
        ['name' => 'Plomberie / sanitaire',     'percentage' => 10, 'duration_years' => 15, 'sort_order' => 6],
    ];

    /**
     * Composants optionnels (maison individuelle).
     * Sources : BOFiP BOI-ANNX-000115, pratique experts-comptables LMNP.
     */
    public const OPTIONAL_COMPONENTS = [
        ['name' => 'Piscine',                      'percentage' =>  7, 'duration_years' => 15, 'sort_order' => 7],
        ['name' => 'Climatisation / chauffage',     'percentage' =>  5, 'duration_years' => 20, 'sort_order' => 8],
        ['name' => 'Cuisine équipée',               'percentage' =>  5, 'duration_years' => 10, 'sort_order' => 9],
        ['name' => 'VRD (voirie, réseaux)',          'percentage' =>  3, 'duration_years' => 15, 'sort_order' => 10],
        ['name' => 'Aménagements extérieurs',        'percentage' =>  5, 'duration_years' => 15, 'sort_order' => 11],
    ];

    /**
     * Catalogue complet : standards + optionnels.
     */
    public const FULL_CATALOG = [
        ['name' => 'Gros œuvre',                'percentage' => 50, 'duration_years' => 50, 'sort_order' => 1,  'optional' => false],
        ['name' => 'Toiture',                   'percentage' => 10, 'duration_years' => 25, 'sort_order' => 2,  'optional' => false],
        ['name' => 'Installations électriques', 'percentage' => 10, 'duration_years' => 25, 'sort_order' => 3,  'optional' => false],
        ['name' => 'Étanchéité',                'percentage' =>  5, 'duration_years' => 15, 'sort_order' => 4,  'optional' => false],
        ['name' => 'Agencements intérieurs',    'percentage' => 15, 'duration_years' => 15, 'sort_order' => 5,  'optional' => false],
        ['name' => 'Plomberie / sanitaire',     'percentage' => 10, 'duration_years' => 15, 'sort_order' => 6,  'optional' => false],
        ['name' => 'Piscine',                   'percentage' =>  7, 'duration_years' => 15, 'sort_order' => 7,  'optional' => true],
        ['name' => 'Climatisation / chauffage',  'percentage' =>  5, 'duration_years' => 20, 'sort_order' => 8, 'optional' => true],
        ['name' => 'Cuisine équipée',            'percentage' =>  5, 'duration_years' => 10, 'sort_order' => 9, 'optional' => true],
        ['name' => 'VRD (voirie, réseaux)',       'percentage' =>  3, 'duration_years' => 15, 'sort_order' => 10, 'optional' => true],
        ['name' => 'Aménagements extérieurs',     'percentage' =>  5, 'duration_years' => 15, 'sort_order' => 11, 'optional' => true],
    ];

    /** Nombre de décimales retenues pour un pourcentage de ventilation. */
    public const PERCENTAGE_SCALE = 4;

    /**
     * Au-delà de ce rapport, un écart entre la base stockée et la base théorique ne peut
     * pas résulter d'un ajustement humain : c'est une corruption (double conversion ×100).
     */
    public const CORRUPTION_FACTOR = 10;

    // -------------------------------------------------------------------------
    // Formules de base — écrites ICI et nulle part ailleurs
    //
    // Elles l'étaient à cinq endroits (ce service, DepreciationEditor,
    // RepairComponentsCommand, DemoDataService, et une copie morte du modèle), ce qui
    // avait déjà laissé diverger les arrondis.
    // -------------------------------------------------------------------------

    /** Part de la base amortissable revenant à un pourcentage donné, en centimes. */
    public static function baseFromPercentage(string $depreciableBaseCents, string $percentage): string
    {
        return bcmul($depreciableBaseCents, bcdiv($percentage, '100', 10), 0);
    }

    /**
     * Dotation annuelle linéaire, en centimes.
     *
     * ⚠️ Tronque, et doit continuer à tronquer : passer à l'arrondi décalerait d'un
     * centime toutes les dotations déjà enregistrées en production.
     */
    public static function annualFromBase(string $baseCents, int $durationYears): string
    {
        return $durationYears > 0 ? bcdiv($baseCents, (string) $durationYears, 0) : '0';
    }

    /**
     * Pourcentage qu'une base représente — valeur d'AFFICHAGE, jamais une entrée de calcul.
     *
     * Arrondi et non tronqué : `bcdiv` seul rendait 9,9999 % pour une part qui vaut
     * très exactement 10 %, ce qui donne l'air d'un bug dans les sorties du serveur MCP.
     */
    public static function percentageFromBase(string $depreciableBaseCents, string $baseCents): string
    {
        if (bccomp($depreciableBaseCents, '0', 0) <= 0) {
            return '0';
        }

        $scale = self::PERCENTAGE_SCALE;
        $raw = bcdiv(bcmul($baseCents, '100', $scale + 2), $depreciableBaseCents, $scale + 2);
        $half = bcdiv('5', bcpow('10', (string) ($scale + 1)), $scale + 2);

        return bcadd($raw, bccomp($raw, '0', $scale + 2) >= 0 ? $half : "-{$half}", $scale);
    }

    /**
     * Un écart d'au moins un facteur 10, dans un sens ou dans l'autre, ne peut pas
     * résulter d'un ajustement manuel raisonnable.
     */
    public static function looksCorrupted(int $stored, int $expected): bool
    {
        if ($expected === 0) {
            return $stored !== 0;
        }

        if ($stored === 0) {
            return true;
        }

        $ratio = $stored / $expected;

        return $ratio >= self::CORRUPTION_FACTOR || $ratio <= 1 / self::CORRUPTION_FACTOR;
    }

    /**
     * Décide, pour un bien existant, quels composants portaient déjà une base réglée
     * à la main — avant que `base_source` n'existe.
     *
     * La règle n'est pas inventée : c'est celle qu'`openlmnp:repair-components`
     * appliquait déjà. Un écart d'un facteur ≥ 10 est une corruption (elle restera
     * réparable) ; en dessous, la commande refusait déjà de corriger d'office, donc
     * l'écart était réputé volontaire.
     *
     * @return array<int, string> id du composant => base_source
     */
    public static function classifyLegacyBaseSource(Property $property): array
    {
        $base = $property->depreciable_base;
        $classification = [];

        foreach ($property->components as $component) {
            $expected = (int) self::baseFromPercentage($base, (string) $component->percentage);
            $stored = (int) $component->base_amount;

            $deliberate = $stored !== $expected && ! self::looksCorrupted($stored, $expected);

            $classification[$component->id] = $deliberate
                ? PropertyComponent::BASE_SOURCE_MANUAL
                : PropertyComponent::BASE_SOURCE_PERCENTAGE;
        }

        return $classification;
    }

    /**
     * Génère les composants d'amortissement par défaut pour un bien.
     */
    public function generateDefaultComponents(Property $property): void
    {
        $depreciableBase = $property->depreciable_base;

        foreach (self::DEFAULT_COMPONENTS as $comp) {
            $baseAmount = self::baseFromPercentage($depreciableBase, (string) $comp['percentage']);

            PropertyComponent::create([
                'property_id'         => $property->id,
                'name'                => $comp['name'],
                'percentage'          => $comp['percentage'],
                'duration_years'      => $comp['duration_years'],
                'base_amount'         => (int) $baseAmount,
                'annual_depreciation' => (int) self::annualFromBase($baseAmount, $comp['duration_years']),
                'base_source'         => PropertyComponent::BASE_SOURCE_PERCENTAGE,
                'sort_order'          => $comp['sort_order'],
            ]);
        }
    }

    /**
     * Enregistre la ventilation d'un bien, en METTANT À JOUR les composants existants.
     *
     * Remplace le `delete()` + `create()` qui régnait jusqu'au 2026-09-03 : celui-ci
     * changeait tous les identifiants à chaque enregistrement et effaçait toute donnée
     * qu'une autre fonctionnalité aurait posée sur la ligne. L'appariement se fait par
     * `id`, plus par nom — deux composants homonymes ne se marchent donc plus dessus.
     *
     * Sur-ventiler est refusé (transaction annulée, rien n'est écrit). Sous-ventiler est
     * ACCEPTÉ et signalé par `remainder` : un comptable peut légitimement n'avoir ventilé
     * qu'une partie de la base, et l'interdire reproduirait l'impasse de l'issue #8.
     *
     * @param  list<array{
     *     id?: int|null, name: string, duration_years: int, sort_order: int,
     *     base_source?: string, percentage?: float|string|null,
     *     base_amount?: int|null, annual_depreciation?: int|null
     * }>  $lines
     * @return array{written: int, deleted: int, remainder: string}
     *
     * @throws \RuntimeException si la ventilation dépasse la base amortissable
     */
    public function syncComponents(Property $property, array $lines): array
    {
        $depreciableBase = $property->depreciable_base;
        $resolved = [];

        foreach ($lines as $line) {
            $source = $line['base_source'] ?? PropertyComponent::BASE_SOURCE_PERCENTAGE;

            $baseAmount = $source === PropertyComponent::BASE_SOURCE_MANUAL
                ? (string) max(0, (int) ($line['base_amount'] ?? 0))
                : self::baseFromPercentage($depreciableBase, (string) ($line['percentage'] ?? 0));

            $resolved[] = [
                'id'                  => $line['id'] ?? null,
                'name'                => $line['name'],
                'duration_years'      => max(0, (int) $line['duration_years']),
                'sort_order'          => (int) ($line['sort_order'] ?? 0),
                'base_source'         => $source,
                'base_amount'         => $baseAmount,
                'annual_depreciation' => $line['annual_depreciation'] ?? null,
            ];
        }

        $allocated = array_reduce($resolved, fn ($carry, $l) => bcadd($carry, $l['base_amount'], 0), '0');

        if (bccomp($allocated, $depreciableBase, 0) > 0) {
            throw new \RuntimeException(sprintf(
                'La ventilation dépasse la base amortissable : %s € répartis pour %s € disponibles.',
                number_format((int) $allocated / 100, 0, ',', ' '),
                number_format((int) $depreciableBase / 100, 0, ',', ' '),
            ));
        }

        $resolved = $this->absorbTruncationDust($resolved, $depreciableBase, $allocated);
        $allocated = array_reduce($resolved, fn ($carry, $l) => bcadd($carry, $l['base_amount'], 0), '0');

        return DB::transaction(function () use ($property, $resolved, $depreciableBase, $allocated) {
            $keptIds = [];

            foreach ($resolved as $line) {
                $attributes = [
                    'name'           => $line['name'],
                    'percentage'     => (float) self::percentageFromBase($depreciableBase, $line['base_amount']),
                    'duration_years' => $line['duration_years'],
                    'base_amount'    => (int) $line['base_amount'],
                    'base_source'    => $line['base_source'],
                    'sort_order'     => $line['sort_order'],
                ];

                // En mode manuel seulement, une dotation explicite l'emporte sur le calcul :
                // l'arrondi du cabinet précédent n'est pas forcément le nôtre.
                if ($line['base_source'] === PropertyComponent::BASE_SOURCE_MANUAL
                    && $line['annual_depreciation'] !== null) {
                    $attributes['annual_depreciation'] = (int) $line['annual_depreciation'];
                } else {
                    $attributes['annual_depreciation'] = (int) self::annualFromBase(
                        $line['base_amount'],
                        $line['duration_years'],
                    );
                }

                $component = $line['id']
                    ? $property->components()->whereKey($line['id'])->first()
                    : null;

                if ($component) {
                    $component->forceFill($attributes)->save();
                } else {
                    $component = $property->components()->create($attributes);
                }

                $keptIds[] = $component->id;
            }

            $deleted = $property->components()->whereNotIn('id', $keptIds ?: [0])->delete();

            return [
                'written'   => count($keptIds),
                'deleted'   => $deleted,
                'remainder' => bcsub($depreciableBase, $allocated, 0),
            ];
        });
    }

    /**
     * Réaffecte les quelques centimes perdus par la troncature de `baseFromPercentage()`.
     *
     * Une ventilation à 100 % ne retombe pas exactement sur la base : chaque part est
     * tronquée séparément. Ces centimes-là sont de la poussière d'arrondi et reviennent
     * à la plus grosse ligne ventilée.
     *
     * En revanche, dès que le reliquat dépasse le nombre de lignes ventilées, ce n'est
     * plus de la poussière : c'est une sous-ventilation voulue par l'utilisateur, et la
     * maquiller serait lui mentir.
     *
     * @param  list<array<string, mixed>>  $resolved
     * @return list<array<string, mixed>>
     */
    private function absorbTruncationDust(array $resolved, string $depreciableBase, string $allocated): array
    {
        $remainder = bcsub($depreciableBase, $allocated, 0);

        if (bccomp($remainder, '0', 0) <= 0) {
            return $resolved;
        }

        $ventilated = array_keys(array_filter(
            $resolved,
            fn ($l) => $l['base_source'] === PropertyComponent::BASE_SOURCE_PERCENTAGE
                && bccomp($l['base_amount'], '0', 0) > 0,
        ));

        if ($ventilated === [] || bccomp($remainder, (string) count($ventilated), 0) > 0) {
            return $resolved;
        }

        $largest = $ventilated[0];
        foreach ($ventilated as $index) {
            if (bccomp($resolved[$index]['base_amount'], $resolved[$largest]['base_amount'], 0) > 0) {
                $largest = $index;
            }
        }

        $resolved[$largest]['base_amount'] = bcadd($resolved[$largest]['base_amount'], $remainder, 0);

        return $resolved;
    }

    /**
     * Calcule l'amortissement annuel total d'un bien pour une année donnée.
     *
     * Inclut : composants immeuble + travaux + mobilier
     * Applique le prorata temporis pour la 1ère année.
     *
     * @return array{
     *     building: string,
     *     works: string,
     *     furniture: string,
     *     total: string,
     *     details: array
     * }
     */
    public function calculateAnnualDepreciation(Property $property, int $year): array
    {
        // Amortissement immeuble (composants)
        $buildingTotal = '0';
        $details = [];

        foreach ($property->components as $component) {
            $annual = $this->calculateComponentForYear($component, $property, $year);
            $buildingTotal = bcadd($buildingTotal, $annual, 0);
            $details[] = [
                'type'   => 'building',
                'name'   => $component->name,
                'amount' => $annual,
            ];
        }

        // Amortissement travaux
        $worksTotal = '0';
        foreach ($property->works as $work) {
            $annual = $this->calculateWorkForYear($work, $property, $year);
            $worksTotal = bcadd($worksTotal, $annual, 0);
            $details[] = [
                'type'   => 'work',
                'name'   => $work->description,
                'amount' => $annual,
            ];
        }

        // Amortissement mobilier
        $furnitureTotal = '0';
        foreach ($property->furniture as $item) {
            $annual = $this->calculateFurnitureForYear($item, $property, $year);
            $furnitureTotal = bcadd($furnitureTotal, $annual, 0);
            $details[] = [
                'type'   => 'furniture',
                'name'   => $item->description,
                'amount' => $annual,
            ];
        }

        // Amortissement frais de notaire et honoraires agence (25 ans, avec quote-part si RP)
        $notaryTotal = '0';
        foreach (['notary_fees' => 'Frais de notaire', 'agency_fees' => 'Honoraires agence'] as $field => $label) {
            if ($property->$field > 0) {
                $annual = $this->calculateAcquisitionFeesForYear($property, $field, $year);
                if (bccomp($annual, '0', 0) > 0) {
                    $notaryTotal = bcadd($notaryTotal, $annual, 0);
                    $details[] = [
                        'type'   => 'notary',
                        'name'   => $label,
                        'amount' => $annual,
                    ];
                }
            }
        }

        $total = bcadd(bcadd(bcadd($buildingTotal, $worksTotal, 0), $furnitureTotal, 0), $notaryTotal, 0);

        return [
            'building'  => $buildingTotal,
            'works'     => $worksTotal,
            'furniture' => $furnitureTotal,
            'notary'    => $notaryTotal,
            'total'     => $total,
            'details'   => $details,
        ];
    }

    /**
     * Détaille, actif par actif, l'assiette brute, la dotation de l'exercice et le
     * cumul des amortissements à sa clôture. C'est ce qui alimente le tableau 2033-C.
     *
     * ⚠️ Les dotations proviennent des MÊMES méthodes que `calculateAnnualDepreciation()`,
     * qui alimente la ligne 254 du 2033-B. C'est ce qui rend l'égalité 572 = 254 vraie par
     * construction, et non plus par coïncidence : jusqu'au 2026-09-03 le 2033-C sommait les
     * `annual_depreciation` BRUTS — sans prorata, sans fin de plan, et sans aucune ligne
     * pour les frais d'acquisition — si bien que la liasse imprimait « ⚠ Écart » dès qu'un
     * bien portait des frais de notaire ou entamait sa première année.
     *
     * Le cumul est un vrai rejeu année par année, et non l'ancien `dotation × années`, qui
     * ignorait le prorata de première année comme les plans arrivés à terme.
     *
     * @return list<array{type: string, name: string, base: string, annual: string, cumul: string}>
     */
    public function depreciationDetailForYear(Property $property, int $year): array
    {
        $lines = [];

        foreach ($property->components as $component) {
            $lines[] = [
                'type'   => 'building',
                'name'   => $component->name,
                'base'   => (string) $component->base_amount,
                'annual' => $this->calculateComponentForYear($component, $property, $year),
                'cumul'  => $this->replay(
                    fn (int $y) => $this->calculateComponentForYear($component, $property, $y),
                    (int) $property->rental_start_date->format('Y'),
                    $year,
                ),
            ];
        }

        foreach ($property->works as $work) {
            $lines[] = [
                'type'   => 'work',
                'name'   => $work->description,
                'base'   => $this->grossAmount((string) $work->amount, $work->is_dedicated, $property),
                'annual' => $this->calculateWorkForYear($work, $property, $year),
                'cumul'  => $this->replay(
                    fn (int $y) => $this->calculateWorkForYear($work, $property, $y),
                    (int) $work->work_date->format('Y'),
                    $year,
                ),
            ];
        }

        foreach ($property->furniture as $item) {
            $lines[] = [
                'type'   => 'furniture',
                'name'   => $item->description,
                'base'   => $this->grossAmount((string) $item->amount, $item->is_dedicated, $property),
                'annual' => $this->calculateFurnitureForYear($item, $property, $year),
                'cumul'  => $this->replay(
                    fn (int $y) => $this->calculateFurnitureForYear($item, $property, $y),
                    (int) $item->purchase_date->format('Y'),
                    $year,
                ),
            ];
        }

        foreach (['notary_fees' => 'Frais de notaire', 'agency_fees' => 'Honoraires agence'] as $field => $label) {
            if ((int) $property->$field <= 0) {
                continue;
            }

            // Émise même à dotation nulle, contrairement à calculateAnnualDepreciation() :
            // sans quoi la valeur BRUTE des frais disparaîtrait du bilan une fois amortis.
            // La somme des dotations n'en est pas affectée, une ligne à zéro ajoute zéro.
            $lines[] = [
                'type'   => 'notary',
                'name'   => $label,
                'base'   => $property->is_primary_residence
                    ? bcmul((string) $property->$field, $property->quota_share, 0)
                    : (string) $property->$field,
                'annual' => $this->calculateAcquisitionFeesForYear($property, $field, $year),
                'cumul'  => $this->replay(
                    fn (int $y) => $this->calculateAcquisitionFeesForYear($property, $field, $y),
                    (int) $property->rental_start_date->format('Y'),
                    $year,
                ),
            ];
        }

        return $lines;
    }

    /** Assiette brute d'un actif, quote-part appliquée s'il n'est pas dédié à la location. */
    private function grossAmount(string $amount, bool $isDedicated, Property $property): string
    {
        return $isDedicated ? $amount : bcmul($amount, $property->quota_share, 0);
    }

    /**
     * Rejoue une dotation de $from à $to inclus et en rend le cumul.
     *
     * Aucune requête dans la boucle : les relations sont déjà chargées.
     */
    private function replay(callable $annualFor, int $from, int $to): string
    {
        $cumul = '0';

        for ($y = $from; $y <= $to; $y++) {
            $cumul = bcadd($cumul, $annualFor($y), 0);
        }

        return $cumul;
    }

    /**
     * Calcule l'amortissement d'un composant immeuble pour une année.
     */
    private function calculateComponentForYear(PropertyComponent $component, Property $property, int $year): string
    {
        $startDate = $property->rental_start_date;
        $startYear = (int) $startDate->format('Y');
        $endYear = $startYear + $component->duration_years - 1;

        // Pas encore commencé ou déjà terminé
        if ($year < $startYear || $year > $endYear) {
            return '0';
        }

        $annual = (string) $component->annual_depreciation;

        // Prorata temporis la 1ère année
        if ($year === $startYear) {
            $annual = $this->prorateFirstYear($annual, $startDate);
        }

        return $annual;
    }

    /**
     * Calcule l'amortissement de travaux pour une année.
     */
    private function calculateWorkForYear(PropertyWork $work, Property $property, int $year): string
    {
        $workDate = $work->work_date;
        $startYear = (int) $workDate->format('Y');
        $endYear = $startYear + $work->duration_years - 1;

        if ($year < $startYear || $year > $endYear) {
            return '0';
        }

        // Montant annuel, avec quote-part si non dédié
        $annual = (string) $work->annual_depreciation;
        if (! $work->is_dedicated) {
            $annual = bcmul($annual, $property->quota_share, 0);
        }

        // Prorata temporis la 1ère année
        if ($year === $startYear) {
            $annual = $this->prorateFirstYear($annual, $workDate);
        }

        return $annual;
    }

    /**
     * Calcule l'amortissement d'un meuble pour une année.
     */
    private function calculateFurnitureForYear(Furniture $item, Property $property, int $year): string
    {
        $purchaseDate = $item->purchase_date;
        $startYear = (int) $purchaseDate->format('Y');
        $endYear = $startYear + $item->duration_years - 1;

        if ($year < $startYear || $year > $endYear) {
            return '0';
        }

        $annual = (string) $item->annual_depreciation;
        if (! $item->is_dedicated) {
            $annual = bcmul($annual, $property->quota_share, 0);
        }

        // Prorata temporis
        if ($year === $startYear) {
            $annual = $this->prorateFirstYear($annual, $purchaseDate);
        }

        return $annual;
    }

    private const NOTARY_FEES_DURATION = 25;

    /**
     * Calcule l'amortissement des frais de notaire pour une année (25 ans linéaire).
     */
    private function calculateAcquisitionFeesForYear(Property $property, string $field, int $year): string
    {
        $startDate = $property->rental_start_date;
        $startYear = (int) $startDate->format('Y');
        $endYear = $startYear + self::NOTARY_FEES_DURATION - 1;

        if ($year < $startYear || $year > $endYear) {
            return '0';
        }

        $annual = bcdiv((string) $property->$field, (string) self::NOTARY_FEES_DURATION, 0);

        // Quote-part si résidence principale
        if ($property->is_primary_residence) {
            $annual = bcmul($annual, $property->quota_share, 0);
        }

        // Prorata temporis la 1ère année
        if ($year === $startYear) {
            $annual = $this->prorateFirstYear($annual, $startDate);
        }

        return $annual;
    }

    /**
     * Réduit une dotation annuelle au prorata des jours restants dans l'année,
     * la journée de départ comprise (BOI-BIC-AMT-20-10 § 20).
     *
     * ⚠️ Ne pas revenir à `diffInDays(...) + 1`, la formule d'avant le 2026-09-03 :
     * `Carbon::diffInDays()` rend un flottant qui inclut DÉJÀ la journée en cours
     * (du 1er janvier au 31 décembre 23 h 59 : 364,999…), donc le « + 1 » la comptait
     * une seconde fois. Toute première année était majorée de 1/365, soit +0,27 % —
     * un bien loué au 1er janvier amortissait 100,27 % de sa dotation.
     *
     * `dayOfYear` est un entier exact et ne dépend pas du comportement de Carbon.
     *
     * @param  string  $annual  Dotation annuelle pleine, en centimes (chaîne bcmath)
     * @return string           Dotation proratisée, en centimes
     */
    private function prorateFirstYear(string $annual, CarbonInterface $start): string
    {
        $daysInYear = $start->isLeapYear() ? 366 : 365;
        $remainingDays = $daysInYear - $start->dayOfYear + 1;

        return bcmul($annual, bcdiv((string) $remainingDays, (string) $daysInYear, 10), 0);
    }
}
