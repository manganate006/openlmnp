<?php

namespace App\Services\Ai\Analysis;

use App\Enums\TvaRate;
use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Income;
use App\Models\Loan;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Services\Ai\Analysis\Prompts\Prompt;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Traduit la réponse du modèle en valeurs exploitables par les formulaires.
 *
 * TOUTES les règles de normalisation vivent ici, côté PHP — jamais déléguées au
 * modèle (CDC § 5) : montants en centimes bcmath, taux de TVA ramenés aux
 * quatre valeurs de l'énumération, dates ISO, catégorie parmi les onze de
 * l'énumération SQL, durées d'amortissement déduites des règles métier.
 *
 * Règle de confiance : dès qu'une valeur lue est MODIFIÉE par une de ces règles
 * (taux de TVA inconnu ramené à 0, catégorie inconnue ramenée à « Divers »…),
 * la confiance du champ est plafonnée à 0,6 pour que l'écran de relecture le
 * signale en orange.
 */
final class Normalizer
{
    /** Plafond de confiance appliqué à un champ qu'une règle a modifié. */
    private const CORRECTED_CONFIDENCE = 0.6;

    /** Longueur maximale du libellé « Fournisseur — objet ». */
    private const DESCRIPTION_MAX = 120;

    /** Au-delà, un article de mobilier relève du gros équipement (7 ans). */
    private const HEAVY_EQUIPMENT_CENTS = 100_000;

    /** Mots-clés de linge de maison : amorti sur 3 ans comme l'occasion. */
    private const LINEN_KEYWORDS = ['linge', 'draps', 'couette', 'oreiller', 'serviette', 'rideau', 'housse'];

    /** Travaux lourds : 15 ans. */
    private const HEAVY_WORK_KEYWORDS = ['toiture', 'charpente', 'isolation', 'façade', 'facade', 'gros œuvre', 'gros oeuvre', 'ravalement', 'fondation'];

    /** Travaux d'embellissement : 5 ans. */
    private const LIGHT_WORK_KEYWORDS = ['peinture', 'revêtement', 'revetement', 'papier peint', 'moquette', 'parquet flottant', 'tapisserie'];

    /** @var array<int, string> */
    private array $warnings = [];

    /** @var array<string, float> */
    private array $confidences = [];

    /** @var array<string, string> */
    private array $evidence = [];

    /** @var array<string, array{value: ?string, confidence: float, evidence: ?string}> */
    private array $fields = [];

    /**
     * @param  array<string, mixed>  $payload  Réponse JSON décodée du modèle.
     */
    public function normalize(
        array $payload,
        Property $property,
        ?string $forcedType = null,
        string $model = '',
        bool $fallbackUsed = false,
    ): AnalysisResult {
        $this->warnings = [];
        $this->confidences = [];
        $this->evidence = [];
        $this->fields = $this->indexFields((array) ($payload['fields'] ?? []));

        $type = $this->resolveType($payload, $forcedType);
        $confidence = $this->clamp((float) ($payload['confidence'] ?? 0));

        $values = match ($type) {
            'furniture_invoice' => $this->furnitureValues($property),
            'works_invoice' => $this->workValues($property),
            'loan_schedule' => $this->loanValues($property),
            'bank_statement' => $this->statementValues(),
            'income_statement' => $this->earningsValues($property),
            'liasse_2033' => $this->liasseValues((array) ($payload['carryforwards'] ?? [])),
            default => $this->expenseValues($type, $property),
        };

        $rows = match ($type) {
            'furniture_invoice' => $this->furnitureRows((array) ($payload['items'] ?? []), $values),
            'loan_schedule' => $this->scheduleRows((array) ($payload['schedule'] ?? []), $values),
            'bank_statement' => $this->statementRows((array) ($payload['lines'] ?? []), $property),
            'income_statement' => $this->earningsRows((array) ($payload['earnings'] ?? []), $values),
            'liasse_2033' => $this->liasseRows((array) ($payload['depreciation'] ?? []), $values),
            default => [],
        };

        return new AnalysisResult(
            documentType: $type,
            confidence: $confidence,
            values: $values,
            confidences: $this->confidences,
            evidence: $this->evidence,
            warnings: $this->warnings,
            rows: $rows,
            model: $model,
            fallbackUsed: $fallbackUsed,
            propertyId: $property->id,
        );
    }

    // =========================================================================
    // Type de document
    // =========================================================================

    /**
     * Type retenu : celui imposé par l'utilisateur, sinon celui du modèle, avec
     * le reclassement facture -> mobilier / travaux du CDC § 5.1.
     */
    private function resolveType(array $payload, ?string $forcedType): string
    {
        if ($forcedType !== null && in_array($forcedType, Prompt::TYPES, strict: true)) {
            return $forcedType;
        }

        $type = (string) ($payload['document_type'] ?? 'unknown');

        if (! in_array($type, Prompt::TYPES, strict: true)) {
            return 'unknown';
        }

        if ($type !== 'expense_invoice') {
            return $type;
        }

        // Reclassement : une facture de mobilier ou de travaux déposée comme
        // charge courante bascule sur le bon formulaire, avec un message.
        $subject = mb_strtolower((string) ($this->raw('subject') . ' ' . $this->raw('vendor')));
        $amount = $this->amountCents($this->raw('total_ttc'));

        if ($this->matches($subject, array_merge(self::HEAVY_WORK_KEYWORDS, self::LIGHT_WORK_KEYWORDS))
            || $this->matches($subject, ['pose', 'rénovation', 'renovation', 'main-d\'œuvre', 'main d\'oeuvre', 'dépose'])) {
            $this->warnings[] = 'Ce document ressemble à une facture de travaux : nous l\'avons classé en Travaux, vous pouvez changer.';

            return 'works_invoice';
        }

        if ($amount !== null && $amount >= 50_000
            && $this->matches($subject, ['canapé', 'canape', 'lit', 'literie', 'matelas', 'électroménager', 'electromenager',
                'réfrigérateur', 'refrigerateur', 'lave-linge', 'lave-vaisselle', 'four', 'table', 'armoire', 'meuble'])) {
            $this->warnings[] = 'Ce document ressemble à une facture de mobilier : nous l\'avons classé en Mobilier, vous pouvez changer.';

            return 'furniture_invoice';
        }

        return 'expense_invoice';
    }

