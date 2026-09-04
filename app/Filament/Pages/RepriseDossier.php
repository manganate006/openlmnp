<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\EditsDepreciationComponents;
use App\Models\FiscalYear;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Services\DepreciationService;
use App\Services\FiscalYearService;
use App\Services\ReprisesCheckService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

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
    use EditsDepreciationComponents;

    protected static ?string $slug = 'reprise';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Reprendre un dossier existant';

    protected string $view = 'filament.pages.reprise-dossier';

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

    // Étape 4 — vos reports
    public ?string $openingDeferred = null;
    public ?string $openingAccumulated = null;
    public ?string $declaredGrossAssets = null;

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

    /** Écart signé formaté (+ 4 213 €). */
    public function formatSignedEuros(?int $cents): string
    {
        if ($cents === null) {
            return '—';
        }

        if ($cents === 0) {
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

        if ($this->step === 4) {
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
            $errors['rentalStartDate'] = 'Indiquez la date de mise en location (jj/mm/aaaa).';
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

        $land = $this->landPercentage === null || trim((string) $this->landPercentage) === ''
            ? null
            : (int) $this->landPercentage;

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
            'declaredGrossAssets' => 'Montant illisible (2033-A case 028).',
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

    /** Date d'un modèle, telle qu'elle doit s'afficher dans les champs de l'assistant. */
    private static function displayDate(?\Carbon\CarbonInterface $date): ?string
    {
        return $date?->format('d/m/Y');
    }

    // -------------------------------------------------------------------------
    // Étape 2 — enregistrement du bien
    // -------------------------------------------------------------------------

    private function persistProperty(): void
    {
        $rentalStart = $this->parseDate($this->rentalStartDate)?->format('Y-m-d');
        $acquisition = $this->parseDate($this->acquisitionDate)?->format('Y-m-d') ?? $rentalStart;

        $attributes = [
            'acquisition_price' => self::centsFromEuros($this->acquisitionPrice) ?? 0,
            'notary_fees' => self::centsFromEuros($this->notaryFees) ?? 0,
            'agency_fees' => self::centsFromEuros($this->agencyFees) ?? 0,
            'land_percentage' => (int) $this->landPercentage,
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
        if (! in_array($method, [self::METHOD_COPY, self::METHOD_SPREAD], true)) {
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
        return $this->method === self::METHOD_COPY ? 'amounts' : 'ventilation';
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
        $fiscalYear->opening_source = FiscalYear::OPENING_SOURCE_MANUAL;
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

        $this->report = app(ReprisesCheckService::class)->check($this->repriseFiscalYear(), $declared);
    }

    /**
     * Une ligne en écart dont le diagnostic « frais d'acquisition » est corroboré : c'est
     * la piste qui se chiffre, et la seule que l'application sait corriger d'un clic.
     */
    public function corroboratedAcquisitionFees(): bool
    {
        foreach ($this->report['lines'] ?? [] as $line) {
            foreach ($line['diagnostics'] ?? [] as $diagnostic) {
                if ($diagnostic['code'] === 'acquisition_fees' && $diagnostic['corroborated']) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Bascule les frais d'acquisition en charges, puis rejoue le contrôle. */
    public function expenseAcquisitionFees(): void
    {
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
        $this->runCheck();
    }

    // -------------------------------------------------------------------------
    // Données d'affichage
    // -------------------------------------------------------------------------

    /** Base amortissable calculée, affichée à l'étape 2 (centimes). */
    public function depreciableBaseCents(): int
    {
        $price = self::centsFromEuros($this->acquisitionPrice) ?? 0;
        $land = max(0, min(99, (int) $this->landPercentage));

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
