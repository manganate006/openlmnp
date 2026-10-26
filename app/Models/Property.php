<?php

namespace App\Models;

use App\Models\Scopes\BelongsToUserScope;
use App\Services\FiscalYearService;
use App\Support\Dpe\PropertyDpeVerdict;
use App\Support\MicroBicRules;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle représentant un bien immobilier en LMNP.
 *
 * Tous les montants sont stockés en centimes (entiers).
 * Les accesseurs *_euros convertissent en euros pour l'affichage.
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $name
 * @property string      $address
 * @property string      $city
 * @property string      $postal_code
 * @property string|null $insee_code             code INSEE de la commune (estimation DVF)
 * @property string|null $dpe_number             numéro du DPE (base ADEME)
 * @property string|null $dpe_class              classe du DPE, A à G
 * @property \Carbon\Carbon|null $dpe_established_at
 * @property \Carbon\Carbon|null $dpe_valid_until
 * @property string      $type
 * @property int         $total_area             m² total du logement
 * @property int         $rented_area            m² loués (quote-part)
 * @property \Carbon\Carbon $acquisition_date
 * @property int         $acquisition_price      centimes
 * @property int         $notary_fees            centimes
 * @property int         $agency_fees            centimes
 * @property int|null    $market_value           centimes
 * @property \Carbon\Carbon|null $market_value_date
 * @property float       $land_percentage        % terrain non amortissable (décimales admises)
 * @property \Carbon\Carbon $rental_start_date
 * @property string      $rental_type
 * @property string      $tourism_classification classement micro-BIC (CGI art. 50-0)
 * @property bool        $is_primary_residence   ⚠️ dérivée, lecture seule — voir residence_situation
 * @property string      $residence_situation    separate|part_of_residence|independent_unit_in_residence
 * @property string|null $notes
 */
#[ScopedBy([BelongsToUserScope::class])]
class Property extends Model
{
    // Types de biens
    public const TYPE_APARTMENT = 'apartment';
    public const TYPE_HOUSE     = 'house';
    public const TYPE_ROOM      = 'room';
    public const TYPE_STUDIO    = 'studio';
    public const TYPE_OTHER     = 'other';

    // Types de location
    public const RENTAL_SEASONAL   = 'seasonal';
    public const RENTAL_LONG_TERM  = 'long_term';
    public const RENTAL_MIXED      = 'mixed';

    // Régimes TVA
    public const TVA_EXEMPT  = 'exempt';
    public const TVA_LIABLE  = 'liable';

    // Situation du bien vis-à-vis de la résidence principale.
    //
    // ⚠️ Remplace `is_primary_residence` (conservé pour rétrocompatibilité, resynchronisé
    // automatiquement par `saving()`). Plus aucun calcul MONÉTAIRE n'en dépend (quote-part
    // toujours appliquée, voir DepreciationService) — mais `is_primary_residence` reste
    // ambigu entre `part_of_residence` et `independent_unit_in_residence` (toutes deux le
    // mettent à vrai) : tout consommateur qui doit distinguer les deux (ex. exemption DPE
    // des meublés de tourisme, réservée à la résidence principale au sens strict) doit lire
    // `residence_situation` directement, jamais ce booléen dérivé.
    public const RESIDENCE_SEPARATE = 'separate'; // logement distinct, non habité par le loueur
    public const RESIDENCE_PART_OF_RESIDENCE = 'part_of_residence'; // pièce(s) de la RP
    public const RESIDENCE_INDEPENDENT_UNIT = 'independent_unit_in_residence'; // logement indépendant sur la même propriété

    // Classement micro-BIC (CGI art. 50-0) — voir App\Support\MicroBicRules pour les
    // taux et plafonds associés.
    public const CLASSIFICATION_UNCLASSIFIED = MicroBicRules::UNCLASSIFIED;
    public const CLASSIFICATION_CLASSIFIED = MicroBicRules::CLASSIFIED;
    public const CLASSIFICATION_LONG_TERM = MicroBicRules::LONG_TERM;