    // =========================================================================
    // Charges (facture, taxe foncière, assurance)
    // =========================================================================

    private function expenseValues(string $type, Property $property): array
    {
        $values = [
            'property_id' => $property->id,
            'expense_date' => $this->date('issue_date', 'expense_date'),
            'amount' => $this->amount('total_ttc', 'amount'),
            'tva_rate' => $this->vatRate('vat_rate'),
            'category' => $this->category('category'),
            'description' => $this->description(),
            'recurring_type' => $this->recurringType(),
            'is_dedicated' => $this->isDedicated($property),
            'notes' => $this->periodNote(),
        ];

        // Avis d'imposition : catégorie et taux imposés par la nature du document.
        if ($type === 'property_tax_notice') {
            $values['category'] = Expense::CATEGORY_PROPERTY_TAX;
            $values['tva_rate'] = TvaRate::Exempt->value;
            $values['recurring_type'] = 'yearly';
            $this->confidences['category'] = 0.95;

            if ($property->is_primary_residence) {
                $this->warnings[] = 'Bien mixte : pensez à la quote-part, appliquée automatiquement si la charge n\'est pas 100 % dédiée.';
            }
        }

        if ($type === 'insurance') {
            $values['category'] = Expense::CATEGORY_INSURANCE;
            $this->confidences['category'] = 0.95;

            if ($values['recurring_type'] === 'once') {
                $values['recurring_type'] = 'yearly';
            }

            // La taxe sur les conventions d'assurance n'est pas de la TVA :
            // un « taux » lu sur une attestation ne doit pas atterrir ici.
            if ($values['tva_rate'] !== TvaRate::Exempt->value) {
                $this->warnings[] = 'Une assurance ne porte pas de TVA (taxe sur les conventions d\'assurance) : le taux a été remis à 0 %.';
                $values['tva_rate'] = TvaRate::Exempt->value;
                $this->confidences['tva_rate'] = self::CORRECTED_CONFIDENCE;
            }
        }

        if ($type === 'unknown') {
            $this->warnings[] = 'Document non reconnu : le formulaire est vide, à vous de le remplir.';
        }

        return $values;
    }

    // =========================================================================
    // Mobilier
    // =========================================================================

    private function furnitureValues(Property $property): array
    {
        $amount = $this->amount('total_ttc', 'amount');
        $secondHand = $this->boolean('is_second_hand', 'is_second_hand');
        $label = $this->text('item_label') ?? $this->description();

        return [
            'property_id' => $property->id,
            'purchase_date' => $this->date('issue_date', 'purchase_date'),
            'amount' => $amount,
            'tva_rate' => $this->vatRate('vat_rate'),
            'description' => $label,
            'is_second_hand' => $secondHand,
            'duration_years' => $this->furnitureDuration($amount, $secondHand, (string) $label),
            'is_dedicated' => $this->isDedicated($property),
        ];
    }

    /**
     * Durée d'amortissement du mobilier — proposée par le CODE, jamais par le
     * modèle (CDC § 5.2) : 3 ans en occasion ou pour le linge, 7 ans au-delà de
     * 1 000 € (gros équipement), 5 ans sinon.
     */
    private function furnitureDuration(?int $amountCents, bool $secondHand, string $label): int
    {
        $this->confidences['duration_years'] = 0.7;

        if ($secondHand || $this->matches(mb_strtolower($label), self::LINEN_KEYWORDS)) {
            return Furniture::DURATION_SECOND_HAND;
        }

        if ($amountCents !== null && $amountCents > self::HEAVY_EQUIPMENT_CENTS) {
            return Furniture::DURATION_EQUIPMENT;
        }

        return Furniture::DURATION_NEW;
    }

    /**
     * Une ligne par article ≥ 100 € TTC, les petits articles regroupés en une
     * ligne « Petit équipement » (CDC § 5.2).
     */
    private function furnitureRows(array $items, array $values): array
    {
        if (count($items) < 2) {
            return [];
        }

        $rows = [];
        $smallTotal = 0;
        $smallCount = 0;

        foreach ($items as $item) {
            $amount = $this->amountCents($item['total_ttc'] ?? null);

            if ($amount === null || $amount <= 0) {
                continue;
            }

            $label = trim((string) ($item['label'] ?? '')) ?: 'Article';

            if ($amount < 10_000) {
                $smallTotal += $amount;
                $smallCount++;

                continue;
            }

            $secondHand = (bool) ($item['is_second_hand'] ?? $values['is_second_hand'] ?? false);

            $rows[] = [
                'selected' => true,
                'description' => mb_substr($label, 0, self::DESCRIPTION_MAX),
                'amount' => $amount,
                'tva_rate' => $this->vatRateValue($item['vat_rate'] ?? null) ?? $values['tva_rate'],
                'is_second_hand' => $secondHand,
                'duration_years' => $this->furnitureDuration($amount, $secondHand, $label),
                'confidence' => $this->clamp((float) ($item['confidence'] ?? 0.5)),
            ];
        }

        if ($smallCount > 0) {
            $rows[] = [
                'selected' => true,
                'description' => 'Petit équipement (' . $smallCount . ' article' . ($smallCount > 1 ? 's' : '') . ')',
                'amount' => $smallTotal,
                'tva_rate' => $values['tva_rate'],
                'is_second_hand' => (bool) ($values['is_second_hand'] ?? false),
                'duration_years' => Furniture::DURATION_NEW,
                'confidence' => 0.6,
            ];
        }

        return $rows;
    }

