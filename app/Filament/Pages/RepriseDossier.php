<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\EditsDepreciationComponents;
use App\Models\FiscalYear;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Services\Ai\Analysis\AnalysisResult;
use App\Services\Ai\DocumentAnalysisService;
use App\Services\Ai\Exceptions\AiException;
use App\Services\DepreciationService;
use App\Services\FiscalYearService;
use App\Services\ReprisesCheckService;
use App\Support\AiAccess;
use App\Support\AiQuota;
use App\Support\AiStagingUpload;
use App\Support\DocumentStorage;
use App\Support\DossierContext;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Assistant « Reprendre un dossier existant ».
 *
 * Le bailleur qui quitte son cabinet n'a que sa dernière liasse sous les yeux. Il ne doit
 * ressaisir AUCUN exercice passé : trois chiffres lus sur cette liasse suffisent à replacer
 * ses reports, et l'étape 5 lui prouve que l'application retombe sur les chiffres de son
 * comptable. Sans cette preuve, il n'ose pas partir — c'est l'étape 5 la fonctionnalité.
 *
 * Cinq étapes, jamais plus (cahier des charges § 4) :
 *   1. votre situation      — depuis quand louez-vous, avez-vous déclaré au réel, quelle
 *                             première année tenir ici
 *   2. votre bien           — prix, frais, terrain, traitement des frais d'acquisition
 *   3. vos amortissements   — CHOIX de la méthode, puis l'éditeur existant (deux modes)
 *   4. vos reports          — 2033-D 870, 2033-A 030, 2033-A 028, déficits 980-984
 *   5. contrôle             — `ReprisesCheckService`, verdicts et diagnostics ordonnés
 *
 * Chaque champ demandé porte le numéro de la case Cerfa où le lire : c'est la seule façon
 * de rendre la saisie possible sans expertise comptable.
 *
 * ⚠️ RIEN n'est écrit dans `fiscal_years` avant « Terminer la reprise » : l'étape 5 fait
 * tourner le contrôle sur un exercice NON PERSISTÉ. Un utilisateur qui abandonne en cours
 * de route ne laisse donc pas derrière lui un exercice fantôme dont les reports fausseraient
 * toute la chaîne. Le bien et ses composants, eux, sont bien enregistrés au fil des étapes :
 * ce sont des données du dossier, pas des soldes de reprise.
 */
class RepriseDossier extends Page
{
    use EditsDepreciationComponents, WithFileUploads;

    protected static ?string $slug = 'reprise';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Reprendre un dossier existant';

    protected string $view = 'filament.pages.reprise-dossier';

    /**
     * Un compte « membre » n'a jamais de biens à lui : cet assistant en crée un
     * depuis zéro (lot « dossiers membre », décision du 2026-10-15).
     */
    public static function canAccess(): bool
    {
        return parent::canAccess() && ! DossierContext::isMemberActingAsPrincipal();
    }

    public function getHeading(): string
    {
        return $this->finished ? 'Votre dossier est repris' : 'Reprendre un dossier existant';
    }

    public function getSubheading(): ?string
    {
        return $this->finished
            ? 'Vous pouvez créer votre exercice ' . $this->firstYear . '. Vos reports sont en place.'
            : 'Nous allons reprendre votre plan d\'amortissement et vos reports à partir de votre '
              . 'dernière liasse fiscale.';
    }

    /** Recopier les lignes de sa liasse : saisie en euros, `base_source = manual`. */
    public const METHOD_COPY = 'copy';

    /** Répartir automatiquement la base : ventilation standard, `base_source = percentage`. */
    public const METHOD_SPREAD = 'spread';

    /**
     * Faire lire la liasse par l'IA : les lignes du 2033-C sont recopiées après
     * RELECTURE. Une option parmi trois, jamais un passage obligé — la carte
     * n'apparaît que si l'IA est activée et disponible pour ce compte.
     */
    public const METHOD_AI = 'ai';

    /** L'utilisateur amortit depuis la mise en location. */
    public const REGIME_SINCE_START = 'since_start';

    /** L'utilisateur est passé au réel après coup (micro-BIC avant). */
    public const REGIME_SINCE_YEAR = 'since_year';

    /** Aucun amortissement pratiqué : il n'y a rien à reprendre. */
    public const REGIME_NEW = 'new';

    // -------------------------------------------------------------------------
    // État du parcours
    // -------------------------------------------------------------------------

    public int $step = 1;

    /** Étape 5 franchie : l'écran final (« ce qui a été repris ») remplace le parcours. */
    public bool $finished = false;

    /** @var array<string, string> Erreurs de validation de l'étape courante. */
    public array $stepErrors = [];

    // Étape 1 — votre situation
    public ?string $rentalStartDate = null;
    public ?int $firstYear = null;
    public string $regime = self::REGIME_SINCE_START;
    public ?int $regimeSinceYear = null;

    // Étape 2 — votre bien
    public ?int $propertyId = null;
    public string $propertyName = '';
    public string $propertyAddress = '';
    public string $propertyCity = '';
    public string $propertyPostalCode = '';
    public ?string $propertyArea = null;
    public ?string $acquisitionDate = null;
    public ?string $acquisitionPrice = null;
    public ?string $notaryFees = null;
    public ?string $agencyFees = null;
    public ?string $landPercentage = '15';
    public string $acquisitionFeesTreatment = Property::ACQUISITION_FEES_AMORTIZED;
    public ?string $acquisitionFeesDuration = '25';

    // Étape 3 — vos amortissements
    public ?string $method = null;

    // Étape 3 — lecture IA de la liasse (relecture obligatoire avant toute écriture)
    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $liasseFile = null;

    /** Consentement explicite à l'envoi de la liasse au service d'IA (case à cocher). */
    public bool $liasseConsent = false;

    /**
     * @var array<string, mixed>|null Résultat d'analyse sérialisé — rien n'est écrit tant qu'il n'est pas appliqué.
     * Non verrouillé (les tests y posent une lecture simulée) : son `file_path` ne sert qu'à
     * `discard()`, qui n'efface que dans le dossier de l'utilisateur, et à un lien signé que
     * DocumentController contrôle. Le reste ne touche que les données de l'utilisateur.
     */
    public ?array $liasseAnalysis = null;

    public ?string $liasseError = null;

    /** Les lignes lues ont été recopiées dans l'éditeur (et l'étape 4 pré-remplie). */
    public bool $liasseApplied = false;

    /**
     * Refus de recopie pour débordement de la base amortissable, et de quoi le RÉPARER.
     *
     * ⚠️ Le refus lui-même est correct : rien n'est écrit, et ce n'est pas un faux positif.
     * Ce qui manquait, c'est le moyen d'agir — le message disait quoi faire (« vérifiez la
     * part du terrain et la valeur retenue, ou écartez les lignes qui ne portent pas sur le
     * bâti ») sans offrir aucun bouton pour le faire. `null` = pas de refus en cours.
     *
     * @var array{message: string, allocated: int, available: int}|null
     */
    public ?array $liasseOverflow = null;

    /**
     * Index des lignes lues que l'utilisateur a écartées avant recopie.
     *
     * Index dans `liasseResult()->rows`, donc dans l'ordre du 2033-C : c'est le seul
     * identifiant stable d'une ligne lue, qui n'existe nulle part en base.
     *
     * @var list<int>
     */
    public array $liasseExcludedRows = [];

    // Étape 4 — vos reports
    public ?string $openingDeferred = null;
    public ?string $openingAccumulated = null;
    /**
     * Case 044 : le TOTAL des immobilisations brutes, et non la seule case 028.
     *
     * ⚠️ Une question sur la case 014 vivait ici jusqu'au 2026-09-08, pour révéler le
     * comptable qui avait passé les frais d'acquisition en charges. Elle n'a plus d'objet :
     * nos frais capitalisés sont désormais corporels, donc notre 014 vaut zéro, et le total
     * 044 révèle le même cas sans dépendre d'une convention de présentation.
     */
    public ?string $declaredGrossAssets = null;

    /**
     * Résultat fiscal déclaré de la liasse reprise (2033-B : 352 positif, 354 négatif), en
     * centimes. Lu par l'IA, conservé sur l'exercice de reprise pour que « Revoir ma
     * reprise » le retrouve après un rechargement.
     */
    public ?int $declaredFiscalResult = null;