    /**
     * Traitement des frais d'acquisition (notaire + agence).
     *
     * Le contribuable a le choix, à l'acquisition, entre les incorporer au coût de
     * revient — donc les amortir — et les déduire immédiatement en charges
     * (BOI-BIC-CHG-20-20-10). L'option est prise une fois pour toutes et vaut pour la
     * totalité des frais de l'exercice.
     *
     * Jusqu'au 2026-09-04, OpenLMNP imposait l'amortissement sur 25 ans. Un bailleur dont
     * le cabinet avait passé ces frais en charges l'année de l'acquisition les voyait donc
     * amortis une SECONDE fois : écart garanti avec sa liasse, sans rien pour le corriger.
     *
     * ⚠️ `AMORTIZED` et `CAPITALIZED` ne sont pas deux façons de dire la même chose, et la
     * confusion a duré : le libellé de `AMORTIZED` annonçait « incorporés au coût du bien »
     * alors que le code en faisait une immobilisation SÉPARÉE, amortie linéairement sur sa
     * propre durée. C'est cet écart entre l'annonce et le calcul qui a produit l'issue #11.
     *
     *   - `AMORTIZED`   : immobilisation distincte, durée propre (`acquisition_fees_duration`),
     *                     amortie en totalité. La base amortissable du bien ne bouge pas.
     *   - `CAPITALIZED` : les frais rejoignent le coût de revient du bien. Ils se répartissent
     *                     donc terrain / bâti par `land_percentage` — la part terrain cesse
     *                     de s'amortir — et le bâti suit le rythme des composants. C'est le
     *                     traitement que décrit le PCG (art. 213-8).
     */
    public const ACQUISITION_FEES_AMORTIZED   = 'amortized';
    public const ACQUISITION_FEES_CAPITALIZED = 'capitalized';
    public const ACQUISITION_FEES_EXPENSED    = 'expensed';
    public const ACQUISITION_FEES_EXCLUDED    = 'excluded';

    /** Durée retenue quand rien n'est précisé — la pratique dominante en LMNP. */
    public const ACQUISITION_FEES_DEFAULT_DURATION = 25;

    protected $fillable = [
        'user_id',
        'name',
        'address',
        'city',
        'postal_code',
        'insee_code',
        'dpe_number',
        'dpe_class',
        'dpe_established_at',
        'dpe_valid_until',
        'type',
        'total_area',
        'rented_area',
        'acquisition_date',
        'acquisition_price',
        'notary_fees',
        'agency_fees',
        'acquisition_fees_treatment',
        'acquisition_fees_duration',
        'market_value',
        'market_value_date',
        'land_percentage',
        'rental_start_date',
        'airbnb_commission_rate',
        'rental_type',
        'tourism_classification',
        'tva_regime',
        'is_primary_residence',
        'residence_situation',
        'notes',
        'photo_path',
        'listing_urls',
    ];

    protected function casts(): array
    {
        return [
            'acquisition_date'    => 'date',
            'market_value_date'   => 'date',
            'dpe_established_at'  => 'date',
            'dpe_valid_until'     => 'date',
            'rental_start_date'   => 'date',
            'is_primary_residence' => 'boolean',
            'listing_urls'        => 'array',
        ];
    }

    // -------------------------------------------------------------------------
    // Labels UI (français)
    // -------------------------------------------------------------------------

    public static function typeLabels(): array
    {
        return [
            self::TYPE_APARTMENT => 'Appartement',
            self::TYPE_HOUSE     => 'Maison',
            self::TYPE_ROOM      => 'Chambre',
            self::TYPE_STUDIO    => 'Studio',
            self::TYPE_OTHER     => 'Autre',
        ];
    }

    public static function rentalTypeLabels(): array
    {
        return [
            self::RENTAL_SEASONAL  => 'Saisonnier (Airbnb…)',
            self::RENTAL_LONG_TERM => 'Location longue durée',
            self::RENTAL_MIXED     => 'Mixte',
        ];
    }

    /**
     * Libellés de la situation du bien, avec leur conséquence fiscale résumée — ce
     * sont ces phrases qui font foi dans les formulaires (onboarding + fiche bien).
     */
    public static function residenceSituationLabels(): array
    {
        return [
            self::RESIDENCE_SEPARATE => 'Logement distinct (je n\'y habite pas)',
            self::RESIDENCE_PART_OF_RESIDENCE => 'Une ou plusieurs pièces de ma résidence principale',
            self::RESIDENCE_INDEPENDENT_UNIT => 'Logement indépendant dans ma résidence principale (ou sur la même propriété)',
        ];
    }

    public static function tourismClassificationLabels(): array
    {
        return MicroBicRules::labels();
    }