    // =========================================================================
    // Travaux
    // =========================================================================

    private function workValues(Property $property): array
    {
        $description = $this->description();

        return [
            'property_id' => $property->id,
            'work_date' => $this->date('issue_date', 'work_date'),
            'amount' => $this->amount('total_ttc', 'amount'),
            'tva_rate' => $this->vatRate('vat_rate'),
            'description' => $description,
            'duration_years' => $this->workDuration((string) $description),
            'is_dedicated' => $this->isDedicated($property),
        ];
    }

    /** 15 ans pour le gros œuvre, 5 ans pour l'embellissement, 10 ans sinon (CDC § 5.3). */
    private function workDuration(string $description): int
    {
        $this->confidences['duration_years'] = 0.7;
        $haystack = mb_strtolower($description);

        if ($this->matches($haystack, self::HEAVY_WORK_KEYWORDS)) {
            return 15;
        }

        if ($this->matches($haystack, self::LIGHT_WORK_KEYWORDS)) {
            return 5;
        }

        return 10;
    }

    // =========================================================================
    // Emprunt
    // =========================================================================

    private function loanValues(Property $property): array
    {
        $insuranceType = $this->text('insurance_type');

        return [
            'property_id' => $property->id,
            'bank_name' => $this->text('bank_name', 'bank_name'),
            'amount' => $this->amount('principal', 'amount'),
            'annual_rate' => $this->rate('annual_rate'),
            'duration_months' => $this->integer('duration_months', 'duration_months'),
            'start_date' => $this->startDate(),
            'monthly_payment' => $this->amount('monthly_payment', 'monthly_payment'),
            'insurance_monthly' => $this->amount('insurance_monthly', 'insurance_monthly') ?? 0,
            'insurance_rate' => $this->rate('insurance_rate', 'insurance_rate'),
            'insurance_type' => $insuranceType === Loan::INSURANCE_VARIABLE
                ? Loan::INSURANCE_VARIABLE
                : Loan::INSURANCE_FIXED,
        ];
    }

    /**
     * `loans.start_date` est la date d'effet : la première échéance tombe un
     * mois plus tard (LoanService::generateSchedule ajoute i mois). Le modèle
     * lit la date de première échéance, on retranche donc un mois.
     */
    private function startDate(): ?string
    {
        $first = $this->date('first_payment_date', 'start_date');

        if ($first === null) {
            return null;
        }

        return CarbonImmutable::parse($first)->subMonth()->toDateString();
    }

    /** Échéances importées, avec les contrôles de cohérence du CDC § 5.6. */
    private function scheduleRows(array $schedule, array $values): array
    {
        $rows = [];
        $number = 0;

        foreach ($schedule as $line) {
            $capital = $this->amountCents($line['capital'] ?? null);
            $date = $this->parseDate($line['date'] ?? null);

            if ($capital === null || $date === null) {
                continue;
            }

            $number++;

            $rows[] = [
                'month_number' => (int) ($line['n'] ?? $number),
                'payment_date' => $date,
                'capital_amount' => $capital,
                'interest_amount' => $this->amountCents($line['interest'] ?? null) ?? 0,
                'insurance_amount' => $this->amountCents($line['insurance'] ?? null) ?? 0,
                'remaining_capital' => $this->amountCents($line['remaining'] ?? null) ?? 0,
            ];
        }

        if ($rows === []) {
            $this->warnings[] = 'Aucune échéance lisible dans ce document : vérifiez qu\'il contient bien le tableau d\'amortissement.';

            return [];
        }

        $this->warnings = array_merge($this->warnings, ScheduleChecker::check($rows, $values));

        return $rows;
    }

    // =========================================================================
    // Relevé bancaire
    // =========================================================================

    private function statementValues(): array
    {
        $last4 = $this->text('iban_last4');

        return [
            'bank_name' => $this->text('bank_name', 'bank_name'),
            'period_start' => $this->date('period_start'),
            'period_end' => $this->date('period_end'),
            // Jamais l'IBAN complet : 4 chiffres, et uniquement pour l'affichage.
            'iban_last4' => $last4 !== null ? substr(preg_replace('/\D/', '', $last4) ?: '', -4) : null,
        ];
    }

    private function statementRows(array $lines, Property $property): array
    {
        $rows = [];

        foreach ($lines as $line) {
            $amount = $this->amountCents($line['amount'] ?? null);
            $date = $this->parseDate($line['date'] ?? null);

            if ($amount === null || $amount <= 0 || $date === null) {
                continue;
            }

            $kind = (string) ($line['kind'] ?? 'ignore');

            if (! in_array($kind, ['expense', 'income', 'loan_payment', 'ignore'], strict: true)) {
                $kind = 'ignore';
            }

            $category = $this->categoryValue($line['category'] ?? null) ?? Expense::CATEGORY_OTHER;

            $rows[] = [
                'selected' => $kind === 'expense' || $kind === 'income',
                'date' => $date,
                'label' => mb_substr(trim((string) ($line['label'] ?? '')) ?: 'Opération', 0, self::DESCRIPTION_MAX),
                'amount' => $amount,
                'direction' => ($line['direction'] ?? null) === 'credit' ? 'credit' : 'debit',
                'kind' => $kind,
                'category' => $category,
                'source' => $this->incomeSource((string) ($line['label'] ?? '')),
                'is_dedicated' => ! $property->is_primary_residence,
                'confidence' => $this->clamp((float) ($line['confidence'] ?? 0.5)),
            ];
        }

        if (collect($rows)->where('kind', 'loan_payment')->isNotEmpty()) {
            $this->warnings[] = 'Des échéances de prêt ont été détectées : elles ne sont pas importées ici. Importez le tableau d\'amortissement de votre banque depuis la fiche de l\'emprunt.';
        }

        return $rows;
    }

