<?php

namespace App\Services\Ai;

use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Income;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\Property;
use App\Models\PropertyWork;
use App\Models\User;
use App\Services\Ai\Analysis\AnalysisGuard;
use App\Services\Ai\Analysis\AnalysisResult;
use App\Services\Ai\Analysis\DocumentFile;
use App\Services\Ai\Analysis\DuplicateFinder;
use App\Services\Ai\Analysis\Normalizer;
use App\Services\Ai\Analysis\PdfTextFallback;
use App\Services\Ai\Analysis\Prompts\Prompt;
use App\Services\Ai\Exceptions\AiBadResponse;
use App\Services\Ai\Exceptions\AiModelUnavailable;
use App\Services\Ai\Exceptions\AiUnavailable;
use App\Services\BadgeService;
use App\Support\AiStagingUpload;
use App\Support\DocumentStorage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Analyse d'un justificatif et création de l'écriture correspondante.
 *
 * Deux temps volontairement séparés (CDC § 2, hors périmètre : la création sans
 * relecture) :
 *   analyze() ne crée RIEN, il propose ;
 *   apply()   n'invente RIEN, il enregistre ce que l'utilisateur a validé.
 *
 * Journal d'usage : une ligne par appel, écrite par AiClient. Le premier appel
 * (celui que l'utilisateur a demandé) porte `feature = documents` et consomme
 * une unité de quota ; les appels de suivi AUTOMATIQUES (réparation, cascade
 * vers le modèle renforcé) portent `feature = documents_auto`, hors quota :
 * notre choix d'insister ne se facture pas à l'utilisateur.
 */
class DocumentAnalysisService
{
    /** Plafond de réponse : une facture tient large, un relevé a besoin de place. */
    private const MAX_TOKENS = 4_000;
    private const MAX_TOKENS_LONG = 16_000;

    /** Appel demandé par l'utilisateur : compte dans le quota mensuel. */
    private const FEATURE = 'documents';

    /** Suivi automatique (réparation, cascade) : hors quota. */
    private const FEATURE_AUTO = 'documents_auto';

    public function __construct(
        private readonly Normalizer $normalizer = new Normalizer,
        private readonly DuplicateFinder $duplicates = new DuplicateFinder,
    ) {}

    // =========================================================================
    // Analyse
    // =========================================================================

    /**
     * Analyse un justificatif et renvoie une proposition à relire.
     *
     * @param  string|null  $forcedType    Type imposé par le contexte ou l'utilisateur.
     * @param  bool         $strongModel   « Réanalyser avec le modèle renforcé ».
     *
     * @throws \App\Services\Ai\Exceptions\AiException
     * @throws \RuntimeException Fichier refusé (type, taille, PDF illisible).
     */
    public function analyze(
        UploadedFile|string|DocumentFile $file,
        Property $property,
        ?string $forcedType = null,
        bool $strongModel = false,
    ): AnalysisResult {
        // Tout passe par l'utilisateur connecté : les global scopes d'isolation
        // se désactivent silencieusement hors session (CDC § 4.1), donc un
        // traitement détaché lirait les biens de tout le monde.
        $user = auth()->user() ?? $property->user;

        if (! $user instanceof User) {
            throw new AiUnavailable;
        }

        if ($message = AnalysisGuard::check($user)) {
            throw new AiUnavailable($message);
        }

        $document = $file instanceof DocumentFile ? $file : DocumentFile::fromUpload($file);
        $client = AiClient::for($user);
        $messages = $this->buildMessages($document, $property, $forcedType, $client);

        $primaryModel = $strongModel
            ? $this->fallbackModel($client)
            : $client->access()->model('documents');

        // Une réanalyse renforcée est une demande explicite de l'utilisateur :
        // elle consomme une unité de quota, comme le prévoit le CDC § 6.2.
        $pass = $this->firstPass($client, $messages, $primaryModel, $forcedType, $document);

        if (! $strongModel && $this->shouldCascade($client, $pass)) {
            $pass = $this->cascade($client, $messages, $pass, $property, $forcedType);
        } elseif ($pass['failed']) {
            throw $pass['exception'];
        } else {
            $pass = $this->finalize($pass, $property, $forcedType);
        }

        $analysis = $pass['analysis'];

        // Un relevé se dédoublonne ligne par ligne ; un document simple porte
        // une seule écriture, donc une seule alerte.
        if (in_array($analysis->entity(), ['statement', 'earnings'], strict: true)) {
            $analysis = $analysis->withRows($this->markDuplicateRows($analysis->rows, $property->id));

            // ⚠️ Le dédoublonnage ligne à ligne ne suffit PAS pour un relevé annuel.
            // Réimporter le même PDF est bien détecté (dates déterministes, montants
            // identiques au centime) — mais le vrai risque est ailleurs : un bien dont
            // les recettes viennent déjà d'un import CSV porte les MÊMES euros sous une
            // autre maille. Les montants ne coïncident jamais, DuplicateFinder est
            // aveugle, et l'année est comptée deux fois.
            return $analysis->entity() === 'earnings'
                ? $this->guardAgainstFilledYear($analysis, $property)
                : $analysis;
        }

        // Une liasse ne correspond à aucune écriture existante : le dédoublonnage
        // n'a pas de sens, l'assistant de reprise consomme le résultat tel quel.
        if ($analysis->entity() === 'reprise') {
            return $analysis;
        }

        return $analysis
            ->withDuplicate($this->duplicates->find(
                $analysis->entity(),
                $property->id,
                $analysis->values['amount'] ?? null,
                $analysis->values['expense_date'] ?? $analysis->values['purchase_date'] ?? $analysis->values['work_date'] ?? null,
            ));
    }

    /**
     * Marque « déjà saisie » les opérations d'un relevé qui existent déjà, et
     * les décoche : sans cela, réimporter un relevé chevauchant le précédent
     * doublerait des charges — l'erreur la plus coûteuse d'une comptabilité.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function markDuplicateRows(array $rows, int $propertyId): array
    {
        foreach ($rows as $index => $row) {
            $entity = match ($row['kind'] ?? null) {
                'expense' => 'expense',
                'income' => 'income',
                default => null,
            };

            if ($entity === null) {
                continue;
            }

            $isDuplicate = $this->duplicates->exists($entity, $propertyId, (int) $row['amount'], $row['date']);

            $rows[$index]['duplicate'] = $isDuplicate;

            if ($isDuplicate) {
                $rows[$index]['selected'] = false;
            }
        }

        return $rows;
    }

    /**
     * Premier appel, plus une passe de réparation si la réponse n'est pas du
     * JSON exploitable. La réparation rejoue les annotations du parsing déjà
     * facturé : le document n'est pas relu par le fournisseur.
     *
     * @return array{analysis: AnalysisResult|null, model: string, annotations: array, failed: bool}
     */
    private function firstPass(
        AiClient $client,
        array $messages,
        string $model,
        ?string $forcedType,
        DocumentFile $document,
    ): array {
        try {
            $response = $client->completeJson(
                self::FEATURE,
                $messages,
                Prompt::schema(),
                $model,
                $this->options($forcedType, [
                    'document_type_hint' => $forcedType ?? 'auto',
                    'file' => $document->extension(),
                ]),
            );

            return [
                'analysis' => null,
                'response' => $response,
                'model' => $model,
                'annotations' => $response->fileAnnotations,
                'failed' => false,
            ];
        } catch (AiBadResponse $e) {
            // Une seule réparation (CDC § 6.3), et hors quota : l'utilisateur
            // n'a demandé qu'une analyse.
            try {
                $response = $client->completeJson(
                    self::FEATURE_AUTO,
                    [...$messages, ['role' => 'user', 'content' => Prompt::repairInstruction()]],
                    Prompt::schema(),
                    $model,
                    $this->options($forcedType, ['repair' => true], long: true)
                        + ['file_annotations' => $e->fileAnnotations],
                );

                return [
                    'analysis' => null,
                    'response' => $response,
                    'model' => $model,
                    'annotations' => $response->fileAnnotations ?: $e->fileAnnotations,
                    'failed' => false,
                ];
            } catch (AiBadResponse $repairFailure) {
                return [
                    'analysis' => null,
                    'response' => null,
                    'model' => $model,
                    'annotations' => $repairFailure->fileAnnotations ?: $e->fileAnnotations,
                    'failed' => true,
                    'exception' => $repairFailure,
                ];
            }
        } catch (AiModelUnavailable $e) {
            // Modèle indisponible : la cascade est justement là pour ça.
            return [
                'analysis' => null,
                'response' => null,
                'model' => $model,
                'annotations' => [],
                'failed' => true,
                'exception' => $e,
            ];
        }
    }

    /**
     * Faut-il basculer sur le modèle de repli ? JSON inexploitable après
     * réparation, modèle indisponible, ou confiance globale sous le seuil.
     */
    private function shouldCascade(AiClient $client, array $pass): bool
    {
        if ($this->fallbackModel($client) === '') {
            return false;
        }

        if ($pass['failed']) {
            return true;
        }

        $confidence = (float) ($pass['response']->json()['confidence'] ?? 0);

        return $confidence < (float) config('ai.fallback_confidence', 0.6);
    }

    /**
     * Second appel sur AI_MODEL_DOCUMENTS_FALLBACK, avec les mêmes messages et
     * les annotations déjà facturées. On garde la proposition la mieux notée.
     */
    private function cascade(AiClient $client, array $messages, array $pass, Property $property, ?string $forcedType): array
    {
        try {
            $response = $client->completeJson(
                self::FEATURE_AUTO,
                $messages,
                Prompt::schema(),
                $this->fallbackModel($client),
                $this->options($forcedType, ['fallback' => true], long: true)
                    + ['file_annotations' => $pass['annotations']],
            );
        } catch (AiBadResponse|AiModelUnavailable $e) {
            // Le repli a échoué à son tour : on rend la première proposition si
            // elle existait, sinon on remonte l'erreur d'origine.
            if ($pass['failed']) {
                throw $pass['exception'] ?? $e;
            }

            return $this->finalize($pass, $property, $forcedType);
        }

        $candidate = [
            'analysis' => null,
            'response' => $response,
            'model' => $response->model,
            'annotations' => $response->fileAnnotations,
            'failed' => false,
            'fallback' => true,
        ];

        if ($pass['failed']) {
            return $this->finalize($candidate, $property, $forcedType);
        }

        // Les deux passes ont abouti : on garde la mieux notée.
        $best = (float) ($response->json()['confidence'] ?? 0) >= (float) ($pass['response']->json()['confidence'] ?? 0)
            ? $candidate
            : $pass;

        return $this->finalize($best, $property, $forcedType);
    }

    /** Normalisation de la passe retenue. */
    private function finalize(array $pass, Property $property, ?string $forcedType): array
    {
        $pass['analysis'] = $this->normalizer->normalize(
            $pass['response']->json(),
            $property,
            $forcedType,
            $pass['response']->model,
            (bool) ($pass['fallback'] ?? false),
        );

        return $pass;
    }

    /** Messages au format OpenAI : consigne, document, contexte. */
    private function buildMessages(DocumentFile $document, Property $property, ?string $forcedType, AiClient $client): array
    {
        // Le document est porté par SON PROPRE message utilisateur : le socle
        // insère les annotations d'un parsing précédent juste après lui.
        $documentPart = $document->isPdf() && PdfTextFallback::isRequiredFor($client->access()->provider())
            ? PdfTextFallback::messagePart(PdfTextFallback::extract($document))
            : $document->messagePart();

        return [
            ['role' => 'system', 'content' => Prompt::system()],
            ['role' => 'user', 'content' => [$documentPart]],
            ['role' => 'user', 'content' => Prompt::userContext($property, $forcedType)],
        ];
    }

    /**
     * Options de l'appel. `meta` ne reçoit que des COMPTEURS ET DES TYPES :
     * jamais un montant lu, un libellé, un extrait ou un base64 (CDC § 4.9).
     */
    private function options(?string $forcedType, array $meta, bool $long = false): array
    {
        return [
            'max_tokens' => ($long || in_array($forcedType, ['bank_statement', 'loan_schedule', 'liasse_2033'], strict: true))
                ? self::MAX_TOKENS_LONG
                : self::MAX_TOKENS,
            'meta' => $meta,
        ];
    }

    /**
     * Modèle de repli EFFECTIF : AI_MODEL_DOCUMENTS_FALLBACK si l'administrateur
     * l'a posé, sinon le repli par défaut du fournisseur configuré.
     *
     * Passe par AiAccess plutôt que par config() : lire la configuration en
     * direct court-circuiterait la résolution du fournisseur et renverrait un
     * slug OpenRouter à un service qui ne le connaît pas.
     */
    private function fallbackModel(AiClient $client): string
    {
        return $client->access()->model('documents_fallback');
    }

    // =========================================================================
    // Création
    // =========================================================================

    /**
     * Crée l'écriture à partir des valeurs VALIDÉES par l'utilisateur et lui
     * rattache le justificatif.
     *
     * @param  array<string, mixed>  $userValues  État du formulaire de relecture.
     */
    public function apply(AnalysisResult $result, array $userValues, User $user): Model|EloquentCollection
    {
        if ($result->entity() === 'reprise') {
            throw new RuntimeException(
                'Une liasse se reprend depuis l\'assistant « Reprendre un dossier existant », pas ici.'
            );
        }

        return DB::transaction(function () use ($result, $userValues, $user) {
            $created = match ($result->entity()) {
                'furniture' => $this->createFurniture($result, $userValues),
                'work' => $this->createWork($userValues),
                'loan' => $this->createLoan($result, $userValues),
                'statement' => $this->createFromStatement($result, $userValues),
                'earnings' => $this->createIncomes($result, $userValues),
                default => $this->createExpense($userValues),
            };

            $this->attachDocument($result, $created, $userValues);

            app(BadgeService::class)->evaluate($user, 'ai_import_used');

            return $created;
        });
    }

    private function createExpense(array $values): Expense
    {
        return Expense::create($this->only($values, [
            'property_id', 'expense_date', 'amount', 'tva_rate', 'category',
            'description', 'is_dedicated', 'recurring_type', 'notes',
        ]));
    }

    /** Une facture de mobilier peut donner plusieurs lignes amortissables. */
    private function createFurniture(AnalysisResult $result, array $values): Model|EloquentCollection
    {
        $rows = collect($values['rows'] ?? [])->where('selected', true);

        if ($rows->isEmpty()) {
            return Furniture::create($this->only($values, [
                'property_id', 'purchase_date', 'amount', 'tva_rate',
                'description', 'duration_years', 'is_dedicated', 'is_second_hand',
            ]));
        }

        $created = $rows->map(fn (array $row) => Furniture::create([
            'property_id' => $values['property_id'] ?? $result->propertyId,
            'purchase_date' => $values['purchase_date'] ?? null,
            'amount' => (int) $row['amount'],
            'tva_rate' => (int) ($row['tva_rate'] ?? $values['tva_rate'] ?? 0),
            'description' => $row['description'],
            'duration_years' => (int) ($row['duration_years'] ?? Furniture::DURATION_NEW),
            // ⚠️ `?? true`, et pas `?? false`. Les deux branches de cette méthode
            // divergeaient : celle du haut passe par `only()`, qui omet la clé absente
            // et laisse le DÉFAUT DE LA COLONNE s'appliquer — `true` pour `furniture`
            // comme pour `property_works`, et défaut du Toggle du formulaire. Celle-ci
            // forçait `false`, donc la quote-part de surface, donc une dotation rabotée
            // et un bien qui ne s'amortit jamais entièrement. Silencieux : aucun test,
            // aucun message, juste un amortissement trop petit.
            'is_dedicated' => (bool) ($values['is_dedicated'] ?? true),
            'is_second_hand' => (bool) ($row['is_second_hand'] ?? false),
        ]));

        return new EloquentCollection($created->all());
    }

    private function createWork(array $values): PropertyWork
    {
        return PropertyWork::create($this->only($values, [
            'property_id', 'work_date', 'amount', 'tva_rate',
            'description', 'duration_years', 'is_dedicated',
        ]));
    }

    /**
     * Emprunt + échéances RÉELLES de la banque.
     *
     * `schedule_source = imported` protège ensuite ces lignes : LoanService ne
     * les écrase plus sans confirmation explicite (CDC § 5.6).
     */
    private function createLoan(AnalysisResult $result, array $values): Loan
    {
        $loan = Loan::create($this->only($values, [
            'property_id', 'bank_name', 'amount', 'annual_rate', 'duration_months',
            'start_date', 'monthly_payment', 'insurance_monthly', 'insurance_type', 'insurance_rate',
        ]));

        $rows = collect($values['rows'] ?? $result->rows);

        if ($rows->isEmpty()) {
            return $loan;
        }

        foreach ($rows as $index => $row) {
            LoanPayment::create([
                'loan_id' => $loan->id,
                'payment_date' => $row['payment_date'],
                'month_number' => (int) ($row['month_number'] ?? $index + 1),
                'capital_amount' => (int) ($row['capital_amount'] ?? 0),
                'interest_amount' => (int) ($row['interest_amount'] ?? 0),
                'insurance_amount' => (int) ($row['insurance_amount'] ?? 0),
                'remaining_capital' => (int) ($row['remaining_capital'] ?? 0),
            ]);
        }

        $loan->forceFill(['schedule_source' => Loan::SCHEDULE_IMPORTED])->save();

        return $loan->refresh();
    }

    /**
     * Relevé bancaire : création en lot des seules lignes cochées.
     *
     * Les échéances de prêt détectées ne sont jamais créées ici — elles
     * relèvent du tableau d'amortissement, sous peine de compter deux fois le
     * capital remboursé.
     */
    private function createFromStatement(AnalysisResult $result, array $values): EloquentCollection
    {
        $propertyId = $values['property_id'] ?? $result->propertyId;
        $created = [];

        foreach (collect($values['rows'] ?? [])->where('selected', true) as $row) {
            if (($row['kind'] ?? null) === 'expense') {
                $created[] = Expense::create([
                    'property_id' => $propertyId,
                    'expense_date' => $row['date'],
                    'amount' => (int) $row['amount'],
                    'tva_rate' => 0,
                    'category' => $row['category'],
                    'description' => $row['label'],
                    'is_dedicated' => (bool) ($row['is_dedicated'] ?? false),
                    'recurring_type' => 'once',
                ]);

                continue;
            }

            if (($row['kind'] ?? null) === 'income') {
                $created[] = Income::create([
                    'property_id' => $propertyId,
                    'income_date' => $row['date'],
                    'amount' => (int) $row['amount'],
                    'tva_rate' => 0,
                    'source' => $row['source'] ?? Income::SOURCE_DIRECT,
                    'notes' => $row['label'],
                ]);
            }
        }

        return new EloquentCollection($created);
    }

    // =========================================================================
    // Pièce justificative
    // =========================================================================

    /**
     * Déplace le fichier déposé vers son emplacement définitif et le rattache.
     *
     * Pour un relevé, le MÊME fichier est rattaché à chaque écriture créée :
     * une ligne `documents` par entité, un seul fichier sur le disque.
     */
    private function attachDocument(AnalysisResult $result, Model|EloquentCollection $created, array $values): void
    {
        if (blank($result->filePath)) {
            return;
        }

        $records = $created instanceof EloquentCollection ? $created->all() : [$created];

        if ($records === []) {
            return;
        }

        $path = $this->moveToFinalLocation($result);

        if ($path === null) {
            return;
        }

        $label = Str::limit(
            (string) ($values['description'] ?? $result->values['description'] ?? $result->fileName ?? 'Justificatif'),
            120,
            '',
        );

        foreach ($records as $record) {
            if (! method_exists($record, 'documents')) {
                continue;
            }

            $record->documents()->create([
                'label' => $label ?: 'Justificatif',
                'amount' => $record->amount ?? null,
                // ⚠️ `income_date` et `period_end` pour un relevé annuel : sans eux la
                // chaîne rend null, et DocumentExportService classe la pièce en
                // « sans-annee ».
                'document_date' => $values['expense_date']
                    ?? $values['purchase_date']
                    ?? $values['work_date']
                    ?? $values['start_date']
                    ?? $values['income_date']
                    ?? $values['period_end']
                    ?? null,
                'file_path' => $path,
                'sort_order' => 0,
            ]);
        }
    }

    /** documents/{user_id}/{type}/{date}_{slug}_{alea}.{ext} — patron d'AttachDocument. */
    private function moveToFinalLocation(AnalysisResult $result): ?string
    {
        $disk = Storage::disk();

        if (! $disk->exists($result->filePath)) {
            return null;
        }

        $extension = strtolower(pathinfo((string) $result->fileName, PATHINFO_EXTENSION)) ?: 'pdf';
        $slug = Str::slug(pathinfo((string) $result->fileName, PATHINFO_FILENAME)) ?: 'justificatif';
        $directory = (DocumentStorage::directory($this->storageFolder($result)))();

        $path = $directory . '/' . now()->format('Y-m-d') . '_' . $slug . '_' . Str::random(8) . '.' . $extension;

        // ⚠️ `DocumentStorage::putProtected()` et non `$disk->put()`. Cette ligne
        // recopiait le clair du staging vers `documents/{user_id}/` sans passer par
        // le coffre : un justificatif NU posé au milieu de justificatifs scellés,
        // sur un compte dont le propriétaire croit tout avoir chiffré. C'est le seul
        // fichier qu'il n'a pas déposé lui-même, donc celui qu'il ne pensera jamais
        // à vérifier. Rien ne cassait, aucun test ne rougissait.
        //
        // Le flux, et non `(string) $disk->get()` : un relevé de 10 Mo n'a pas à
        // passer entier par la mémoire pour être rangé.
        $source = $disk->readStream($result->filePath);

        try {
            DocumentStorage::putProtected($source, $path, size: $disk->size($result->filePath));
        } finally {
            is_resource($source) && fclose($source);
        }

        AiStagingUpload::discard($result->filePath);

        return $path;
    }

    /**
     * Relevé annuel de plateforme : une recette par mois.
     *
     * `amount` porte le BRUT et `platform_fee` la retenue du mois — l'écart entre les
     * deux colonnes imprimées, jamais une répartition : le relevé les publie toutes
     * deux, mois par mois, et leurs sommes retombent au centime sur le récapitulatif.
     *
     * ⚠️ La date est recomposée depuis l'année VALIDÉE par l'utilisateur, jamais depuis
     * celle qu'a proposée le modèle. C'est le seul champ de l'en-tête dont dépendent
     * toutes les lignes, et il est éditable précisément pour ça : sans cela, corriger
     * l'année à l'écran ne changerait rien aux recettes écrites.
     *
     * @param  array<string, mixed>  $values
     */
    private function createIncomes(AnalysisResult $result, array $values): EloquentCollection
    {
        $propertyId = (int) ($values['property_id'] ?? $result->propertyId);
        $annee = (int) ($values['fiscal_year'] ?? 0) ?: null;
        $source = (string) ($values['source'] ?? Income::SOURCE_OTHER);
        $tvaRate = (int) ($values['tva_rate'] ?? 0);
        $created = [];

        foreach (collect($values['rows'] ?? [])->where('selected', true) as $row) {
            $montant = (int) ($row['amount'] ?? 0);
            $date = $this->monthlyDate($annee, $row);

            if ($montant <= 0 || $date === null) {
                continue;
            }

            $created[] = Income::create([
                'property_id' => $propertyId,
                'income_date' => $date,
                'amount' => $montant,
                'tva_rate' => $tvaRate,
                'platform_fee' => (int) ($row['platform_fee'] ?? 0),
                // ⚠️ Zéro, et pas une répartition : le relevé ne ventile la taxe de
                // séjour sur aucun mois, elle n'est pas comprise dans les revenus bruts,
                // et aucun calcul de l'application ne lit cette colonne.
                'tourist_tax' => 0,
                'source' => $source,
                // ⚠️ Pas de `reservation_ref` : un relevé annuel n'en porte aucun.
                // Conséquence assumée — AirbnbImportService dédoublonne par ce SEUL
                // champ, il ne verra donc pas ces lignes. Un import CSV postérieur
                // doublerait l'année. C'est dit dans l'aide, et `notes` porte le mois
                // pour qu'un humain le repère dans la liste.
                'notes' => $row['label'] ?? null,
            ]);
        }

        return new EloquentCollection($created);
    }

    /** Dernier jour du mois, depuis l'année validée ; repli sur la date proposée. */
    private function monthlyDate(?int $annee, array $row): ?string
    {
        $mois = (int) ($row['month'] ?? 0);

        if ($annee !== null && $mois >= 1 && $mois <= 12) {
            return CarbonImmutable::createFromDate($annee, $mois, 1)->endOfMonth()->toDateString();
        }

        return $row['date'] ?? null;
    }

    /**
     * Décoche tout et prévient quand l'exercice porte déjà des recettes.
     *
     * ⚠️ C'est le seul garde-fou contre l'erreur la plus coûteuse d'une comptabilité :
     * compter une année deux fois. Elle survient quand les recettes ont déjà été
     * saisies au fil de l'eau ou importées par CSV — les mêmes euros sous une autre
     * maille, que AUCUN dédoublonnage ligne à ligne ne peut voir, les montants ne
     * coïncidant jamais.
     */
    private function guardAgainstFilledYear(AnalysisResult $analysis, Property $property): AnalysisResult
    {
        $annee = $analysis->values['fiscal_year'] ?? null;

        if ($annee === null) {
            return $analysis;
        }

        $existant = Income::query()
            ->where('property_id', $property->id)
            ->whereYear('income_date', $annee)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(amount), 0) as total')
            ->first();

        if ((int) $existant->n === 0) {
            return $analysis;
        }

        // ⚠️ array_replace et pas `+` : l'opérateur d'union ne REMPLACE pas une clé
        // déjà présente, et `selected` l'est toujours.
        return $analysis
            ->withRows(array_map(
                static fn (array $row) => array_replace($row, ['selected' => false]),
                $analysis->rows,
            ))
            ->withWarnings([...$analysis->warnings, sprintf(
                'Ce bien porte déjà %d recette(s) en %d, pour %s € au total. Un relevé annuel fait '
                . 'double emploi avec un import CSV ou une saisie au fil de l\'eau : n\'importez pas '
                . 'les deux. Toutes les lignes ont été décochées par précaution.',
                (int) $existant->n,
                $annee,
                number_format(((int) $existant->total) / 100, 2, ',', ' '),
            )]);
    }

    private function storageFolder(AnalysisResult $result): string
    {
        return match ($result->entity()) {
            'furniture' => 'mobilier',
            'work' => 'travaux',
            'loan' => 'emprunts',
            'statement' => 'releves',
            'earnings' => 'recettes',
            'reprise' => 'reprise',
            default => 'pieces-comptables',
        };
    }

    /** @param array<int, string> $keys */
    private function only(array $values, array $keys): array
    {
        return array_filter(
            array_intersect_key($values, array_flip($keys)),
            fn ($value) => $value !== null,
        );
    }

    /** Supprime un fichier resté en attente (analyse annulée). */
    public function discard(?string $path): void
    {
        // N'efface que dans le dossier de l'utilisateur connecté : le chemin a pu
        // transiter par l'état Livewire (GHSA-j4gm-g8m8-93x2).
        if (! auth()->check() || ! \App\Support\DocumentStorage::isOwnedBy($path, (int) auth()->id())) {
            return;
        }

        if (filled($path) && Storage::disk()->exists($path)) {
            Storage::disk()->delete($path);
        }
    }
}