    public function getTypeLabel(): string
    {
        return static::typeLabels()[$this->type] ?? $this->type;
    }

    public function getRentalTypeLabel(): string
    {
        return static::rentalTypeLabels()[$this->rental_type] ?? $this->rental_type;
    }

    public function getResidenceSituationLabel(): string
    {
        return static::residenceSituationLabels()[$this->residence_situation] ?? $this->residence_situation;
    }

    public function getTourismClassificationLabel(): string
    {
        return static::tourismClassificationLabels()[$this->tourism_classification] ?? $this->tourism_classification;
    }

    public static function tvaRegimeLabels(): array
    {
        return [
            self::TVA_EXEMPT => 'Franchise en base (non assujetti)',
            self::TVA_LIABLE => 'Assujetti TVA (para-hôtelier)',
        ];
    }

    public function isTvaLiable(): bool
    {
        return $this->tva_regime === self::TVA_LIABLE;
    }

    /**
     * Taux de TVA à proposer sur une recette locative : 10 % si le bien est
     * assujetti, 0 sinon.
     *
     * ⚠️ Une seule définition, parce que l'expression vivait en QUATRE exemplaires —
     * dont deux qui écrivaient `0` en dur sur un chemin d'analyse. Le hook `saving`
     * d'Income en dérive `tva_collected` : un 0 posé sur un bien assujetti écrit donc
     * une TVA collectée nulle dans la base que lit TvaDeclarationService. Sans message,
     * sans test, sans rien d'observable avant la déclaration.
     */
    public function defaultIncomeTvaRate(): int
    {
        return $this->isTvaLiable() ? 1000 : 0;
    }

    /** @return array<string, string> */
    public static function acquisitionFeesTreatmentLabels(): array
    {
        return [
            self::ACQUISITION_FEES_AMORTIZED   => 'Amortis séparément, sur leur propre durée',
            self::ACQUISITION_FEES_CAPITALIZED => 'Intégrés au coût du bien (amortis avec les composants)',
            self::ACQUISITION_FEES_EXPENSED    => 'Passés en charges l\'année de l\'acquisition',
            self::ACQUISITION_FEES_EXCLUDED    => 'Non repris (hors comptabilité)',
        ];
    }

    /** Le traitement retenu, l'ancien comportement valant défaut pour une valeur absente. */
    public function acquisitionFeesTreatment(): string
    {
        // Une valeur absente vaut l'ancien comportement : les instances qui n'ont jamais
        // vu ce réglage continuent d'amortir séparément, comme avant l'ajout de la colonne.
        return $this->acquisition_fees_treatment ?? self::ACQUISITION_FEES_AMORTIZED;
    }

    /**
     * Vrai si les frais portent leur PROPRE ligne d'immobilisation, amortie à part.
     *
     * ⚠️ Ne pas confondre avec `capitalizesAcquisitionFees()` : sous `CAPITALIZED` les frais
     * sont bien amortis, mais à travers les composants du bien — aucune ligne distincte ne
     * doit être émise, sans quoi ils seraient comptés deux fois au bilan.
     */
    public function emitsSeparateAcquisitionFeeLine(): bool
    {
        return $this->acquisitionFeesTreatment() === self::ACQUISITION_FEES_AMORTIZED;
    }

    /** Vrai si les frais rejoignent le coût de revient du bien (et donc sa base amortissable). */
    public function capitalizesAcquisitionFees(): bool
    {
        return $this->acquisitionFeesTreatment() === self::ACQUISITION_FEES_CAPITALIZED;
    }

    /** Montant total des frais d'acquisition saisis, en centimes. */
    public function acquisitionFeesTotal(): string
    {
        return bcadd((string) (int) $this->notary_fees, (string) (int) $this->agency_fees, 0);
    }

    /** Durée d'amortissement des frais d'acquisition, en années. */
    public function acquisitionFeesDurationYears(): int
    {
        return max(1, (int) ($this->acquisition_fees_duration ?: self::ACQUISITION_FEES_DEFAULT_DURATION));
    }

    // -------------------------------------------------------------------------
    // Accesseurs montants en euros
    // -------------------------------------------------------------------------

    public function getAcquisitionPriceEurosAttribute(): string
    {
        return bcdiv((string) $this->acquisition_price, '100', 2);
    }

    public function getNotaryFeesEurosAttribute(): string
    {
        return bcdiv((string) $this->notary_fees, '100', 2);
    }

