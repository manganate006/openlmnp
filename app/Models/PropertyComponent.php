<?php

namespace App\Models;

use App\Models\Scopes\BelongsToUserThroughPropertyScope;
use App\Services\DepreciationService;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Composant d'amortissement par ventilation d'un bien immobilier.
 *
 * Conforme à la doctrine fiscale LMNP régime réel :
 * le bien est décomposé en plusieurs composants, chacun amorti
 * sur une durée propre.
 *
 * Composants standards recommandés :
 *   - Gros œuvre       : 50 %, 50 ans
 *   - Toiture          : 10 %, 25 ans
 *   - Électricité      : 10 %, 25 ans
 *   - Étanchéité       : 5 %,  15 ans
 *   - Agencements      : 15 %, 15 ans
 *   - Plomberie        : 10 %, 15 ans
 *
 * ⚠️ Depuis le 2026-09-03, c'est `base_amount` qui fait foi, plus `percentage`.
 * Un pourcentage ne peut pas représenter une base au centime près (il y faudrait
 * neuf décimales), ce qui rendait impossible de reproduire un plan d'amortissement
 * déjà pratiqué par un comptable — c'est l'objet de l'issue #8. `percentage` est
 * désormais DÉRIVÉ de la base, et n'entre plus jamais dans un calcul de montant.
 *
 * `base_source` dit qui pilote la base :
 *   - `percentage` : ventilée depuis la base amortissable du bien. Les curseurs la
 *     pilotent, `openlmnp:repair-components` la resynchronise.
 *   - `manual`     : fixée à la main. Plus rien ne la recalcule.
 *
 * ⚠️ `cerfa_category` remplace le rattachement PAR LE NOM qui vivait dans
 * `TaxReturnService::compute2033C()`. Un composant renommé (« Toiture ardoise ») ou créé
 * à la main tombait en « autres » : sa base et sa dotation changeaient de ligne Cerfa à
 * l'insu de l'utilisateur. La catégorie est désormais une donnée, dérivée du nom
 * seulement quand personne ne l'a fixée.
 *
 * ⚠️ `opening_accumulated_depreciation` ne s'ajoute qu'aux CUMULS AFFICHÉS (2033-A case
 * 030, colonne « amortissements » du 2033-C). Il n'entre jamais dans la dotation d'un
 * exercice — sinon la charge de l'année serait comptée deux fois.
 *
 * @property int         $id
 * @property int         $property_id
 * @property string      $name
 * @property float       $percentage          % de la base amortissable (DÉRIVÉ)
 * @property int         $duration_years      durée d'amortissement
 * @property int         $base_amount         centimes — source de vérité
 * @property int         $annual_depreciation centimes
 * @property string      $base_source         percentage|manual
 * @property \Carbon\Carbon|null $depreciation_start_date  défaut : rental_start_date du bien
 * @property string|null $cerfa_category      constructions|installations|agencements|autres
 * @property int         $opening_accumulated_depreciation centimes, cumuls affichés UNIQUEMENT
 * @property int         $sort_order
 */
#[ScopedBy([BelongsToUserThroughPropertyScope::class])]
class PropertyComponent extends Model
{
    public const BASE_SOURCE_PERCENTAGE = 'percentage';
    public const BASE_SOURCE_MANUAL = 'manual';

    public const CERFA_CATEGORY_CONSTRUCTIONS = 'constructions';
    public const CERFA_CATEGORY_INSTALLATIONS = 'installations';
    public const CERFA_CATEGORY_FITTINGS      = 'agencements';
    public const CERFA_CATEGORY_OTHER         = 'autres';