    /** Source d'une recette déduite du libellé de l'opération. */
    /** Mois en clair. ÉCRITS EN BASE (colonne `notes`), donc jamais dépendants de la locale. */
    private const MOIS = [
        1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];

    /**
     * En-tête d'un relevé annuel de plateforme.
     *
     * Les totaux du récapitulatif ne sont JAMAIS écrits tels quels : ils servent aux
     * contrôles de cohérence d'earningsRows() et à l'affichage. Précédent identique :
     * statementValues() pose `bank_name` et `iban_last4`, qui n'ont pas de champ non plus.
     *
     * @return array<string, mixed>
     */
    private function earningsValues(Property $property): array
    {
        $year = $this->integer('fiscal_year', 'fiscal_year');
        $source = $this->incomeSource(
            (string) $this->raw('vendor') . ' ' . (string) $this->raw('subject'),
            default: Income::SOURCE_OTHER,
        );

        return [
            'property_id' => $property->id,
            'fiscal_year' => $year,
            'source' => $source,
            'tva_rate' => $property->defaultIncomeTvaRate(),

            'gross_total' => $this->amount('gross_total'),
            'service_fees' => $this->amount('service_fees'),
            'adjustments' => $this->amount('adjustments'),
            'net_total' => $this->amount('net_total'),
            'tourist_tax_collected' => $this->amount('tourist_tax_collected'),

            // Consommées par attachDocument() : le libellé du justificatif et sa date.
            'description' => trim((Income::sourceLabels()[$source] ?? 'Plateforme') . ' — relevé annuel ' . ($year ?? '')),
            'period_end' => $year !== null ? "{$year}-12-31" : null,
        ];
    }

    /**
     * Une recette par mois, depuis les DEUX colonnes du relevé.
     *
     * ⚠️ Rien n'est réparti. Le relevé publie déjà, mois par mois, un « revenus bruts »
     * et un « total versé » : leur écart EST la retenue du mois — commission et
     * régularisation comprises, chacune dans son vrai mois. Vérifié sur le relevé réel
     * 2024 : Σbruts = 18 464,06 €, Σtotaux = 17 732,35 €, Σécarts = 731,71 € = frais
     * (662,21) + régularisations (69,50), au centime. Un prorata aurait étalé sur douze
     * mois une régularisation qui n'appartient qu'à décembre.
     *
     * @param  array<int, mixed>  $earnings
     * @param  array<string, mixed>  $values
     * @return array<int, array<string, mixed>>
     */
    private function earningsRows(array $earnings, array $values): array
    {
        $rows = [];
        $vus = [];
        $sommeBrute = 0;
        $sommeFrais = 0;
        $annee = $values['fiscal_year'] ?? null;

        foreach ($earnings as $ligne) {
            if (! is_array($ligne)) {
                continue;
            }

            $mois = isset($ligne['month']) ? (int) $ligne['month'] : 0;

            if ($mois < 1 || $mois > 12) {
                continue;
            }

            // ⚠️ UN MOIS NE PEUT APPARAÎTRE QU'UNE FOIS, et ce n'est pas théorique :
            // sur le relevé Airbnb 2024, « mai » est imprimé DEUX FOIS — la ligne est
            // répétée en tête de la page suivante. Sans cette garde, mai est compté
            // deux fois et l'année est fausse de 1 948,70 €, soit 10 %. Le contrôle de
            // cohérence l'aurait signalé, mais une recette en trop aurait été créée.
            if (isset($vus[$mois])) {
                continue;
            }

            $vus[$mois] = true;

            $brut = $this->amountCents(isset($ligne['gross']) ? (string) $ligne['gross'] : null);
            $net = $this->amountCents(isset($ligne['net']) ? (string) $ligne['net'] : null);
            $brut ??= $net;   // une plateforme qui ne publierait qu'une colonne

            if ($brut === null || $brut <= 0) {
                // ⚠️ Un mois à 0 n'est pas une recette nulle à créer : c'est un mois sans
                // location. Mais un mois NÉGATIF (remboursement intégral) doit se dire —
                // Income ne sait pas porter un montant négatif, et l'escamoter
                // fausserait le total sans que rien ne l'indique.
                if ($net !== null && $net < 0) {
                    $this->warnings[] = 'Le mois de ' . self::MOIS[$mois] . ' présente un solde négatif '
                        . '(remboursement) : aucune recette n\'a été créée, saisissez-le à la main.';
                }

                continue;
            }

            // ⚠️ max(0, …) : une régularisation POSITIVE rend net > brut, donc une
            // commission négative, donc une recette nette GONFLÉE. Jamais.
            $frais = $net === null ? 0 : max(0, $brut - $net);

            $sommeBrute += $brut;
            $sommeFrais += $frais;

            $rows[] = [
                'selected' => true,
                // `kind`, `date` et `amount` sont les clés que lit markDuplicateRows() :
                // s'y mouler évite d'élargir sa signature et de risquer le relevé bancaire.
                'kind' => 'income',
                'month' => $mois,
                'date' => $annee !== null
                    ? CarbonImmutable::createFromDate($annee, $mois, 1)->endOfMonth()->toDateString()
                    : null,
                'label' => 'Recettes ' . self::MOIS[$mois] . ($annee !== null ? ' ' . $annee : ''),
                'amount' => $brut,
                'platform_fee' => $frais,
                'source' => $values['source'] ?? Income::SOURCE_OTHER,
                'tva_rate' => $values['tva_rate'] ?? 0,
                'confidence' => $this->clamp((float) ($ligne['confidence'] ?? 0.5)),
            ];
        }

        $this->checkEarningsTotals($rows, $values, $sommeBrute, $sommeFrais);

        return $rows;
    }