    public function getAgencyFeesEurosAttribute(): string
    {
        return bcdiv((string) $this->agency_fees, '100', 2);
    }

    public function getMarketValueEurosAttribute(): ?string
    {
        if ($this->market_value === null) {
            return null;
        }

        return bcdiv((string) $this->market_value, '100', 2);
    }

    // -------------------------------------------------------------------------
    // Accesseurs calculés (financiers)
    // -------------------------------------------------------------------------

    /**
     * Retourne la quote-part de superficie louée (rented_area / total_area).
     * Exemple : 31m² loués / 118m² total = 0.2627
     *
     * ⚠️ REFUSE de calculer plutôt que de rendre silencieusement `'0'` quand `total_area`
     * est absente ou nulle. Cette quote-part multiplie la base amortissable ET quasiment
     * toutes les charges au prorata (`Expense`, `PropertyWork`, `Furniture`,
     * `AccountingEntryService`, `FiscalYearService`, `TaxReturnService`…) : un `'0'` muet n'y
     * ressemble jamais à une erreur — il ressemble à un résultat fiscal exact, juste faux.
     * `total_area` est obligatoire et positive à toute création/modification (formulaire,
     * assistants, outils MCP) : cette exception ne devrait donc jamais se déclencher en usage
     * normal. Si elle se déclenche, c'est qu'une donnée existante y a échappé — la corriger
     * sur la fiche du bien plutôt que la contourner ici.
     *
     * @throws \RuntimeException Si `total_area` est absente ou ≤ 0.
     */
    public function getQuotaShareAttribute(): string
    {
        if ($this->total_area <= 0) {
            throw new \RuntimeException(
                "Bien « {$this->name} » : surface totale absente ou nulle, impossible de calculer "
                . 'la quote-part de surface louée. Renseignez la surface totale sur la fiche du bien.'
            );
        }

        return bcdiv((string) $this->rented_area, (string) $this->total_area, 10);
    }

    /**
     * Valeur de référence du bien, en centimes — la SEULE source, à n'écrire nulle part ailleurs.
     *
     * ⚠️ Elle était recalculée à trois endroits indépendants : ici, dans la case 028 du
     * 2033-A et dans la ligne 420 (terrain) du 2033-C. Or `044 − 490` ne vaut le reliquat
     * de ventilation que si les trois s'accordent au centime — en oublier un fait diverger
     * le bilan de l'état des immobilisations, sans qu'aucun des deux ne soit « faux » pris
     * isolément. C'est devenu une vraie mine le jour où les frais d'acquisition ont pu
     * entrer dans cette valeur.
     *
     * Les frais ne s'y ajoutent que sous le traitement `CAPITALIZED`, ET seulement à défaut
     * de valeur vénale : une valeur vénale est la valeur d'entrée dans l'activité, à laquelle
     * les frais d'une acquisition antérieure n'ont aucune raison de s'ajouter. Le formulaire
     * du bien signale la combinaison plutôt que de la refuser.
     */
    public function referenceValue(): string
    {
        if ($this->market_value !== null) {
            return (string) $this->market_value;
        }

        $value = (string) $this->acquisition_price;

        return $this->capitalizesAcquisitionFees()
            ? bcadd($value, $this->acquisitionFeesTotal(), 0)
            : $value;
    }

    /** Vrai si des frais capitalisés sont ignorés parce qu'une valeur vénale prime. */
    public function acquisitionFeesIgnoredByMarketValue(): bool
    {
        return $this->capitalizesAcquisitionFees()
            && $this->market_value !== null
            && bccomp($this->acquisitionFeesTotal(), '0', 0) > 0;
    }