    /**
     * Rattachement d'un composant du catalogue à sa ligne du 2033-C.
     *
     * ⚠️ Recopié à l'identique de la table qui vivait dans `TaxReturnService::compute2033C()`,
     * et qui décidait par le NOM. Ne rien y changer sans mesurer l'effet sur des liasses
     * déjà déposées : un montant qui change de ligne Cerfa d'un exercice à l'autre est
     * une incohérence visible par l'administration.
     */
    public const LEGACY_NAME_TO_CATEGORY = [
        'Gros œuvre'                => self::CERFA_CATEGORY_CONSTRUCTIONS,
        'Toiture'                   => self::CERFA_CATEGORY_CONSTRUCTIONS,
        'Installations électriques' => self::CERFA_CATEGORY_INSTALLATIONS,
        'Plomberie / sanitaire'     => self::CERFA_CATEGORY_INSTALLATIONS,
        'Étanchéité'                => self::CERFA_CATEGORY_FITTINGS,
        'Agencements intérieurs'    => self::CERFA_CATEGORY_FITTINGS,
    ];

    protected $fillable = [
        'property_id',
        'name',
        'percentage',
        'duration_years',
        'base_amount',
        'annual_depreciation',
        'base_source',
        'depreciation_start_date',
        'cerfa_category',
        'opening_accumulated_depreciation',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            // `percentage` est un decimal(7,4) en base : sans ce cast, Eloquent rendrait
            // la chaîne "50.0000" et polluerait les sorties JSON du serveur MCP.
            'percentage' => 'float',
            'depreciation_start_date' => 'date',
        ];
    }

    /** @return array<string, string> Libellés français des lignes du 2033-C. */
    public static function cerfaCategoryLabels(): array
    {
        return [
            self::CERFA_CATEGORY_CONSTRUCTIONS => 'Constructions (lignes 430 / 520)',
            self::CERFA_CATEGORY_INSTALLATIONS => 'Installations techniques (440 / 530)',
            self::CERFA_CATEGORY_FITTINGS      => 'Agencements et aménagements (450 / 540)',
            self::CERFA_CATEGORY_OTHER         => 'Autres immobilisations (470 / 560)',
        ];
    }

    /** Ligne Cerfa qu'un nom de composant impliquait avant que la colonne n'existe. */
    public static function cerfaCategoryForName(?string $name): string
    {
        return self::LEGACY_NAME_TO_CATEGORY[$name] ?? self::CERFA_CATEGORY_OTHER;
    }

    /** Ligne Cerfa effective : la catégorie posée, à défaut celle que le nom impliquait. */
    public function cerfaCategory(): string
    {
        return array_key_exists((string) $this->cerfa_category, self::cerfaCategoryLabels())
            ? (string) $this->cerfa_category
            : self::cerfaCategoryForName($this->name);
    }

    protected static function booted(): void
    {
        static::saving(function (PropertyComponent $component) {
            // Une catégorie absente reprend celle que le nom impliquait : la ligne Cerfa
            // d'un composant du catalogue ne bouge pas parce que la colonne est nouvelle.
            if (! array_key_exists((string) $component->cerfa_category, self::cerfaCategoryLabels())) {
                $component->cerfa_category = self::cerfaCategoryForName($component->name);
            }

            // Même contrat que PropertyWork et Furniture : la dotation est dérivée du
            // montant et de la durée. Seule exception, le mode manuel — un utilisateur
            // qui reprend une comptabilité existante fixe sa dotation au centime près,
            // et l'arrondi de son cabinet n'est pas forcément le nôtre.
            if ($component->base_source === self::BASE_SOURCE_MANUAL && $component->annual_depreciation) {
                return;
            }

            if ($component->base_amount > 0 && $component->duration_years > 0) {
                $component->annual_depreciation = (int) DepreciationService::annualFromBase(
                    (string) $component->base_amount,
                    (int) $component->duration_years,
                );
            }
        });
    }

    // -------------------------------------------------------------------------
    // Accesseurs euros
    // -------------------------------------------------------------------------

    public function getBaseAmountEurosAttribute(): string
    {
        return bcdiv((string) $this->base_amount, '100', 2);
    }

    public function getAnnualDepreciationEurosAttribute(): string
    {
        return bcdiv((string) $this->annual_depreciation, '100', 2);
    }

    // -------------------------------------------------------------------------
    // Méthodes de calcul
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