    /** @var list<array{origin_year: string|int|null, amount: string|null}> */
    public array $deficits = [];

    // Étape 5 — contrôle
    /** @var array<string, mixed>|null Rapport de `ReprisesCheckService`. */
    public ?array $report = null;

    // -------------------------------------------------------------------------
    // Amorçage
    // -------------------------------------------------------------------------

    public function mount(): void
    {
        $property = Property::orderBy('id')->first();

        $this->firstYear = (int) date('Y');

        if ($property) {
            $this->fillFromProperty($property);
        } else {
            $this->acquisitionFeesDuration = (string) Property::ACQUISITION_FEES_DEFAULT_DURATION;
        }

        $this->restoreFromFinishedReprise();
    }

    /**
     * Réveille l'écran final après un rechargement de page.
     *
     * ⚠️ Rien n'est persisté avant « Terminer la reprise » (voir le docblock de la classe)
     * — mais UNE FOIS ce bouton cliqué, un exercice existe bel et bien en base, avec ses
     * soldes d'ouverture. Sans ce réveil, chaque nouveau montage du composant (recharger la
     * page, rouvrir l'onglet, revenir par l'historique du navigateur) repartait de zéro :
     * la méthode choisie et les reports saisis disparaissaient, et « Revoir ma reprise »
     * retombait sur un formulaire vide qui ne reflétait plus ce qui avait été repris.
     * Constaté en rejouant un dossier de test le 2026-10-15 — le symptôme se voit AU CLIC
     * sur ce bouton, mais la perte a lieu avant, au montage.
     *
     * `opening_source` n'est écrit QUE par cette page (`applyOpeningBalances()`) : un
     * exercice qui le porte est donc, à coup sûr, celui d'une reprise terminée ici, jamais
     * un exercice ordinaire créé depuis l'assistant annuel.
     */
    private function restoreFromFinishedReprise(): void
    {
        if ($this->propertyId === null) {
            return;
        }

        $fiscalYear = FiscalYear::withoutGlobalScopes()
            ->where('user_id', auth()->id())
            ->whereNotNull('opening_source')
            ->orderByDesc('year')
            ->first();

        if ($fiscalYear === null) {
            return;
        }

        $this->firstYear = (int) $fiscalYear->year;
        $this->openingDeferred = self::eurosFromCents((int) $fiscalYear->opening_deferred_depreciation);
        $this->openingAccumulated = self::eurosFromCents((int) $fiscalYear->opening_accumulated_depreciation);
        $this->deficits = array_map(fn (array $deficit) => [
            'origin_year' => $deficit['origin_year'],
            'amount' => self::eurosFromCents((int) $deficit['amount']),
        ], $fiscalYear->opening_deficits ?? []);

        // La case 044 et le résultat déclaré ne servent qu'au contrôle de l'étape 5, mais
        // « Revoir ma reprise » doit les retrouver : ils sont conservés sur l'exercice depuis
        // le 2026-10-15 (ils revenaient « non renseigné » après un rechargement).
        $this->declaredGrossAssets = $fiscalYear->opening_declared_gross_assets === null
            ? null
            : self::eurosFromCents((int) $fiscalYear->opening_declared_gross_assets);
        $this->declaredFiscalResult = $fiscalYear->opening_declared_result === null
            ? null
            : (int) $fiscalYear->opening_declared_result;

        // La méthode (COPY/SPREAD/AI) n'est pas persistée telle quelle : COPY et SPREAD
        // écrivent tous deux `OPENING_SOURCE_MANUAL`. On ne peut plus les distinguer après
        // coup, mais les deux rouvrent le même éditeur partagé en mode « Montants » dès que
        // `method !== SPREAD` (`editorInitialMode()`) — reprendre `METHOD_COPY` par défaut
        // n'altère donc pas ce qui s'affiche.
        $this->method = $fiscalYear->opening_source === FiscalYear::OPENING_SOURCE_AI
            ? self::METHOD_AI
            : self::METHOD_COPY;

        // Le plan lu a déjà été recopié : l'étape 3 doit montrer l'éditeur, pas un nouveau
        // dépôt de liasse vide.
        $this->liasseApplied = $this->method === self::METHOD_AI;

        $this->finished = true;
        $this->step = 5;
    }

    private function fillFromProperty(Property $property): void
    {
        $this->propertyId = $property->id;
        $this->propertyName = (string) $property->name;
        $this->propertyAddress = (string) $property->address;
        $this->propertyCity = (string) $property->city;
        $this->propertyPostalCode = (string) $property->postal_code;
        $this->propertyArea = (string) $property->total_area;
        $this->acquisitionDate = self::displayDate($property->acquisition_date);
        $this->rentalStartDate = self::displayDate($property->rental_start_date);
        $this->acquisitionPrice = self::eurosFromCents((int) $property->acquisition_price);
        $this->notaryFees = self::eurosFromCents((int) $property->notary_fees);
        $this->agencyFees = self::eurosFromCents((int) $property->agency_fees);
        $this->landPercentage = (string) $property->land_percentage;
        $this->acquisitionFeesTreatment = $property->acquisition_fees_treatment
            ?? Property::ACQUISITION_FEES_AMORTIZED;
        $this->acquisitionFeesDuration = (string) $property->acquisitionFeesDurationYears();
    }

    // -------------------------------------------------------------------------
    // Conversions — le piège des montants ×100
    // -------------------------------------------------------------------------

    /**
     * Euros saisis → centimes, en bcmath.
     *
     * Rend `null` pour une saisie vide ou illisible : une case vide de la liasse ne doit
     * pas être comparée (`ReprisesCheckService` la laisse `unchecked`), et surtout pas
     * valoir zéro. Les espaces de milliers, l'espace insécable, le symbole € et la virgule
     * décimale sont acceptés : c'est ce qu'on recopie d'une liasse.
     */
    public static function centsFromEuros(mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        $value = str_replace(["\u{202F}", "\u{00A0}", ' ', '€'], '', (string) $raw);
        $value = str_replace(',', '.', trim($value));

        if ($value === '' || ! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return null;
        }

        $negative = str_starts_with($value, '-');
        $absolute = ltrim($value, '-');

        // bcmul TRONQUE : le demi-centime ajouté avant la troncature rend un arrondi
        // au centime le plus proche, sans jamais passer par un float.
        $cents = bcadd(bcmul($absolute, '100', 4), '0.5', 0);

        return (int) ($negative ? '-' . $cents : $cents);
    }

    /** Centimes → euros affichables dans un champ de saisie (jamais de séparateur). */
    public static function eurosFromCents(?int $cents): ?string
    {
        if ($cents === null) {
            return null;
        }

        $euros = bcdiv((string) $cents, '100', 2);

        // « 200000.00 » se relit mal dans un champ : on retire les centimes nuls.
        return str_ends_with($euros, '.00') ? substr($euros, 0, -3) : $euros;
    }

    /** Montant formaté pour l'affichage (1 234 €). */
    public function formatEuros(?int $cents): string
    {
        if ($cents === null) {
            return '—';
        }

        return number_format($cents / 100, 0, ',', ' ') . ' €';
    }

    /**
     * Montant formaté AVEC ses centimes quand il en a (170 000,85 €, mais 1 234 €).
     *
     * Pour les écrans où l'écart se joue à moins d'un euro : arrondis à l'euro, « 170 001 €
     * répartis pour 170 001 € disponibles » et « excédent : 0 € » ne disaient plus rien.
     */
    public static function formatCents(int $cents): string
    {
        $decimals = $cents % 100 === 0 ? 0 : 2;

        return number_format($cents / 100, $decimals, ',', ' ') . ' €';
    }

    /** Écart signé formaté (+ 4 213 €). */
    public function formatSignedEuros(?int $cents): string
    {
        if ($cents === null) {
            return '—';
        }

        // Un écart de quelques centimes s'arrondit à zéro à l'affichage : « + 0 € » se lit
        // comme un défaut d'affichage, alors que la ligne est justement déclarée identique.
        if (abs($cents) < 50) {
            return '0 €';
        }

        return ($cents > 0 ? '+ ' : '− ') . number_format(abs($cents) / 100, 0, ',', ' ') . ' €';
    }

    // -------------------------------------------------------------------------
    // Libellés
    // -------------------------------------------------------------------------