    /**
     * Recoupe les lignes mensuelles avec le récapitulatif.
     *
     * C'est le contrôle qui rattrape une ligne oubliée ou mal lue sans coûter un appel
     * de plus — même patron que liasseRows(), tolérance d'un euro.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $values
     */
    private function checkEarningsTotals(array $rows, array $values, int $sommeBrute, int $sommeFrais): void
    {
        if ($rows === []) {
            $this->warnings[] = 'Aucun revenu mensuel n\'a pu être lu dans ce relevé : vérifiez le document, '
                . 'ou saisissez vos recettes à la main.';

            return;
        }

        $brutDeclare = $values['gross_total'] ?? null;

        if ($brutDeclare !== null && abs($sommeBrute - $brutDeclare) > 100) {
            $this->warnings[] = sprintf(
                'La somme des revenus mensuels (%s €) ne correspond pas au total annoncé par le relevé (%s €) : '
                . 'un mois a peut-être été mal lu.',
                number_format($sommeBrute / 100, 2, ',', ' '),
                number_format($brutDeclare / 100, 2, ',', ' '),
            );
        }

        $fraisDeclares = abs($values['service_fees'] ?? 0) + abs($values['adjustments'] ?? 0);

        if ($fraisDeclares > 0 && abs($sommeFrais - $fraisDeclares) > 100) {
            $this->warnings[] = sprintf(
                'Les retenues mensuelles (%s €) ne correspondent pas aux frais et régularisations du récapitulatif (%s €).',
                number_format($sommeFrais / 100, 2, ',', ' '),
                number_format($fraisDeclares / 100, 2, ',', ' '),
            );
        }

        $taxe = $values['tourist_tax_collected'] ?? null;

        if ($taxe !== null && $taxe > 0) {
            // ⚠️ Non répartie, et volontairement absente des lignes : le relevé ne la
            // ventile nulle part, elle n'est pas comprise dans les revenus bruts, et
            // AUCUN calcul de l'application ne lit `tourist_tax`. L'inventer n'aurait
            // aucun effet fiscal, seulement le coût d'une donnée fausse.
            $this->warnings[] = sprintf(
                'La plateforme a collecté et reversé %s € de taxe de séjour. Ce montant n\'est pas compris '
                . 'dans vos revenus bruts et n\'est réparti sur aucun mois : aucune ligne n\'a été créée pour lui.',
                number_format($taxe / 100, 2, ',', ' '),
            );
        }
    }

    /**
     * @param  string  $default  Sur un RELEVÉ de plateforme, `direct` serait un
     *                           contresens : le versement vient bien d'une plateforme,
     *                           simplement non reconnue. D'où `other` par défaut là-bas.
     */
    private function incomeSource(string $label, string $default = Income::SOURCE_DIRECT): string
    {
        $haystack = mb_strtolower($label);

        return match (true) {
            str_contains($haystack, 'airbnb') => 'airbnb',
            str_contains($haystack, 'booking') => 'booking',
            str_contains($haystack, 'abritel') || str_contains($haystack, 'homeaway') => 'abritel',
            default => $default,
        };
    }

    // =========================================================================
    // Champs élémentaires
    // =========================================================================

    /**
     * Indexe les champs par nom, en ramenant les synonymes au nom du prompt.
     *
     * Le prompt impose `vat_rate` ; un modèle qui travaille sur un document
     * français renvoie régulièrement `tva_rate`. Refuser silencieusement le
     * synonyme reviendrait à proposer 0 % de TVA sur une facture qui en porte.
     *
     * @param  array<int, mixed>  $fields
     */
    private function indexFields(array $fields): array
    {
        $synonymes = [
            'tva_rate' => 'vat_rate',
            'amount' => 'total_ttc',
            'total' => 'total_ttc',
            'date' => 'issue_date',
            'supplier' => 'vendor',
        ];

        $indexed = [];

        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            $name = $synonymes[$name] ?? $name;

            if ($name === '' || isset($indexed[$name])) {
                continue;
            }

            $indexed[$name] = [
                'value' => isset($field['value']) ? (string) $field['value'] : null,
                'confidence' => $this->clamp((float) ($field['confidence'] ?? 0.5)),
                'evidence' => isset($field['evidence']) ? mb_substr((string) $field['evidence'], 0, 80) : null,
            ];
        }