    /**
     * Retourne la base amortissable en centimes.
     *
     * Formule :
     *   base = referenceValue() * (1 - land_percentage/100) * quota_share
     *
     * Tous les calculs utilisent bcmath.
     *
     * ⚠️ CONVENTION — la seule à suivre pour toute base saisie ou importée d'une liasse d'un
     * bien PARTIELLEMENT loué (`quota_share < 1`, ex. une chambre dans une résidence
     * principale) : le montant qu'un cabinet inscrit au 2033-C pour un tel bien est déjà LA
     * PART PROFESSIONNELLE de l'immobilisation (l'affectation partielle d'un bien mixte
     * n'entre dans le patrimoine professionnel qu'à hauteur de son usage professionnel) —
     * jamais la valeur du bien entier. Une base « manual » (reprise d'une liasse, voir
     * `RepriseDossier::applyLiasse()`) doit donc être copiée TELLE QUELLE dans
     * `PropertyComponent::base_amount`, sans jamais la re-multiplier par `quota_share` : elle
     * l'a déjà subie chez le cabinet. `DepreciationService::syncComponents()` applique cette
     * règle : `base_amount` d'un composant `manual` n'est JAMAIS recalculé depuis
     * `percentage` — ce dernier n'est qu'un DÉRIVÉ d'affichage
     * (`base_amount / depreciable_base`, quote-part déjà dedans), jamais une entrée de calcul
     * (voir le docblock de `PropertyComponent::$percentage`). Seule une base `percentage`
     * (ventilation par curseurs, sans reprise de cabinet) part de zéro et applique la
     * quote-part une fois, ici, via `depreciable_base`.
     *
     * Défaut vérifié adjacent, et non celui-ci : l'import IA d'une liasse recopiait aussi la
     * ligne « Terrains » du 2033-C comme composant amortissable (un terrain ne s'amortit
     * JAMAIS) — voir `PropertyComponent::CERFA_CATEGORY_LAND`. Corrigé à l'import, pas ici.
     */
    public function getDepreciableBaseAttribute(): string
    {
        // Fraction non-terrain : (100 - land_percentage) / 100
        $buildingFraction = bcdiv(
            bcsub('100', (string) $this->land_percentage, 10),
            '100',
            10
        );

        // Base = valeur de référence * fraction_bâti * quote-part
        return bcmul(
            bcmul($this->referenceValue(), $buildingFraction, 10),
            $this->quota_share,
            0  // résultat en centimes entiers
        );
    }

    /**
     * Base amortissable en euros (pour l'affichage).
     */
    public function getDepreciableBaseEurosAttribute(): string
    {
        return bcdiv($this->depreciable_base, '100', 2);
    }

    // -------------------------------------------------------------------------
    // DPE
    // -------------------------------------------------------------------------

    /** Vrai si le bien est dans un département d'outre-mer visé par l'article 6 de 1989. */
    public function dpeOverseas(): bool
    {
        return PropertyDpeVerdict::overseas($this->postal_code);
    }

    /**
     * Verdicts de décence énergétique applicables à la configuration de location du bien
     * (bail meublé et/ou meublé de tourisme selon `rental_type` et `residence_situation`).
     * Vide tant qu'aucune classe de DPE n'est renseignée.
     *
     * ⚠️ L'exemption de l'art. L. 324-2-2 du code du tourisme ne vaut que si le meublé de
     * tourisme EST la résidence principale du loueur (`part_of_residence` : une ou plusieurs
     * pièces de son propre logement) — jamais `is_primary_residence`, qui vaut également vrai
     * pour `independent_unit_in_residence`, un logement que la loi traite au contraire comme
     * DISTINCT de la résidence principale (voir le commentaire de `RESIDENCE_INDEPENDENT_UNIT`).
     *
     * @return array<string, array{label: string, applies: bool, allowed: bool|null, worst_allowed: string|null, next: array{date: string, worst_allowed: string}|null}>
     */
    public function dpeVerdicts(?string $date = null): array
    {
        return PropertyDpeVerdict::forRentalConfiguration(
            $this->dpe_class,
            $this->rental_type,
            $this->residence_situation === self::RESIDENCE_PART_OF_RESIDENCE,
            $this->postal_code,
            $date,
        );
    }

    /** État d'expiration du DPE : `expired`, `soon` (moins de 6 mois), ou null. */
    public function dpeExpiryStatus(): ?string
    {
        return PropertyDpeVerdict::expiryStatus($this->dpe_valid_until?->toDateString());
    }

    public function dpeExpired(): bool
    {
        return $this->dpeExpiryStatus() === 'expired';
    }

    public function dpeExpiringSoon(): bool
    {
        return $this->dpeExpiryStatus() === 'soon';
    }