    /** @return array<int, array{key: string, title: string}> */
    public function steps(): array
    {
        return [
            ['key' => 'situation', 'title' => 'Votre situation'],
            ['key' => 'property', 'title' => 'Votre bien'],
            ['key' => 'depreciation', 'title' => 'Vos amortissements'],
            ['key' => 'carryforwards', 'title' => 'Vos reports'],
            ['key' => 'check', 'title' => 'Contrôle'],
        ];
    }

    /** @return array<string, string> */
    public static function regimeLabels(): array
    {
        return [
            self::REGIME_SINCE_START => 'Oui, depuis le début',
            self::REGIME_SINCE_YEAR => 'Oui, depuis une autre année',
            self::REGIME_NEW => 'Non, je passe au réel cette année',
        ];
    }

    // -------------------------------------------------------------------------
    // Navigation entre étapes
    // -------------------------------------------------------------------------

    public function nextStep(): void
    {
        if (! $this->validateStep($this->step)) {
            return;
        }

        if ($this->step === 2) {
            $this->persistProperty();
        }

        if ($this->step === 3) {
            $this->boundOpeningCumuls();
        }

        if ($this->step === 4) {
            // Rappelée ici, pas seulement à la sortie de l'étape 3 : le lien vers les
            // écrans « Travaux »/« Mobilier » reste visible à l'étape 3, et rien n'empêche
            // d'y revenir après l'avoir déjà quittée (bouton « Retour »). Idempotente.
            $this->boundOpeningCumuls();
            $this->runCheck();
        }

        $this->step = min(5, $this->step + 1);
    }

    public function previousStep(): void
    {
        $this->stepErrors = [];
        $this->step = max(1, $this->step - 1);
    }

    /**
     * Retour direct sur une étape déjà franchie.
     *
     * ⚠️ Méthode Livewire publique : le navigateur peut demander n'importe quel numéro.
     * On n'autorise que le RETOUR — sauter en avant contournerait la validation.
     */
    public function goToStep(int $step): void
    {
        if ($step < 1 || $step >= $this->step) {
            return;
        }

        $this->stepErrors = [];
        $this->step = $step;
    }

    // -------------------------------------------------------------------------
    // Validation, étape par étape
    // -------------------------------------------------------------------------

    private function validateStep(int $step): bool
    {
        $this->stepErrors = match ($step) {
            1 => $this->validateSituation(),
            2 => $this->validateProperty(),
            3 => $this->validateDepreciation(),
            4 => $this->validateCarryforwards(),
            default => [],
        };

        return $this->stepErrors === [];
    }

    /** @return array<string, string> */
    private function validateSituation(): array
    {
        $errors = [];

        $start = $this->parseDate($this->rentalStartDate);

        if ($start === null) {
            $errors['rentalStartDate'] = 'Indiquez la date de mise en location.';
        } elseif ($start->year > (int) date('Y')) {
            $errors['rentalStartDate'] = 'La mise en location ne peut pas être dans le futur.';
        }

        $year = (int) $this->firstYear;

        if ($year < 2000 || $year > (int) date('Y') + 1) {
            $errors['firstYear'] = 'Choisissez une année entre 2000 et ' . ((int) date('Y') + 1) . '.';
        } elseif ($start !== null && $year < $start->year) {
            $errors['firstYear'] = 'Cette année précède la mise en location du bien ('
                . $start->year . ').';
        }

        if (! array_key_exists($this->regime, self::regimeLabels())) {
            $errors['regime'] = 'Choisissez une réponse.';
        }

        if ($this->regime === self::REGIME_SINCE_YEAR) {
            $since = (int) $this->regimeSinceYear;

            if ($since < 2000 || $since > (int) date('Y')) {
                $errors['regimeSinceYear'] = 'Indiquez l\'année de passage au régime réel.';
            }
        }

        return $errors;
    }

    /** @return array<string, string> */
    private function validateProperty(): array
    {
        $errors = [];

        if (self::centsFromEuros($this->acquisitionPrice) === null
            || self::centsFromEuros($this->acquisitionPrice) <= 0) {
            $errors['acquisitionPrice'] = 'Indiquez le prix d\'acquisition du bien.';
        }

        // ⚠️ `(float)` et non `(int)` : une part de terrain de 17,5 % tombait à 17, ce qui
        // déplace 875 € de base amortissable sur un bien à 175 000 € — davantage que les
        // écarts que l'écran de contrôle signale en rouge deux étapes plus loin.
        $land = $this->landPercentage === null || trim((string) $this->landPercentage) === ''
            ? null
            : (float) str_replace(',', '.', (string) $this->landPercentage);

        if ($land === null || $land < 0 || $land > 99) {
            $errors['landPercentage'] = 'La part du terrain doit être comprise entre 0 et 99 %.';
        }

        if (! array_key_exists($this->acquisitionFeesTreatment, Property::acquisitionFeesTreatmentLabels())) {
            $errors['acquisitionFeesTreatment'] = 'Choisissez un traitement des frais d\'acquisition.';
        }

        if ($this->acquisitionFeesTreatment === Property::ACQUISITION_FEES_AMORTIZED
            && (int) $this->acquisitionFeesDuration < 1) {
            $errors['acquisitionFeesDuration'] = 'Indiquez la durée d\'amortissement des frais.';
        }

        // Champs d'identité : demandés UNIQUEMENT quand le bien n'existe pas encore.
        // On ne peut pas inventer une adresse — mais on ne la redemande pas non plus à
        // quelqu'un qui a déjà saisi son bien.
        if ($this->propertyId === null) {
            foreach ([
                'propertyName' => 'Donnez un nom à ce bien.',
                'propertyAddress' => 'Indiquez l\'adresse.',
                'propertyCity' => 'Indiquez la ville.',
                'propertyPostalCode' => 'Indiquez le code postal.',
            ] as $field => $message) {
                if (trim((string) $this->{$field}) === '') {
                    $errors[$field] = $message;
                }
            }

            if ((int) $this->propertyArea < 1) {
                $errors['propertyArea'] = 'Indiquez la surface du bien, en m².';
            }
        }

        return $errors;
    }

    /** @return array<string, string> */
    private function validateDepreciation(): array
    {
        // La carte IA ne vaut méthode que si des lignes ont réellement été
        // recopiées : une lecture jamais appliquée laisserait le plan vide.
        if ($this->method === self::METHOD_AI) {
            return $this->liasseApplied
                ? []
                : ['method' => 'Lancez la lecture puis « Utiliser ces lignes », ou choisissez une autre méthode.'];
        }

        if (! in_array($this->method, [self::METHOD_COPY, self::METHOD_SPREAD], true)) {
            return ['method' => 'Choisissez comment reprendre votre plan d\'amortissement.'];
        }

        return [];
    }

    /** @return array<string, string> */
    private function validateCarryforwards(): array
    {
        $errors = [];

        foreach ([
            'openingDeferred' => 'Montant illisible (2033-D case 870).',
            'openingAccumulated' => 'Montant illisible (2033-A case 030).',
            'declaredGrossAssets' => 'Montant illisible (2033-A case 044).',
        ] as $field => $message) {
            $raw = trim((string) $this->{$field});

            if ($raw !== '' && self::centsFromEuros($raw) === null) {
                $errors[$field] = $message;
            }
        }

        foreach ($this->deficits as $index => $deficit) {
            $year = (int) ($deficit['origin_year'] ?? 0);
            $amount = self::centsFromEuros($deficit['amount'] ?? null);

            if ($year < 2000 || $year > (int) date('Y')) {
                $errors['deficits.' . $index] = 'Année d\'origine invalide.';
            } elseif ($amount === null || $amount <= 0) {
                $errors['deficits.' . $index] = 'Montant du déficit invalide.';
            }
        }

        return $errors;
    }

    /**
     * Lit une date saisie.
     *
     * ⚠️ `Carbon::parse('01/06/2019')` rend le **6 janvier** : `strtotime` lit les dates
     * séparées par des barres obliques à l'américaine. Sur un assistant où l'on recopie
     * « loué depuis le 01/06/2019 », l'erreur décalerait tout le prorata de première année
     * sans rien afficher d'anormal. Le format français est donc essayé EN PREMIER, et
     * explicitement.
     */
    private function parseDate(?string $raw): ?Carbon
    {
        $value = trim((string) ($raw ?? ''));

        if ($value === '') {
            return null;
        }

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $format) {
            try {
                // Carbon 3 LÈVE une exception au lieu de rendre `false` : sans ce try, une
                // saisie en cours (« 01/0 ») ferait planter le rendu de la page.
                $date = Carbon::createFromFormat($format, $value);
            } catch (\Throwable) {
                continue;
            }

            // Comparaison aller-retour : `createFromFormat` accepte « 32/13/2019 » en
            // débordant sur le mois suivant. On refuse ce que l'utilisateur n'a pas écrit.
            if ($date !== false && $date->format($format) === $value) {
                return $date->startOfDay();
            }
        }