        return $indexed;
    }

    private function raw(string $name): ?string
    {
        $value = $this->fields[$name]['value'] ?? null;

        return ($value === null || trim($value) === '') ? null : trim($value);
    }

    // =========================================================================
    // Liasse 2033 (assistant de reprise)
    // =========================================================================

    /**
     * Cases de la liasse, en centimes. Invariant du lot 6 : une case absente
     * reste ABSENTE (null) — jamais un zéro qui ressemblerait à un montant lu.
     *
     * @param  array<int, array<string, mixed>>  $carryforwards
     * @return array<string, mixed>
     */
    private function liasseValues(array $carryforwards): array
    {
        $values = [
            'fiscal_year' => $this->integer('fiscal_year', 'fiscal_year'),
            'intangible_assets' => $this->amount('case_014', 'intangible_assets'),
            'gross_assets' => $this->amount('case_028', 'gross_assets'),
            // Le TOTAL (044), distinct de la seule case 028 : le contrôle de reprise compare
            // le total, qui ne dépend pas de la ligne où un cabinet range ses frais d'acquisition.
            // ⚠️ Le prompt ne la DEMANDE pas : l'ajouter à la table des cases a fait lire au
            // modèle les bases du 2033-C dans la mauvaise colonne (amortissements au début de
            // l'exercice, donc 0 pour un premier exercice), mesuré deux fois sur une liasse
            // réelle le 2026-10-16. `RepriseDossier::liasseTotalGrossAssets()` la reconstitue
            // par 028 + 014 ; elle n'est lue que si le modèle la fournit de lui-même.
            'total_gross_assets' => $this->amount('case_044', 'total_gross_assets'),
            'accumulated_depreciation' => $this->amount('case_030', 'accumulated_depreciation'),
            'declared_total_depreciation' => $this->amount('case_572', 'declared_total_depreciation'),
            'fiscal_result_profit' => $this->amount('case_352', 'fiscal_result_profit'),
            'fiscal_result_deficit' => $this->amount('case_354', 'fiscal_result_deficit'),
        ];

        // Le stock d'ARD se lit en 2033-D case 870 ; à défaut, la case 318 du
        // 2033-B (ARD de l'exercice) sert de repli, signalé à l'utilisateur.
        $values['deferred_depreciation'] = $this->amount('case_870', 'deferred_depreciation');

        if ($values['deferred_depreciation'] === null) {
            $fallback = $this->amount('case_318', 'deferred_depreciation');

            if ($fallback !== null) {
                $values['deferred_depreciation'] = $fallback;
                $this->warnings[] = 'La case 870 du 2033-D est vide : les amortissements différés ont été '
                    . 'lus sur la case 318 du 2033-B, qui ne porte que l\'exercice — vérifiez le cumul.';
            }
        }

        $values['deficits'] = $this->liasseDeficits($carryforwards);

        return $values;
    }

    /**
     * @param  array<int, array<string, mixed>>  $carryforwards
     * @return array<int, array{origin_year: int, amount: int, confidence: float}>
     */
    private function liasseDeficits(array $carryforwards): array
    {
        $deficits = [];

        foreach ($carryforwards as $row) {
            $year = isset($row['origin_year']) ? (int) $row['origin_year'] : null;
            $amount = $this->amountCents(isset($row['amount']) ? (string) $row['amount'] : null);

            if ($year === null || $year < 1990 || $amount === null || $amount <= 0) {
                continue;
            }

            $deficits[] = [
                'origin_year' => $year,
                'amount' => $amount,
                'confidence' => $this->clamp((float) ($row['confidence'] ?? 0)),
            ];
        }

        usort($deficits, fn (array $a, array $b) => $a['origin_year'] <=> $b['origin_year']);

        return $deficits;
    }

    /**
     * Lignes du 2033-C, prêtes pour l'éditeur d'amortissements : montants en
     * centimes, durée dérivée de base ÷ dotation quand elle n'est pas imprimée,
     * catégorie Cerfa suggérée d'après l'intitulé.
     *
     * Contrôle de cohérence « gratuit » du lot 6 : la somme des dotations lues
     * doit retomber sur la ligne 572 — si les deux tombent d'accord, le tableau
     * a été lu en entier.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $values
     * @return array<int, array<string, mixed>>
     */
    private function liasseRows(array $rows, array $values): array
    {
        $normalized = [];
        $annualSum = 0;

        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));

            if ($label === '') {
                continue;
            }

            $base = $this->amountCents(isset($row['base']) ? (string) $row['base'] : null);
            $annual = $this->amountCents(isset($row['annual']) ? (string) $row['annual'] : null);
            $cumul = $this->amountCents(isset($row['cumul']) ? (string) $row['cumul'] : null);
            $duration = isset($row['duration_years']) ? (int) $row['duration_years'] : null;

            // Le 2033-C n'imprime pas toujours la durée : base ÷ dotation la
            // reconstitue, plafonnée aux durées comptables plausibles.
            if (($duration === null || $duration <= 0) && $base !== null && $annual !== null && $annual > 0) {
                $duration = (int) min(60, max(1, (int) round($base / $annual)));
            }

            if ($annual !== null) {
                $annualSum += $annual;
            }

            // Intitulés du formulaire (« Constructions »…), pas les noms de notre catalogue.
            $category = PropertyComponent::cerfaCategoryForLiasseLabel($label);

            // Les frais d'acquisition (immobilisations incorporelles : notaire,
            // agence, honoraires) sont déjà amortis par l'application à partir
            // des frais saisis à l'étape 2 du wizard : la ligne est marquée pour
            // que la relecture la montre, et l'assistant ne la recopie pas.
            if (preg_match('/frais|notaire|honoraire|acquisition|incorporel/iu', $label)) {
                $category = PropertyComponent::CERFA_CATEGORY_INTANGIBLE;
            }

            // Le terrain (ligne 420/510 du 2033-C) ne s'amortit jamais : la base
            // amortissable l'exclut déjà, séparément, via `land_percentage` sur le bien.
            // La ligne est marquée pour que `RepriseDossier::applyLiasse()` la REFUSE —
            // voir le docblock de `CERFA_CATEGORY_LAND`.
            if (preg_match('/\bterrains?\b/iu', $label)) {
                $category = PropertyComponent::CERFA_CATEGORY_LAND;
            }

            $normalized[] = [
                'label' => mb_substr($label, 0, 80),
                'base' => $base,
                'duration_years' => ($duration !== null && $duration > 0) ? $duration : null,
                'annual' => $annual,
                'cumul' => $cumul,
                'cerfa_category' => $category,
                'confidence' => $this->clamp((float) ($row['confidence'] ?? 0)),
            ];
        }

        $declared = $values['declared_total_depreciation'] ?? null;

        if ($normalized === []) {
            $this->warnings[] = 'Aucune ligne d\'amortissement n\'a pu être lue sur le tableau 2033-C.';
        } elseif ($declared !== null && abs($annualSum - $declared) > 100) {
            $this->warnings[] = sprintf(
                'La somme des dotations lues (%s €) ne retombe pas sur la ligne 572 (%s €) : '
                    . 'une ligne du tableau a probablement été mal lue ou omise — relisez-les une à une.',
                number_format($annualSum / 100, 2, ',', ' '),
                number_format($declared / 100, 2, ',', ' '),
            );
        }

        return $normalized;
    }

    /** Reporte confiance et extrait du champ lu vers le champ du formulaire. */
    private function carry(string $source, ?string $target, ?float $cap = null): void
    {
        if ($target === null || ! isset($this->fields[$source])) {
            return;
        }

        $confidence = $this->fields[$source]['confidence'];
        $this->confidences[$target] = $cap === null ? $confidence : min($confidence, $cap);

        if (filled($this->fields[$source]['evidence'])) {
            $this->evidence[$target] = $this->fields[$source]['evidence'];
        }
    }

    private function text(string $name, ?string $target = null): ?string
    {
        $this->carry($name, $target);

        return $this->raw($name);
    }

    private function integer(string $name, ?string $target = null): ?int
    {
        $this->carry($name, $target);
        $raw = $this->raw($name);

        return $raw === null ? null : (int) preg_replace('/\D/', '', $raw);
    }

    private function boolean(string $name, ?string $target = null): bool
    {
        $this->carry($name, $target);

        return in_array(mb_strtolower((string) $this->raw($name)), ['true', '1', 'oui', 'yes'], strict: true);
    }

    private function amount(string $name, ?string $target = null): ?int
    {
        $raw = $this->raw($name);
        $cents = $this->amountCents($raw);
        $this->carry($name, $target, $this->amountWasAmbiguous($raw) ? self::CORRECTED_CONFIDENCE : null);

        return $cents;
    }

    /**
     * Montant décimal -> centimes, en bcmath (jamais de float).
     *
     * Accepte les écritures françaises (« 1 234,56 € », espace fine insécable
     * comprise) comme la forme demandée au modèle (« 1234.56 »).
     */
    public function amountCents(?string $raw): ?int
    {
        $decimal = $this->toDecimalString($raw);

        return $decimal === null ? null : (int) bcmul($decimal, '100', 0);
    }

    /**
     * Un point suivi de trois chiffres est ambigu (« 1.234 » = 1 234 ou 1,234 ?) :
     * on tranche pour le séparateur de milliers, mais on le signale.
     */
    private function amountWasAmbiguous(?string $raw): bool
    {
        return $raw !== null
            && ! str_contains($raw, ',')
            && (bool) preg_match('/^\s*-?\d{1,3}\.\d{3}\s*$/u', $raw);
    }

    /** Nettoyage commun des écritures de nombres, sans jamais passer par un float. */
    private function toDecimalString(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        // Symboles monétaires, espaces ordinaires, insécables et fines insécables.
        $clean = preg_replace('/[^\d,.\-]/u', '', str_replace(["\u{00A0}", "\u{202F}"], '', $raw)) ?? '';

        if ($clean === '' || ! preg_match('/\d/', $clean)) {
            return null;
        }

        $negative = str_starts_with($clean, '-');
        $clean = ltrim($clean, '-');

        $lastComma = strrpos($clean, ',');
        $lastDot = strrpos($clean, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // Les deux séparateurs : le dernier est le séparateur décimal.
            [$thousands, $decimal] = $lastComma > $lastDot ? ['.', ','] : [',', '.'];
            $clean = str_replace($thousands, '', $clean);
            $clean = str_replace($decimal, '.', $clean);
        } elseif ($lastComma !== false) {
            // Virgule seule : décimale française, sauf répétition (1,234,567).
            $clean = substr_count($clean, ',') > 1
                ? str_replace(',', '', $clean)
                : str_replace(',', '.', $clean);
        } elseif ($lastDot !== false && substr_count($clean, '.') === 1) {
            // Point seul suivi de 3 chiffres exactement : séparateur de milliers.
            if (preg_match('/^\d{1,3}\.\d{3}$/', $clean)) {
                $clean = str_replace('.', '', $clean);
            }
        } elseif ($lastDot !== false) {
            $clean = str_replace('.', '', $clean);
        }

        if (! preg_match('/^\d+(\.\d+)?$/', $clean)) {
            return null;
        }

        // Deux décimales, tronquées : bcadd n'arrondit pas, ce qui est voulu.
        return ($negative ? '-' : '') . bcadd($clean, '0', 2);
    }

    /** Taux (annuel, assurance) : décimal à 3 chiffres, jamais un float. */
    private function rate(string $name, ?string $target = null): ?string
    {
        $this->carry($name, $target);
        $decimal = $this->toDecimalString($this->raw($name));

        return $decimal === null ? null : bcadd($decimal, '0', 3);
    }

    // =========================================================================
    // TVA, catégories, dates
    // =========================================================================

    /** Taux de TVA ramené aux quatre valeurs de l'énumération (CDC § 5). */
    private function vatRate(string $name): int
    {
        $raw = $this->raw($name);
        $value = $this->vatRateValue($raw);

        if ($value === null) {
            if ($raw !== null) {
                $this->warnings[] = "Taux de TVA non reconnu ({$raw}) : proposé à 0 %, à vérifier.";
                $this->confidences['tva_rate'] = self::CORRECTED_CONFIDENCE;
            }

            return TvaRate::Exempt->value;
        }

        $this->carry($name, 'tva_rate');

        return $value;
    }

    /** @return int|null Points de base, ou null si le taux n'est pas dans l'énumération. */
    private function vatRateValue(?string $raw): ?int
    {
        $decimal = $this->toDecimalString($raw);

        if ($decimal === null) {
            return null;
        }

        // Un taux déjà exprimé en points de base (2000) est accepté tel quel.
        $candidate = bccomp($decimal, '100', 2) > 0
            ? (int) bcadd($decimal, '0', 0)
            : (int) bcmul($decimal, '100', 0);

        return TvaRate::tryFrom($candidate)?->value;
    }

    /** Catégorie de charge parmi les onze de l'énumération SQL. */
    private function category(string $name): string
    {
        $raw = $this->raw($name);
        $value = $this->categoryValue($raw);

        if ($value === null) {
            $this->confidences['category'] = self::CORRECTED_CONFIDENCE;

            if ($raw !== null) {
                $this->warnings[] = "Catégorie non reconnue ({$raw}) : classée en « Divers », à vérifier.";
            }

            return Expense::CATEGORY_OTHER;
        }

        $this->carry($name, 'category');

        return $value;
    }

    private function categoryValue(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $slug = mb_strtolower(trim($raw));

        return array_key_exists($slug, Expense::categoryLabels()) ? $slug : null;
    }

    private function date(string $name, ?string $target = null): ?string
    {
        $this->carry($name, $target);

        return $this->parseDate($this->raw($name));
    }

    /** Dates ISO et écritures françaises (12/03/2026, 12-03-2026, 12.03.2026). */
    private function parseDate(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $raw = trim($raw);

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y', 'Y/m/d'] as $format) {
            $parsed = CarbonImmutable::canBeCreatedFromFormat($raw, $format)
                ? CarbonImmutable::createFromFormat($format, $raw)
                : null;

            if ($parsed !== null) {
                return $parsed->toDateString();
            }
        }

        try {
            return CarbonImmutable::parse($raw)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    // =========================================================================
    // Champs dérivés
    // =========================================================================

    /** « Fournisseur — objet », tronqué à 120 caractères. */
    private function description(): ?string
    {
        $vendor = $this->raw('vendor');
        $subject = $this->raw('subject');

        $this->carry('vendor', 'description');

        $parts = array_filter([$vendor, $subject]);

        if ($parts === []) {
            return null;
        }

        return mb_substr(implode(' — ', $parts), 0, self::DESCRIPTION_MAX);
    }

    /** Récurrence déduite de la période couverte (CDC § 5.1). */
    private function recurringType(): string
    {
        $start = $this->parseDate($this->raw('period_start'));
        $end = $this->parseDate($this->raw('period_end'));

        if ($start === null || $end === null) {
            return 'once';
        }

        $months = CarbonImmutable::parse($start)->diffInMonths(CarbonImmutable::parse($end));
        $this->confidences['recurring_type'] = 0.7;

        return match (true) {
            $months >= 11 => 'yearly',
            $months >= 2.5 => 'quarterly',
            $months >= 0.75 => 'monthly',
            default => 'once',
        };
    }

    /** La période lue est conservée en note : elle ne rentre dans aucun champ. */
    private function periodNote(): ?string
    {
        $start = $this->parseDate($this->raw('period_start'));
        $end = $this->parseDate($this->raw('period_end'));

        if ($start === null || $end === null) {
            return null;
        }

        return 'Période couverte : du ' . CarbonImmutable::parse($start)->format('d/m/Y')
            . ' au ' . CarbonImmutable::parse($end)->format('d/m/Y') . '.';
    }

    /**
     * Charge 100 % dédiée ou soumise à la quote-part.
     *
     * Un bien qui est aussi la résidence principale du propriétaire implique une
     * quote-part : on propose « non dédiée ». On avertit par ailleurs si
     * l'adresse imprimée ne ressemble pas à celle du bien.
     */
    private function isDedicated(Property $property): bool
    {
        $onDocument = $this->raw('address_on_document');

        if ($onDocument !== null && ! $this->addressLooksLike($onDocument, $property)) {
            $this->warnings[] = 'L\'adresse figurant sur le document ne correspond pas à celle du bien sélectionné : vérifiez que vous avez choisi le bon bien.';
        }

        $this->confidences['is_dedicated'] = 0.6;

        return ! $property->is_primary_residence;
    }

    /** Comparaison volontairement laxiste : code postal OU numéro + rue. */
    private function addressLooksLike(string $onDocument, Property $property): bool
    {
        $haystack = mb_strtolower($onDocument);

        if (filled($property->postal_code) && str_contains($haystack, mb_strtolower((string) $property->postal_code))) {
            return true;
        }

        if (filled($property->city) && str_contains($haystack, mb_strtolower((string) $property->city))) {
            return true;
        }

        $street = preg_replace('/[^a-z0-9 ]/u', '', mb_strtolower((string) $property->address)) ?? '';
        $words = array_filter(explode(' ', $street), fn (string $w) => mb_strlen($w) > 3);

        foreach ($words as $word) {
            if (str_contains($haystack, $word)) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // Utilitaires
    // =========================================================================

    /** @param array<int, string> $needles */
    private function matches(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