    protected static function booted(): void
    {
        // Deux colonnes, une seule source de vérité : `residence_situation`.
        //
        // Un appelant qui ne connaît que l'ancien booléen (test existant, intégration
        // tierce) continue de fonctionner : on en dérive `residence_situation` s'il
        // n'a pas été fourni explicitement. Et un appelant qui pose directement
        // `residence_situation` garde malgré tout un `is_primary_residence` cohérent
        // pour l'affichage historique — mais plus personne ne doit s'y fier pour un
        // calcul (voir DepreciationService, où la quote-part s'applique désormais
        // uniformément, quelle que soit la situation).
        //
        // ⚠️ La dérivation ne joue pas qu'à la création. Un bien EXISTANT porte déjà un
        // `residence_situation` non vide (`separate` par défaut) : sans ce second cas, un
        // appelant qui modifie SEULEMENT l'ancien booléen sur un bien déjà enregistré se
        // le voyait silencieusement écrasé par la ligne suivante, qui recalcule toujours
        // `is_primary_residence` depuis `residence_situation` — le booléen posé à `true`
        // repartait à `false` dans la même requête. `isDirty()` distingue donc « l'appelant
        // vient de changer ce champ » d'« il n'y a pas touché » ; `residence_situation`
        // explicite reste prioritaire si les deux bougent à la fois dans le même appel.
        static::saving(function (self $property) {
            $situationExplicit = $property->isDirty('residence_situation') && filled($property->residence_situation);
            $legacyBooleanExplicit = $property->isDirty('is_primary_residence');

            if (blank($property->residence_situation)) {
                $property->residence_situation = $property->is_primary_residence
                    ? self::RESIDENCE_PART_OF_RESIDENCE
                    : self::RESIDENCE_SEPARATE;
            } elseif ($legacyBooleanExplicit && ! $situationExplicit) {
                // La distinction pièce(s) de la RP / logement indépendant n'existe pas côté
                // legacy : on retombe sur la correspondance la plus proche.
                $property->residence_situation = $property->is_primary_residence
                    ? self::RESIDENCE_PART_OF_RESIDENCE
                    : self::RESIDENCE_SEPARATE;
            }

            $property->is_primary_residence = $property->residence_situation !== self::RESIDENCE_SEPARATE;

            if (blank($property->tourism_classification)) {
                $property->tourism_classification = MicroBicRules::defaultClassificationForRentalType($property->rental_type);
            }
        });

        // Supprimer un bien emporte en cascade ses revenus, charges, meubles, travaux,
        // composants et emprunts — mais laissait les EXERCICES intacts, avec des totaux
        // calculés sur des données qui n'existent plus. `fiscal_years` ne porte aucun
        // lien vers `properties` (un exercice agrège tous les biens de l'année), donc
        // aucune contrainte de clé étrangère ne pouvait s'en charger.
        //
        // Rien ne signalait l'incohérence : l'exercice continuait d'alimenter
        // `/fiscal-years` et le tableau de bord avec ses montants figés, et son
        // amortissement différé se propageait aux exercices suivants.
        //
        // Seuls les BROUILLONS sont recalculés. Un exercice clôturé est la trace de ce
        // qui a été déclaré : le réécrire sans le dire effacerait un fait. Il est
        // signalé par `openlmnp:repair-orphan-fiscal-years`, qui laisse l'arbitrage à
        // l'utilisateur. Le recalcul des brouillons corrige tout de même la chaîne des
        // reports en aval, `FiscalYearService` propageant déjà `previous_deferred`.
        static::deleted(function (self $property) {
            app(FiscalYearService::class)->recalculateDrafts($property->user_id);
        });
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(PropertyComponent::class)->orderBy('sort_order');
    }

    public function works(): HasMany
    {
        return $this->hasMany(PropertyWork::class)->orderBy('work_date');
    }

    public function furniture(): HasMany
    {
        return $this->hasMany(Furniture::class)->orderBy('purchase_date');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class)->orderBy('start_date');
    }

    public function incomes(): HasMany
    {
        return $this->hasMany(Income::class)->orderBy('income_date');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class)->orderBy('expense_date');
    }

    public function accountingEntries(): HasMany
    {
        return $this->hasMany(AccountingEntry::class)->orderBy('entry_date');
    }

    // -------------------------------------------------------------------------
    // Query scopes
    // -------------------------------------------------------------------------

    /**
     * Filtre sur les biens de location saisonnière.
     */
    public function scopeSeasonal(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where('rental_type', self::RENTAL_SEASONAL);
    }

    /**
     * Filtre sur les biens actifs (mise en location démarrée).
     */
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where('rental_start_date', '<=', now()->toDateString());
    }
}