        return null;
    }

    /**
     * Date d'un modèle, telle qu'elle doit s'afficher dans les champs de l'assistant.
     *
     * ⚠️ ISO 8601, et non `d/m/Y` : les champs de dates de la page sont des `type="date"`,
     * qui n'affichent RIEN d'une valeur écrite autrement — le champ serait vide alors que
     * la date existe en base, et repartirait vide à l'enregistrement. Le format d'affichage
     * du navigateur reste le format local, c'est lui qui s'en charge.
     */
    private static function displayDate(?\Carbon\CarbonInterface $date): ?string
    {
        return $date?->format('Y-m-d');
    }

    // -------------------------------------------------------------------------
    // Étape 2 — enregistrement du bien
    // -------------------------------------------------------------------------

    /**
     * Borne les cumuls repris sur le dernier exercice que le cabinet a tenu.
     *
     * ⚠️ Sans cette borne, le cumul repris s'ajoutait à un rejeu portant sur LES MÊMES
     * exercices : la case 030 valait le double. Un utilisateur l'a signalé le 2026-09-09,
     * chiffres à l'appui — 9 496 € affichés pour 4 736 € déclarés.
     *
     * L'assistant est le seul endroit qui connaisse la réponse sans avoir à la demander :
     * `firstYear` est l'exercice que l'utilisateur reprend, donc son cumul d'ouverture
     * s'arrête à la clôture de l'année précédente. On ne l'écrit que là où elle manque —
     * une borne saisie à la main, fût-elle différente, appartient à l'utilisateur.
     *
     * ⚠️ Couvre aussi les travaux et le mobilier, pas seulement les composants du bien.
     * Leurs propres formulaires (`PropertyWorkForm`/`FurnitureForm`, section « Reprise
     * d'une comptabilité existante ») acceptent un cumul déjà pratiqué sans jamais
     * demander la borne qui l'accompagne — ils n'ont pas de notion d'exercice de reprise,
     * eux. Le lien posé à l'étape 3 (« Saisir mes travaux » / « Saisir mon mobilier »)
     * mène vers ces écrans PENDANT le parcours : sans ce même bornage ici, un cumul
     * saisi là-bas rejouerait exactement le même piège que celui des composants.
     * Idempotent (`whereNull`) : rappelée à chaque passage, elle ne touche jamais une
     * borne déjà posée, ni par l'utilisateur, ni par un passage précédent.
     */
    private function boundOpeningCumuls(): void
    {
        if (! $this->propertyId || ! $this->firstYear) {
            return;
        }

        $property = Property::find($this->propertyId);

        if ($property === null) {
            return;
        }

        $openingYear = (int) $this->firstYear - 1;

        foreach ([$property->components(), $property->works(), $property->furniture()] as $relation) {
            $relation->where('opening_accumulated_depreciation', '>', 0)
                ->whereNull('opening_accumulated_year')
                ->update(['opening_accumulated_year' => $openingYear]);
        }
    }

    private function persistProperty(): void
    {
        $rentalStart = $this->parseDate($this->rentalStartDate)?->format('Y-m-d');
        $acquisition = $this->parseDate($this->acquisitionDate)?->format('Y-m-d') ?? $rentalStart;

        $attributes = [
            'acquisition_price' => self::centsFromEuros($this->acquisitionPrice) ?? 0,
            'notary_fees' => self::centsFromEuros($this->notaryFees) ?? 0,
            'agency_fees' => self::centsFromEuros($this->agencyFees) ?? 0,
            'land_percentage' => (float) str_replace(',', '.', (string) $this->landPercentage),
            'rental_start_date' => $rentalStart,
            'acquisition_date' => $acquisition,
            'acquisition_fees_treatment' => $this->acquisitionFeesTreatment,
            'acquisition_fees_duration' => max(1, (int) $this->acquisitionFeesDuration),
        ];

        if ($this->propertyId !== null) {
            // `find()` passe par le scope global : un identifiant posé depuis le navigateur
            // ne peut pas désigner le bien d'un autre compte.
            $property = Property::find($this->propertyId);

            if ($property === null) {
                $this->propertyId = null;
            } else {
                $property->update($attributes);

                return;
            }
        }

        $area = max(1, (int) $this->propertyArea);

        $property = Property::create($attributes + [
            'user_id' => auth()->id(),
            'name' => trim($this->propertyName),
            'address' => trim($this->propertyAddress),
            'city' => trim($this->propertyCity),
            'postal_code' => trim($this->propertyPostalCode),
            // Une reprise porte presque toujours sur un bien loué en entier. La quote-part
            // se règle finement dans « Mes biens » — la demander ici alourdirait l'étape 2
            // pour un cas minoritaire.
            'total_area' => $area,
            'rented_area' => $area,
        ]);

        $this->propertyId = $property->id;
    }

    // -------------------------------------------------------------------------
    // Étape 3 — choix de la méthode
    // -------------------------------------------------------------------------

    /**
     * Applique la méthode choisie.
     *
     * ⚠️ Méthode Livewire publique : la valeur vient du navigateur et n'est acceptée que
     * si elle fait partie des deux méthodes connues.
     */
    public function chooseMethod(string $method): void
    {
        if (! in_array($method, [self::METHOD_COPY, self::METHOD_SPREAD, self::METHOD_AI], true)) {
            return;
        }

        // La carte IA ne s'active que si la fonction est réellement disponible.
        if ($method === self::METHOD_AI && (! config('ai.enabled') || $this->liasseUnavailableMessage() !== null)) {
            return;
        }

        $this->method = $method;
        $this->stepErrors = [];

        $property = $this->propertyId ? Property::find($this->propertyId) : null;

        if ($property === null) {
            return;
        }

        if ($method === self::METHOD_SPREAD) {
            // « Répartir automatiquement » : exactement le comportement du bouton
            // « Réinitialiser par défaut » de l'éditeur — la ventilation standard.
            PropertyComponent::where('property_id', $property->id)->delete();
            app(DepreciationService::class)->generateDefaultComponents($property);
        }

        unset($this->editorData);
    }

    /** Mode d'ouverture de l'éditeur partagé, déduit de la méthode choisie. */
    public function editorInitialMode(): string
    {
        // COPY comme AI ouvrent en saisie de montants : les chiffres viennent de
        // la liasse, pas d'une ventilation en pourcentages.
        return $this->method === self::METHOD_SPREAD ? 'ventilation' : 'amounts';
    }

    // -------------------------------------------------------------------------
    // Étape 3 — lecture IA de la liasse (lot 6)
    // -------------------------------------------------------------------------

    /** La carte IA s'affiche-t-elle ? (interrupteur d'instance seulement). */
    public function aiCardVisible(): bool
    {
        return (bool) config('ai.enabled');
    }

    /**
     * Pourquoi l'analyse est indisponible pour CE compte — ou null si elle est
     * possible. Affichage seulement : rien n'est journalisé ici (le refus de
     * quota n'est compté qu'à une vraie tentative, comme sur la page d'import).
     */
    public function liasseUnavailableMessage(): ?string
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $access = AiAccess::for($user);

        if (! $access->available()) {
            return $access->unavailableMessage();
        }

        if (! AiQuota::for($user, AiQuota::FEATURE_DOCUMENTS)->allowed()) {
            return AiQuota::for($user, AiQuota::FEATURE_DOCUMENTS)->exhaustedMessage();
        }

        return null;
    }

    /**
     * Nom du fichier actuellement retenu pour la lecture, ou chaîne vide.
     *
     * ⚠️ Le cadre de dépôt masque l'`<input type="file">` (opacité nulle), donc le rendu
     * natif « Aucun fichier choisi / liasse-2025.pdf » du navigateur disparaît avec lui :
     * c'est la vue qui doit réafficher ce nom. Alpine le pose au `change`, mais son état
     * repart de zéro après un aller-retour Livewire alors que le fichier reste téléversé —
     * d'où cette valeur, rendue par le serveur, qui réamorce le composant.
     */
    public function liasseFileName(): string
    {
        if ($this->liasseFile === null) {
            return '';
        }

        // Un fichier téléversé par Livewire porte son nom d'origine ; toute autre valeur
        // (chaîne posée depuis le navigateur) ne doit rien afficher.
        return method_exists($this->liasseFile, 'getClientOriginalName')
            ? (string) $this->liasseFile->getClientOriginalName()
            : '';
    }

    /**
     * Astuces montrées à tour de rôle pendant l'attente de la lecture.
     *
     * ⚠️ Elles remplacent une liste d'étapes qui était SIMULÉE : « Document transmis au
     * modèle », « Tableaux 2033-A à 2033-D repérés »… s'allumaient sur une minuterie de
     * 4 secondes, sans le moindre rapport avec le serveur (la quatrième ligne n'avait même
     * aucune liaison et ne s'allumait jamais). Un avancement réel demanderait du streaming
     * Livewire, donc un traitement hors requête — or `QUEUE_CONNECTION=sync` et l'instance
     * n'a aucun worker. Plutôt qu'un faux avancement affirmé dans une modale centrée, on
     * affiche une attente franche et des astuces qui, elles, sont vraies.
     *
     * Une ligne chacune, et chacune doit APPRENDRE quelque chose sur la reprise : meubler
     * l'attente avec des généralités coûterait la même seconde d'attention sans rien rendre.
     *
     * @return list<string>
     */
    public function liasseWaitTips(): array
    {
        return [
            'La ligne 572 de votre 2033-C porte le total des dotations : c\'est elle qui dira si le tableau a été lu en entier.',
            'Un terrain ne s\'amortit jamais : sa ligne du 2033-C ne deviendra pas un composant.',
            'Vos frais d\'acquisition sont déjà amortis par l\'application : leur ligne ne sera pas recopiée une seconde fois.',
            'Une case vide sur votre liasse restera vide ici — jamais un zéro qui ressemblerait à un montant lu.',
            'Le cumul repris s\'arrête au dernier exercice tenu par votre cabinet : sans cette borne, la case 030 compte double.',
            'Différé n\'est pas déficit : le premier se reporte sans limite de durée, le second s\'éteint au bout de dix ans.',
            'Rien ne sera écrit avant votre relecture : la lecture n\'est qu\'une proposition, ligne à ligne.',
        ];
    }

    public function analyzeLiasse(): void
    {
        $this->liasseError = null;

        if (! $this->aiCardVisible() || $this->liasseUnavailableMessage() !== null) {
            return;
        }

        // Consentement propre au parcours : la case cochée vaut acceptation, et
        // l'horodatage partagé (ai_consented_at) n'est posé qu'une seule fois.
        if (! $this->liasseConsent) {
            $this->liasseError = 'Cochez la case de consentement avant de lancer la lecture.';

            return;
        }

        $this->validate(
            ['liasseFile' => 'required|file|max:10240'],
            [
                'liasseFile.required' => 'Déposez d\'abord votre liasse (PDF ou photos).',
                'liasseFile.max' => 'La liasse ne doit pas dépasser 10 Mo.',
            ],
        );

        $property = $this->propertyId ? Property::find($this->propertyId) : null;

        if ($property === null) {
            $this->liasseError = 'Enregistrez d\'abord votre bien (étape 2).';

            return;
        }

        // Consentement du PRINCIPAL (plan des dossiers liés § 2.3), pas du dossier actif.
        $user = \App\Support\DossierContext::principal() ?? auth()->user();

        if (blank($user->ai_consented_at)) {
            $user->forceFill(['ai_consented_at' => now()])->save();
        }

        $extension = strtolower($this->liasseFile->getClientOriginalExtension() ?: 'pdf');
        $path = $this->liasseFile->storeAs(
            (DocumentStorage::directory(AiStagingUpload::SUBDIRECTORY))(),
            'liasse-' . now()->format('Ymd-His') . '.' . $extension,
        );

        try {
            $result = app(DocumentAnalysisService::class)->analyze($path, $property, forcedType: 'liasse_2033');
        } catch (AiException | RuntimeException $exception) {
            app(DocumentAnalysisService::class)->discard($path);
            $this->liasseError = $exception->getMessage();

            return;
        }

        // withFile() : c'est l'appelant qui rattache le fichier au résultat
        // (même contrat que la page d'import) — sans lui, pas d'aperçu en
        // relecture, et discardLiasse() laisserait le dépôt orphelin.
        $this->liasseAnalysis = $result->withFile($path, basename($path))->toArray();
        $this->liasseApplied = false;
        $this->liasseFile = null;
        // Une lecture neuve efface le refus de la précédente et ses exclusions : les index
        // portent sur les lignes de l'ANCIENNE liasse et ne veulent plus rien dire.
        $this->liasseOverflow = null;
        $this->liasseExcludedRows = [];
    }

    public function liasseResult(): AnalysisResult
    {
        return AnalysisResult::fromArray($this->liasseAnalysis ?? []);
    }

    /**
     * Contrôle de complétude : la somme des dotations lues retombe-t-elle sur la
     * ligne 572 ? true = tableau lu en entier ; false = une ligne manque ou a été
     * mal lue ; null = la ligne 572 n'a pas été lue, rien à comparer.
     */
    public function liasseCoherent(): ?bool
    {
        if ($this->liasseAnalysis === null) {
            return null;
        }

        $result = $this->liasseResult();
        $declared = $result->values['declared_total_depreciation'] ?? null;

        if ($declared === null || $result->rows === []) {
            return null;
        }

        $sum = array_sum(array_map(fn (array $row) => (int) ($row['annual'] ?? 0), $result->rows));

        return abs($sum - (int) $declared) <= 100;
    }

    /** URL signée temporaire du document déposé (relecture : liasse à gauche, lecture à droite). */
    public function liassePreviewUrl(): ?string
    {
        return DocumentStorage::temporaryUrl($this->liasseAnalysis['file_path'] ?? null, 15);
    }

    /** Abandonne la lecture : le fichier déposé est supprimé, rien n'a été écrit. */
    public function discardLiasse(): void
    {
        app(DocumentAnalysisService::class)->discard($this->liasseAnalysis['file_path'] ?? null);

        $this->liasseAnalysis = null;
        $this->liasseApplied = false;
        $this->liasseError = null;
        $this->liasseOverflow = null;
        $this->liasseExcludedRows = [];
    }

    /**
     * Recopie les lignes RELUES dans l'éditeur d'amortissements et pré-remplit
     * l'étape 4. C'est la seule écriture du parcours IA, et elle passe par
     * saveComponents() : mêmes validations que la saisie à la main.
     *
     * ⚠️ CONVENTION (voir `Property::getDepreciableBaseAttribute()`) : chaque base est
     * copiée TELLE QUELLE dans `base_amount`, jamais re-multipliée par `quota_share`. Pour un
     * bien partiellement loué, le 2033-C du cabinet porte déjà la part professionnelle de
     * l'immobilisation, pas la valeur du bien entier — la quote-part y est déjà. `percentage`
     * n'est recalculé qu'à l'AFFICHAGE (`DepreciationService::syncComponents()`), jamais
     * réinjecté dans une base.
     */
    /**
     * Classe les lignes lues : ce qui devient un composant, ce qui est écarté, et pourquoi.
     *
     * ⚠️ SOURCE UNIQUE, appelée par `applyLiasse()` (qui écrit) ET par la modale de refus
     * (qui affiche les lignes à décocher). Deux parcours de la même liste finiraient par
     * diverger, et la modale proposerait alors d'écarter des lignes qui ne comptent pas —
     * ou tairait celles qui font déborder la base.
     *
     * @return array{
     *     lines: list<array<string, mixed>>,
     *     candidates: list<array{index: int, label: string, base: int, excluded: bool}>,
     *     fees: list<string>,
     *     land: list<string>,
     *     skipped: int,
     * }
     */
    private function classifyLiasseRows(): array
    {
        $lines = [];
        $candidates = [];
        $fees = [];
        $land = [];
        $skipped = 0;
        $sort = 0;

        foreach ($this->liasseResult()->rows as $index => $row) {
            // Un terrain ne s'amortit JAMAIS : le recopier créerait un composant portant
            // une durée et une dotation fictives (constaté lors d'un test de reprise : 10 ans par
            // défaut, faute de durée imprimée sur la liasse pour une ligne qui n'en a pas).
            // La base amortissable exclut déjà le terrain séparément, via `land_percentage`
            // sur le bien — voir le docblock de `CERFA_CATEGORY_LAND`. Refusée ICI, à
            // l'import, pas seulement masquée à l'affichage : sinon la ligne reste en base,
            // gonfle la ventilation et fausse la case 470/560 du 2033-C.
            if (($row['cerfa_category'] ?? null) === PropertyComponent::CERFA_CATEGORY_LAND) {
                $land[] = (string) $row['label'];

                continue;
            }

            // Les frais d'acquisition (incorporelles) sont déjà amortis par
            // l'application à partir des frais saisis à l'étape 2 : les recopier
            // les compterait deux fois — et ferait déborder la base amortissable.
            if (($row['cerfa_category'] ?? null) === PropertyComponent::CERFA_CATEGORY_INTANGIBLE) {
                $fees[] = (string) $row['label'];

                continue;
            }

            // Absente OU nulle : une base à zéro produirait un composant vide
            // dans le plan (syncComponents n'a pas le filtre de la grille).
            if ((int) ($row['base'] ?? 0) <= 0) {
                $skipped++;

                continue;
            }

            $excluded = in_array((int) $index, $this->liasseExcludedRows, true);

            $candidates[] = [
                'index' => (int) $index,
                'label' => (string) $row['label'],
                'base' => (int) $row['base'],
                'excluded' => $excluded,
            ];

            if ($excluded) {
                continue;
            }

            $lines[] = [
                'id' => null,
                'name' => (string) $row['label'],
                'duration_years' => (int) ($row['duration_years'] ?? 10),
                'sort_order' => $sort++,
                'base_source' => PropertyComponent::BASE_SOURCE_MANUAL,
                'base_amount' => (int) $row['base'],
                // La dotation du cabinet est recopiée telle quelle : son arrondi
                // n'est pas forcément le nôtre, et c'est voulu.
                'annual_depreciation' => $row['annual'] !== null ? (int) $row['annual'] : null,
                // Rubrique d'origine de la ligne. Une lecture faite avant le 2026-10-15 a pu
                // la ranger en « autres » faute de reconnaître l'intitulé : on la redéduit.
                'cerfa_category' => in_array($row['cerfa_category'] ?? null, [null, PropertyComponent::CERFA_CATEGORY_OTHER], true)
                    ? PropertyComponent::cerfaCategoryForLiasseLabel((string) $row['label'])
                    : $row['cerfa_category'],
                'opening_accumulated_depreciation' => $row['cumul'] !== null ? (int) $row['cumul'] : null,
            ];
        }

        return [
            'lines' => $lines,
            'candidates' => $candidates,
            'fees' => $fees,
            'land' => $land,
            'skipped' => $skipped,
        ];
    }

    /**
     * Case 044 (TOTAL des immobilisations brutes) tirée d'une lecture de liasse.
     *
     * ⚠️ Jusqu'au 2026-10-15, le champ de la case 044 recevait la case 028 lue : un faux
     * écart au contrôle, égal aux immobilisations incorporelles (les frais d'acquisition
     * rangés en 014 par le cabinet). La case 044 lue fait foi ; à défaut, 028 + 014, la
     * case 014 vide valant zéro sur une liasse (il n'y a alors aucune incorporelle).
     *
     * @param  array<string, mixed>  $values
     */
    public static function liasseTotalGrossAssets(array $values): ?int
    {
        if (($values['total_gross_assets'] ?? null) !== null) {
            return (int) $values['total_gross_assets'];
        }

        if (($values['gross_assets'] ?? null) === null) {
            return null;
        }

        return (int) $values['gross_assets'] + (int) ($values['intangible_assets'] ?? 0);
    }

    /**
     * Tolérance d'arrondi d'une recopie de liasse, en centimes : moins d'un euro par ligne.
     *
     * Une liasse arrondit chaque ligne à l'euro, alors que la base amortissable (prix moins
     * la part du terrain) tombe rarement sur un euro rond : la ligne recopiée peut la dépasser
     * de quelques centimes sans que rien soit faux. Refusée au centime jusqu'au 2026-10-15,
     * la recopie affichait alors « 0 € » d'excédent à résorber, un refus incompréhensible.
     */
    public static function liasseRoundingTolerance(int $lineCount): int
    {
        return max(0, 100 * $lineCount - 1);
    }

    /**
     * Retire de la plus grosse ligne lue l'excédent dû à l'arrondi à l'euro de la liasse.
     *
     * Au-delà de la tolérance, rien n'est touché : un vrai débordement (terrain, valeur
     * retenue, lignes hors bâti) doit être refusé et corrigé par l'utilisateur.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{excess: int, line: string}|null
     */
    private function absorbLiasseRounding(array &$lines, int $depreciableBase): ?array
    {
        $excess = array_sum(array_map(fn (array $line) => (int) $line['base_amount'], $lines)) - $depreciableBase;

        if ($excess <= 0 || $excess > self::liasseRoundingTolerance(count($lines))) {
            return null;
        }

        $largest = 0;
        foreach ($lines as $index => $line) {
            if ((int) $line['base_amount'] > (int) $lines[$largest]['base_amount']) {
                $largest = $index;
            }
        }

        $lines[$largest]['base_amount'] = (int) $lines[$largest]['base_amount'] - $excess;

        return ['excess' => $excess, 'line' => (string) $lines[$largest]['name']];
    }

    /** Excédent de la sélection sur la base, et s'il reste sous la tolérance d'arrondi. */
    public function liasseExcessCents(): int
    {
        $property = $this->propertyId ? Property::find($this->propertyId) : null;

        return $this->liasseSelectedTotal() - ($property ? (int) $property->depreciable_base : $this->depreciableBaseCents());
    }

    public function liasseExcessAbsorbable(): bool
    {
        $selected = count(array_filter($this->liasseCandidateRows(), fn (array $row) => ! $row['excluded']));

        return $this->liasseExcessCents() <= self::liasseRoundingTolerance($selected);
    }

    /**
     * Lignes lues qu'on s'apprête à recopier, avec leur état de sélection.
     *
     * @return list<array{index: int, label: string, base: int, excluded: bool}>
     */
    public function liasseCandidateRows(): array
    {
        return $this->liasseAnalysis === null ? [] : $this->classifyLiasseRows()['candidates'];
    }

    /** Somme des lignes encore cochées, en centimes — recalculée à chaque décochage. */
    public function liasseSelectedTotal(): int
    {
        return array_sum(array_map(
            fn (array $row) => $row['excluded'] ? 0 : $row['base'],
            $this->liasseCandidateRows(),
        ));
    }

    /**
     * Écarte (ou reprend) une ligne lue depuis la modale de refus.
     *
     * ⚠️ Méthode Livewire publique : l'index vient du navigateur. Il n'est retenu que s'il
     * désigne une ligne réellement candidate — sinon une valeur forgée gonflerait la liste
     * d'exclusions sans jamais correspondre à rien.
     */
    public function toggleLiasseRow(int $index): void
    {
        $candidats = array_column($this->liasseCandidateRows(), 'index');

        if (! in_array($index, $candidats, true)) {
            return;
        }

        $this->liasseExcludedRows = in_array($index, $this->liasseExcludedRows, true)
            ? array_values(array_diff($this->liasseExcludedRows, [$index]))
            : [...$this->liasseExcludedRows, $index];
    }

    /**
     * Corrige la valeur retenue et la part du terrain SANS quitter l'écran, puis réessaie.
     *
     * C'est la première des trois sorties que le message de refus nommait sans les offrir.
     * La validation est celle de l'étape 2, restreinte aux deux champs de la modale : on ne
     * va pas reprocher une adresse manquante à quelqu'un qui corrige un pourcentage.
     */
    public function fixPropertyFromOverflow(): void
    {
        if ($this->liasseOverflow === null) {
            return;
        }

        $errors = array_intersect_key(
            $this->validateProperty(),
            array_flip(['acquisitionPrice', 'landPercentage']),
        );

        if ($errors !== []) {
            $this->stepErrors = $errors;

            return;
        }

        $this->stepErrors = [];
        $this->persistProperty();
        unset($this->editorData);

        // Réessai immédiat : si la base suffit désormais, la modale se referme d'elle-même.
        $this->applyLiasse();
    }

    /** Referme la modale sans rien changer : la lecture reste sous les yeux, intacte. */
    public function dismissLiasseOverflow(): void
    {
        $this->liasseOverflow = null;
    }

    /** Troisième sortie : revenir à l'étape 2 pour reprendre la fiche du bien en entier. */
    public function backToPropertyStep(): void
    {
        $this->liasseOverflow = null;
        $this->goToStep(2);
    }

    public function applyLiasse(): void
    {
        $this->liasseError = null;

        if ($this->liasseAnalysis === null) {
            return;
        }

        $property = $this->propertyId ? Property::find($this->propertyId) : null;

        if ($property === null) {
            $this->liasseError = 'Enregistrez d\'abord votre bien (étape 2).';

            return;
        }

        $result = $this->liasseResult();
        $classified = $this->classifyLiasseRows();
        $lines = $classified['lines'];
        $skipped = $classified['skipped'];
        $feesLeftAside = $classified['fees'];
        $landLeftAside = $classified['land'];

        if ($lines === []) {
            $this->liasseError = 'Aucune ligne exploitable : chaque ligne doit au moins porter une valeur brute.';

            return;
        }

        $rounding = $this->absorbLiasseRounding($lines, (int) $property->depreciable_base);

        // Mêmes règles que la saisie à la main (syncComponents) — mais l'échec
        // remonte ici : une ventilation qui déborde la base n'applique RIEN, et
        // l'utilisateur garde sa lecture sous les yeux pour corriger.
        try {
            app(DepreciationService::class)->syncComponents($property, $lines);
        } catch (RuntimeException $exception) {
            $this->liasseError = $exception->getMessage()
                . ' Vérifiez la part du terrain et la valeur retenue (étape 2), ou écartez les lignes qui ne portent pas sur le bâti.';

            // Le refus devient ACTIONNABLE : la modale porte les deux corrections que
            // le message nomme, et le troisième recours (revenir à l'étape 2).
            $this->liasseOverflow = [
                'message' => $exception->getMessage(),
                'allocated' => array_sum(array_map(
                    fn (array $line) => (int) $line['base_amount'],
                    $lines,
                )),
                'available' => (int) $property->fresh()->depreciable_base,
            ];

            return;
        }

        $this->liasseOverflow = null;

        if ($rounding !== null) {
            Notification::make()
                ->title('Arrondi à l\'euro de la liasse absorbé')
                ->body(sprintf(
                    'Votre liasse arrondit chaque ligne à l\'euro : %s ont été retirés de la ligne « %s » pour '
                    . 'qu\'elle tienne dans la base amortissable. La dotation lue est conservée telle quelle.',
                    self::formatCents($rounding['excess']),
                    $rounding['line'],
                ))
                ->info()
                ->send();
        }

        $this->method = self::METHOD_AI;
        $this->stepErrors = [];
        unset($this->editorData);
        $this->dispatch('components-loaded', data: $this->editorData);

        if ($feesLeftAside !== []) {
            Notification::make()
                ->title('Frais d\'acquisition non recopiés : ' . implode(', ', $feesLeftAside))
                ->body('L\'application les amortit déjà à partir des frais saisis à l\'étape 2 — les recopier les compterait deux fois.')
                ->info()
                ->send();
        }

        if ($landLeftAside !== []) {
            Notification::make()
                ->title('Terrain non recopié : ' . implode(', ', $landLeftAside))
                ->body('Un terrain ne s\'amortit jamais. Sa part est déjà exclue de la base amortissable '
                    . 'par le pourcentage de terrain renseigné à l\'étape 2 — vérifiez qu\'il correspond '
                    . 'bien à celui de la liasse du cabinet.')
                ->warning()
                ->send();
        }

        // Étape 4 : uniquement les cases effectivement lues — une case absente
        // de la liasse reste absente du formulaire, jamais remplacée par zéro.
        $values = $result->values;

        if (($values['deferred_depreciation'] ?? null) !== null) {
            $this->openingDeferred = self::eurosFromCents((int) $values['deferred_depreciation']);
        }

        if (($values['accumulated_depreciation'] ?? null) !== null) {
            $this->openingAccumulated = self::eurosFromCents((int) $values['accumulated_depreciation']);
        }

        $this->declaredFiscalResult = ($values['fiscal_result_profit'] ?? null) !== null
            ? (int) $values['fiscal_result_profit']
            : (($values['fiscal_result_deficit'] ?? null) !== null ? -(int) $values['fiscal_result_deficit'] : null);

        $totalGrossAssets = self::liasseTotalGrossAssets($values);

        if ($totalGrossAssets !== null) {
            $this->declaredGrossAssets = self::eurosFromCents($totalGrossAssets);
        }

        if (! empty($values['deficits'])) {
            $this->deficits = array_map(fn (array $deficit) => [
                'origin_year' => $deficit['origin_year'],
                'amount' => self::eurosFromCents((int) $deficit['amount']),
            ], $values['deficits']);
        }

        // La liasse dit sur quel exercice elle porte : la première année tenue
        // ici devrait être la suivante.
        $liasseYear = $values['fiscal_year'] ?? null;

        if ($liasseYear !== null && (int) $this->firstYear !== (int) $liasseYear + 1) {
            Notification::make()
                ->title('Vérifiez la première année (étape 1)')
                ->body(sprintf(
                    'La liasse porte sur l\'exercice %d : la première année tenue ici devrait être %d, or l\'étape 1 indique %s.',
                    $liasseYear,
                    $liasseYear + 1,
                    $this->firstYear,
                ))
                ->warning()
                ->send();
        }

        if ($skipped > 0) {
            Notification::make()
                ->title($skipped . ' ligne(s) sans valeur brute laissée(s) de côté')
                ->body('Ajoutez-les à la main dans la grille si elles doivent figurer au plan.')
                ->warning()
                ->send();
        }

        $this->liasseApplied = true;

        Notification::make()
            ->title('Lignes de la liasse recopiées')
            ->body('Relisez le plan ci-dessous : chaque chiffre reste modifiable, et l\'étape 4 est pré-remplie avec les cases lues.')
            ->success()
            ->send();
    }

    // -------------------------------------------------------------------------
    // Étape 4 — déficits reportables
    // -------------------------------------------------------------------------

    public function addDeficit(): void
    {
        $this->deficits[] = [
            'origin_year' => (int) $this->firstYear - 1,
            'amount' => null,
        ];
    }

    public function removeDeficit(int $index): void
    {
        unset($this->deficits[$index]);

        $this->deficits = array_values($this->deficits);
    }

    /**
     * Déficits d'ouverture au format attendu par `fiscal_years.opening_deficits`.
     *
     * @return list<array{origin_year: int, amount: int}>
     */
    private function openingDeficitsPayload(): array
    {
        $payload = [];

        foreach ($this->deficits as $deficit) {
            $year = (int) ($deficit['origin_year'] ?? 0);
            $amount = self::centsFromEuros($deficit['amount'] ?? null);

            if ($year < 2000 || $amount === null || $amount <= 0) {
                continue;
            }

            $payload[] = ['origin_year' => $year, 'amount' => $amount];
        }

        return $payload;
    }

    /** Dernière année d'imputation d'un déficit né en $originYear. */
    public function deficitExpiryYear(int $originYear): int
    {
        return $originYear + FiscalYear::DEFICIT_CARRYFORWARD_YEARS;
    }

    // -------------------------------------------------------------------------
    // Étape 5 — contrôle
    // -------------------------------------------------------------------------

    /**
     * Exercice de reprise, NON PERSISTÉ.
     *
     * Le contrôle doit pouvoir tourner (et se rejouer) sans rien écrire : un exercice créé
     * à l'étape 5 puis abandonné porterait des reports que toute la chaîne suivante lirait.
     */
    private function repriseFiscalYear(): FiscalYear
    {
        $fiscalYear = FiscalYear::withoutGlobalScopes()
            ->where('user_id', auth()->id())
            ->where('year', (int) $this->firstYear)
            ->first() ?? new FiscalYear(['year' => (int) $this->firstYear]);

        $fiscalYear->user_id = auth()->id();
        $fiscalYear->year = (int) $this->firstYear;
        $fiscalYear->fiscal_result = (int) ($fiscalYear->fiscal_result ?? 0);

        $this->applyOpeningBalances($fiscalYear);

        return $fiscalYear;
    }

    private function applyOpeningBalances(FiscalYear $fiscalYear): void
    {
        $fiscalYear->opening_deferred_depreciation = self::centsFromEuros($this->openingDeferred) ?? 0;
        $fiscalYear->opening_accumulated_depreciation = self::centsFromEuros($this->openingAccumulated) ?? 0;
        $fiscalYear->opening_deficits = $this->openingDeficitsPayload();
        $fiscalYear->opening_declared_gross_assets = self::centsFromEuros($this->declaredGrossAssets);
        $fiscalYear->opening_declared_result = $this->method === self::METHOD_AI && $this->liasseApplied
            ? $this->declaredFiscalResult
            : null;
        // « Lecture assistée » quand les soldes viennent de la liasse lue par
        // l'IA — même relus et corrigés, on garde la provenance.
        $fiscalYear->opening_source = $this->method === self::METHOD_AI
            ? FiscalYear::OPENING_SOURCE_AI
            : FiscalYear::OPENING_SOURCE_MANUAL;
    }

    public function runCheck(): void
    {
        $declared = [
            ReprisesCheckService::LINE_GROSS_ASSETS => self::centsFromEuros($this->declaredGrossAssets),
            ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION => self::centsFromEuros($this->openingAccumulated),
            ReprisesCheckService::LINE_DEFERRED_DEPRECIATION => self::centsFromEuros($this->openingDeferred),
            ReprisesCheckService::LINE_DEFICIT_CARRYFORWARD => $this->openingDeficitsPayload() === []
                ? null
                : array_sum(array_column($this->openingDeficitsPayload(), 'amount')),
        ];

        // Le résultat fiscal (2033-B cases 352/354) n'est lu que par l'IA : la
        // ligne reste informative, l'application ne le reconstitue pas.
        if ($this->method === self::METHOD_AI && $this->liasseApplied) {
            $declared[ReprisesCheckService::LINE_FISCAL_RESULT] = $this->declaredFiscalResult;
        }

        $this->report = app(ReprisesCheckService::class)->check($this->repriseFiscalYear(), $declared);
    }

    /**
     * Le contrôle établit-il que le cabinet a passé les frais d'acquisition en CHARGES ?
     *
     * C'est le seul cas où « passer mes frais en charges » est la bonne correction : le
     * total brut de la liasse (case 044) ne les contient pas, à leur montant près. Les
     * frais d'acquisition se déduisent soit en charges l'année de l'achat, soit en les
     * amortissant avec le bien ; on ne repasse jamais en charges des frais que la liasse
     * reprise amortit.
     *
     * ⚠️ Jusqu'au 2026-10-15 le bouton suivait la ligne 030 : un écart égal au cumul des
     * frais. Or c'est aussi l'écart d'un cabinet qui les amortit en 016 (incorporelles)
     * plutôt qu'en 030, cas fréquent. Le bouton y aurait retiré les frais des
     * immobilisations et supprimé leur dotation annuelle.
     */
    public function acquisitionFeesExpensedByFirm(): bool
    {
        foreach ($this->report['lines'] ?? [] as $line) {
            if ($line['key'] !== ReprisesCheckService::LINE_GROSS_ASSETS
                || $line['verdict'] === ReprisesCheckService::VERDICT_MATCH) {
                continue;
            }

            foreach ($line['diagnostics'] ?? [] as $diagnostic) {
                if ($diagnostic['code'] === 'acquisition_fees_expensed' && $diagnostic['corroborated']) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Bascule les frais d'acquisition en charges, puis rejoue le contrôle. */
    public function expenseAcquisitionFees(): void
    {
        // ⚠️ Méthode Livewire publique : le bouton n'est rendu que dans le seul cas juste,
        // mais rien n'empêche le navigateur de l'appeler. La règle se revérifie ici.
        if (! $this->acquisitionFeesExpensedByFirm()) {
            return;
        }

        $property = $this->propertyId ? Property::find($this->propertyId) : null;

        if ($property === null) {
            return;
        }

        $property->update([
            'acquisition_fees_treatment' => Property::ACQUISITION_FEES_EXPENSED,
        ]);

        $this->acquisitionFeesTreatment = Property::ACQUISITION_FEES_EXPENSED;

        unset($this->editorData);

        $this->runCheck();

        Notification::make()
            ->success()
            ->title('Frais d\'acquisition passés en charges')
            ->body('Le contrôle a été rejoué avec ce traitement.')
            ->send();
    }

    // -------------------------------------------------------------------------
    // Fin de la reprise
    // -------------------------------------------------------------------------

    public function finish(): void
    {
        if (! $this->validateStep(1) || ! $this->validateStep(4)) {
            $this->step = $this->stepErrors === [] ? $this->step : 1;

            return;
        }

        $year = (int) $this->firstYear;

        $existing = FiscalYear::withoutGlobalScopes()
            ->where('user_id', auth()->id())
            ->where('year', $year)
            ->first();

        if ($existing?->status === FiscalYear::STATUS_CLOSED) {
            Notification::make()
                ->warning()
                ->title('Exercice déjà clôturé')
                ->body("L'exercice {$year} est clôturé : ses reports sont figés. Rouvrez-le "
                    . 'depuis la liste des exercices avant de reprendre votre dossier.')
                ->persistent()
                ->send();

            return;
        }

        $fiscalYear = $existing ?? new FiscalYear([
            'year' => $year,
            'status' => FiscalYear::STATUS_DRAFT,
        ]);

        $fiscalYear->user_id = auth()->id();
        $fiscalYear->year = $year;
        $fiscalYear->status = $fiscalYear->status ?? FiscalYear::STATUS_DRAFT;

        $this->applyOpeningBalances($fiscalYear);

        $fiscalYear->save();

        // Le report d'ouverture n'entre dans la chaîne qu'une fois l'exercice calculé.
        app(FiscalYearService::class)->calculate($fiscalYear, force: true);

        $this->finished = true;

        Notification::make()
            ->success()
            ->title('Reprise enregistrée')
            ->body("Vos reports sont en place sur l'exercice {$year}.")
            ->send();
    }

    /** Retour au parcours depuis l'écran final (« Revoir ma reprise »). */
    public function reviewReprise(): void
    {
        $this->finished = false;
        $this->step = 5;
        $this->boundOpeningCumuls();
        $this->runCheck();
    }

    // -------------------------------------------------------------------------
    // Données d'affichage
    // -------------------------------------------------------------------------

    /** Base amortissable calculée, affichée à l'étape 2 (centimes). */
    public function depreciableBaseCents(): int
    {
        $price = self::centsFromEuros($this->acquisitionPrice) ?? 0;
        $land = max(0.0, min(99.0, (float) str_replace(',', '.', (string) $this->landPercentage)));

        return (int) bcdiv(bcmul((string) $price, (string) (100 - $land), 0), '100', 0);
    }

    /**
     * Récapitulatif de l'écran final.
     *
     * @return array<string, int|null>
     */
    public function recap(): array
    {
        $year = (int) $this->firstYear;
        $annual = 0;
        $cumul = 0;

        $depreciation = app(DepreciationService::class);

        foreach (Property::all() as $property) {
            $annual += (int) $depreciation->calculateAnnualDepreciation($property, $year)['total'];

            foreach ($depreciation->depreciationDetailForYear($property, $year - 1) as $line) {
                $cumul += (int) $line['cumul'];
            }
        }

        $deficits = $this->openingDeficitsPayload();

        return [
            'annual_depreciation' => $annual,
            'deferred' => self::centsFromEuros($this->openingDeferred) ?? 0,
            'deficits' => array_sum(array_column($deficits, 'amount')),
            'deficit_expiry' => $deficits === []
                ? null
                : $this->deficitExpiryYear(max(array_column($deficits, 'origin_year'))),
            'accumulated' => $cumul,
        ];
    }

    /** Année de la liasse de référence : celle qui précède la première année tenue ici. */
    public function referenceYear(): int
    {
        return (int) $this->firstYear - 1;
    }

    /** @return array<int, int> */
    public function yearOptions(): array
    {
        $start = $this->parseDate($this->rentalStartDate)?->year ?? (int) date('Y') - 5;

        return range(max(2000, $start), (int) date('Y') + 1);
    }
}
