<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\NavigationAware;
use App\Filament\Schemas\AnalysisPreview;
use App\Models\Expense;
use App\Models\Property;
use App\Models\User;
use App\Services\Ai\Analysis\AnalysisGuard;
use App\Services\Ai\Analysis\AnalysisResult;
use App\Services\Ai\Analysis\Prompts\Prompt;
use App\Services\Ai\DocumentAnalysisService;
use App\Services\Ai\Exceptions\AiException;
use App\Support\AiAccess;
use App\Support\AiQuota;
use App\Support\Analytics;
use App\Support\AiStagingUpload;
use App\Support\DocumentStorage;
use App\Support\DossierContext;
use App\Support\Vault;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use RuntimeException;
use UnitEnum;

/**
 * « Importer un justificatif » — dépôt, analyse, relecture, création.
 *
 * L'écran de relecture est le cœur de la fonctionnalité : rien n'est jamais
 * créé à partir de la proposition du modèle, seulement à partir de ce que
 * l'utilisateur a validé. C'est ce qui rend acceptable qu'un modèle se trompe.
 */
class ImportDocument extends Page implements HasForms
{
    use InteractsWithForms, NavigationAware, WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;
    protected static string|UnitEnum|null $navigationGroup = 'Comptabilité';
    protected static ?string $navigationLabel = 'Importer un justificatif';
    protected static ?string $title = 'Importer un justificatif';
    // Après Recettes (1) et Charges (2) : la saisie courante d'abord, l'import
    // ensuite. À 2, l'égalité avec Charges plaçait l'entrée au milieu du groupe.
    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.import-document';

    /** Dossier de dépôt temporaire, sous l'arborescence de l'utilisateur. */
    /** @see AiStagingUpload — le répertoire, sa purge et l'exclusion du coffre y vivent ensemble. */
    private const STAGING = AiStagingUpload::SUBDIRECTORY;

    /** Formulaire de dépôt. */
    public ?array $data = [];

    /** Formulaire de relecture (valeurs éditables). */
    public ?array $values = [];

    /**
     * Proposition sérialisée — état Livewire. Verrouillée : son `file_path` part dans
     * `discard()` et dans le justificatif créé, le navigateur ne doit pas le choisir.
     */
    #[Locked]
    public ?array $analysis = null;

    /** Consentement à obtenir avant le premier envoi à un tiers. */
    public bool $consentNeeded = false;

    public static function canAccess(): bool
    {
        // Un compte membre n'a jamais de biens à lui : cette analyse rattache un
        // justificatif à un bien du principal (lot « dossiers membre », décision du
        // 2026-10-15).
        return (bool) config('ai.enabled') && ! DossierContext::isMemberActingAsPrincipal();
    }

    protected static function getGuidedNavigationGroup(): string
    {
        return 'Au quotidien';
    }

    public function getSubheading(): ?string
    {
        return 'Déposez une facture, un avis de taxe foncière, une attestation d\'assurance, '
            . 'un tableau d\'amortissement ou un relevé : l\'analyse vous propose une écriture, vous la relisez.';
    }

    public function mount(): void
    {
        $this->form->fill();
        // Consentement du PRINCIPAL (plan des dossiers liés § 2.3), pas du dossier actif :
        // c'est son compte qui autorise l'appel à un fournisseur d'IA externe.
        $this->consentNeeded = blank($this->billingUser()->ai_consented_at);
    }

    // =========================================================================
    // Schémas
    // =========================================================================

    protected function getForms(): array
    {
        return ['form', 'previewForm'];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Document à analyser')
                    ->description('PDF, JPEG, PNG, WebP ou HEIC (converti en JPEG par votre navigateur), 10 Mo maximum. Le document est envoyé à un service d\'intelligence artificielle pour en extraire les données ; rien n\'est enregistré sans votre relecture.')
                    ->schema([
                        Select::make('property_id')
                            ->label('Bien concerné')
                            ->options(Property::pluck('name', 'id'))
                            ->required()
                            ->preload()
                            ->placeholder('Sélectionner un bien...')
                            ->default(fn () => ($ids = Property::pluck('id'))->count() === 1 ? $ids->first() : null),
                        Select::make('forced_type')
                            ->label('Type de document')
                            ->options(static::typeOptions())
                            ->default('')
                            ->helperText('Laissez sur « Détection automatique » dans le doute.'),
                        FileUpload::make('file')
                            ->label('Justificatif')
                            // HEIC/HEIF acceptés à la sélection : convertis en JPEG dans le
                            // navigateur avant téléversement (resources/js/heic-upload.js).
                            // Le serveur, lui, continue de refuser un HEIC brut.
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'])
                            ->directory(DocumentStorage::directory(self::STAGING))
                            ->preventFilePathTampering(allowFilePathUsing: DocumentStorage::belongsToCurrentUser())
                            ->maxSize(10240)
                            ->required(),
                    ])
                    ->footerActions([
                        Action::make('analyze')
                            ->label('Analyser')
                            ->icon('heroicon-o-sparkles')
                            ->action('analyze')
                            ->color('primary')
                            ->disabled(fn () => ! $this->isUsable()),
                    ]),
            ]);
    }

    public function previewForm(Schema $schema): Schema
    {
        $schema->statePath('values');

        if ($this->analysis === null) {
            return $schema->components([]);
        }

        return AnalysisPreview::configure($schema, $this->result());
    }

    // =========================================================================
    // Actions
    // =========================================================================

    /** Horodate le consentement — jamais réécrit s'il existe déjà (partagé avec l'assistant). */
    public function acceptConsent(): void
    {
        $user = $this->billingUser();

        if (blank($user->ai_consented_at)) {
            $user->forceFill(['ai_consented_at' => now()])->save();
        }

        $this->consentNeeded = false;
    }

    public function analyze(): void
    {
        if ($this->consentNeeded) {
            return;
        }

        $data = $this->form->getState();
        $property = Property::find($data['property_id'] ?? null);
        $path = $this->resolveUploadedPath($data['file'] ?? null);

        if ($property === null || $path === null) {
            Notification::make()->title('Sélectionnez un bien et un document.')->danger()->send();

            return;
        }

        try {
            $analysis = app(DocumentAnalysisService::class)->analyze(
                $path,
                $property,
                filled($data['forced_type'] ?? null) ? $data['forced_type'] : null,
            );
        } catch (AiException $e) {
            $this->fail($e->getMessage(), $e->code());

            return;
        } catch (RuntimeException $e) {
            // Fichier refusé (type réel, taille, PDF illisible) : message métier.
            $this->fail($e->getMessage(), 'FileRejected');

            return;
        }

        $this->present($analysis->withFile($path, basename($path)));

        $this->dispatch('analytics', [
            'event' => 'ai_doc_analyzed',
            'document_type' => $analysis->documentType,
            'confidence_bucket' => Analytics::confidenceBucket($analysis->confidence),
        ]);
    }

    /** « Réanalyser avec le modèle renforcé » — compte dans le quota mensuel. */
    public function reanalyzeStrong(): void
    {
        $result = $this->result();
        $property = Property::find($result->propertyId);

        if ($property === null || blank($result->filePath)) {
            return;
        }

        try {
            $analysis = app(DocumentAnalysisService::class)->analyze(
                $result->filePath,
                $property,
                $result->documentType,
                strongModel: true,
            );
        } catch (AiException $e) {
            $this->fail($e->getMessage(), $e->code());

            return;
        }

        $this->present($analysis->withFile($result->filePath, $result->fileName));

        Notification::make()
            ->title('Nouvelle analyse effectuée avec le modèle renforcé')
            ->success()
            ->send();
    }

    /** Change le type proposé : le formulaire de relecture bascule. */
    public function changeType(string $type): void
    {
        // Liste de la page, pas Prompt::TYPES : la liasse (`liasse_2033`) se
        // traite dans l'assistant de reprise, jamais ici — son entité « reprise »
        // ne crée aucune écriture et apply() la refuse.
        if ($type === '' || ! array_key_exists($type, static::typeOptions())) {
            return;
        }

        // ⚠️ Ce que l'utilisateur a CORRIGÉ À LA MAIN doit survivre au changement de
        // type. Sans ça, `present()` refait `previewForm->fill($analysis->values)` et
        // écrase en silence un montant rectifié, une date corrigée ou un interrupteur
        // « 100 % dédié » basculé — précisément les gestes qu'on lui demande de faire.
        //
        // On ne conserve QUE les écarts : une valeur laissée telle quelle n'est pas
        // réimposée par-dessus la nouvelle proposition, qui peut légitimement différer
        // pour le nouveau type. Et on lit `$this->values` (l'état brut du statePath)
        // plutôt que `previewForm->getState()`, qui déshydraterait.
        $corrections = $this->userCorrections();

        $this->present($this->result()->withDocumentType($type));

        if ($corrections === []) {
            return;
        }

        // Seules les clés que le NOUVEAU type propose : passer `work_date` à une
        // charge n'aurait aucun sens.
        $retenues = array_intersect_key($corrections, $this->values);

        if ($retenues !== []) {
            $this->values = array_merge($this->values, $retenues);
        }
    }

    /**
     * Valeurs qui s'écartent de la proposition du modèle — donc corrigées à la main.
     *
     * @return array<string, mixed>
     */
    private function userCorrections(): array
    {
        $proposees = $this->analysis['values'] ?? null;

        if (! is_array($proposees)) {
            return [];
        }

        $corrections = [];

        foreach ($this->values as $champ => $valeur) {
            if ($champ === 'rows') {
                continue;
            }

            if (! array_key_exists($champ, $proposees) || $proposees[$champ] !== $valeur) {
                $corrections[$champ] = $valeur;
            }
        }

        return $corrections;
    }

    public function confirm(bool $andNext = false): void
    {
        $result = $this->result();
        $values = $this->previewForm->getState();
        $values['rows'] = $this->values['rows'] ?? $result->rows;

        try {
            app(DocumentAnalysisService::class)->apply($result, $values, $this->user());
        } catch (\Throwable $e) {
            report($e);
            $this->fail("L'enregistrement a échoué. Vérifiez les champs et réessayez.", 'ApplyFailed');

            return;
        }

        $this->dispatch('analytics', [
            'event' => 'ai_doc_confirmed',
            'document_type' => $result->documentType,
            'fields_corrected' => $this->countCorrections($result, $values),
        ]);

        Notification::make()
            ->title('Écriture enregistrée')
            ->body('Le justificatif a été rattaché automatiquement.')
            ->success()
            ->send();

        $this->reset(['analysis', 'values']);

        if ($andNext) {
            $this->form->fill(['property_id' => $result->propertyId]);

            return;
        }

        $this->form->fill();
    }

    public function confirmAndNext(): void
    {
        $this->confirm(andNext: true);
    }

    public function cancel(): void
    {
        app(DocumentAnalysisService::class)->discard($this->analysis['file_path'] ?? null);

        $this->dispatch('analytics', [
            'event' => 'ai_doc_cancelled',
            'document_type' => (string) ($this->analysis['document_type'] ?? 'unknown'),
        ]);

        $this->reset(['analysis', 'values']);
        $this->form->fill();
    }

    // =========================================================================
    // État affiché par la vue
    // =========================================================================

    /**
     * Avertissement destiné au porteur d'un coffre-fort actif, ou null.
     *
     * ⚠️ Ce message est le point le plus important de cet écran, et il n'a rien
     * de technique. Quelqu'un qui a pris la peine de chiffrer ses justificatifs
     * croit — légitimement — que ses fichiers ne sortent pas. Or l'analyse envoie
     * le document EN CLAIR à un tiers : c'est la seule façon qu'il ait de le lire.
     * Le coffre scelle ce qui est ENREGISTRÉ, il ne protège pas ce qu'on choisit
     * de faire analyser. Taire cette nuance serait laisser une promesse implicite
     * démentir la promesse explicite.
     */
    public function vaultWarning(): ?string
    {
        if (! Vault::isEnabledFor($this->user())) {
            return null;
        }

        return 'Votre coffre-fort chiffre ce document dès qu\'il est enregistré. '
            . 'L\'analyse, elle, l\'envoie en clair au service d\'intelligence artificielle : '
            . 'c\'est la seule façon qu\'il ait de le lire. Si ce document ne doit sortir sous '
            . 'aucune forme, saisissez-le à la main.';
    }

    /** Message d'indisponibilité (IA coupée, compte démo, aucune clé, quota), ou null. */
    public function unavailableMessage(): ?string
    {
        $access = AiAccess::for($this->user());

        if (! $access->available()) {
            return $access->unavailableMessage();
        }

        // Lecture seule du quota : c'est AnalysisGuard, au moment de l'analyse,
        // qui journalise un refus — afficher l'encart ne doit rien écrire.
        $quota = AiQuota::for($this->user(), AiQuota::FEATURE_DOCUMENTS);

        return $quota->allowed() ? null : $quota->exhaustedMessage();
    }

    public function isUsable(): bool
    {
        return $this->unavailableMessage() === null;
    }

    /** « 12 / 30 documents analysés ce mois-ci », ou null si illimité. */
    public function quotaLabel(): ?string
    {
        return AnalysisGuard::remainingLabel($this->user());
    }

    public function result(): AnalysisResult
    {
        return AnalysisResult::fromArray($this->analysis ?? []);
    }

    /** URL signée temporaire pour l'aperçu du document déposé. */
    public function previewUrl(): ?string
    {
        return DocumentStorage::temporaryUrl($this->analysis['file_path'] ?? null, 15);
    }

    /**
     * Le document déposé est-il une image ?
     *
     * Une image s'affiche en `<img>`, sans cadre ni visionneuse : c'est plus
     * léger et insensible aux restrictions d'affichage en cadre. Seul un PDF a
     * besoin d'une `<iframe>`.
     */
    public function previewIsImage(): bool
    {
        $extension = strtolower(pathinfo((string) ($this->analysis['file_name'] ?? ''), PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], strict: true);
    }

    /**
     * Faut-il proposer « Réanalyser avec le modèle renforcé » ?
     *
     * Non au-dessus du seuil de confiance : l'extraction est sûre, un appel de
     * plus serait facturé pour rien. Non plus si la cascade a déjà eu lieu —
     * relancer le même modèle de repli redonnerait le même résultat.
     */
    public function canReanalyze(): bool
    {
        $result = $this->result();

        // Modèle de repli EFFECTIF : celui de l'instance, ou celui du
        // fournisseur à défaut. Lire la configuration en direct proposerait le
        // bouton là où aucune cascade n'est possible.
        if ($result->fallbackUsed || blank(AiAccess::for($this->user())->model('documents_fallback'))) {
            return false;
        }

        return $result->confidence < (float) config('ai.reanalysis_threshold', 0.85);
    }

    /** @return array<string, string> */
    public static function typeOptions(): array
    {
        return [
            '' => 'Détection automatique',
            'expense_invoice' => 'Facture de charge',
            'furniture_invoice' => 'Facture de mobilier',
            'works_invoice' => 'Facture de travaux',
            'property_tax_notice' => 'Avis de taxe foncière / CFE',
            'insurance' => 'Assurance',
            'loan_schedule' => 'Tableau d\'amortissement',
            'bank_statement' => 'Relevé bancaire',
            'income_statement' => 'Relevé annuel de plateforme',
        ];
    }

    /** Libellés des catégories, pour les listes déroulantes des lignes de relevé. */
    public function categoryOptions(): array
    {
        return Expense::categoryLabels();
    }

    // =========================================================================
    // Interne
    // =========================================================================

    private function user(): User
    {
        return auth()->user();
    }

    /**
     * Compte qui autorise/paie l'appel IA (consentement, clé, quota) : le principal
     * si l'on travaille depuis un dossier lié, `$this->user()` sinon. Les données
     * analysées, elles, restent attachées à `$this->user()` (le dossier actif).
     */
    private function billingUser(): User
    {
        return \App\Support\DossierContext::principal($this->user()) ?? $this->user();
    }

    /** Charge la proposition dans l'état et pré-remplit le formulaire de relecture. */
    private function present(AnalysisResult $analysis): void
    {
        $this->analysis = $analysis->toArray();

        // ⚠️ Filament met le schéma en cache dès le premier accès. Or `analyze()`
        // commence par `$this->form->getState()`, qui construit TOUS les schémas
        // déclarés dans getForms() — dont celui-ci, alors qu'il n'y a encore rien
        // à afficher. Sans cette invalidation, l'écran de relecture reste vide.
        unset($this->cachedSchemas['previewForm']);

        // Le schéma de relecture dépend du type de document : il est reconstruit
        // ici, puis rempli avec les valeurs proposées.
        $this->previewForm->fill($analysis->values);

        // Les lignes (articles, échéances, opérations) vivent hors du schéma
        // Filament : elles sont pilotées directement par la vue.
        $this->values['rows'] = $analysis->rows;
    }

    /**
     * Page de recharge de crédits du fournisseur, ou null s'il n'en a pas — ou
     * si la clé n'appartient pas à l'utilisateur, qui n'y pourrait rien.
     */
    private function creditsUrl(): ?string
    {
        $access = AiAccess::for($this->user());

        if ($access->source() !== AiAccess::SOURCE_USER) {
            return null;
        }

        return $access->provider()->creditsUrl();
    }

    private function fail(string $message, string $code): void
    {
        $notification = Notification::make()->title($message)->danger();

        // Crédits épuisés : sans lien, l'utilisateur est dans une impasse — le
        // message dit ce qui ne va pas, pas où le régler. Le bouton n'apparaît
        // que si c'est SA clé : avec une clé d'instance, il n'y peut rien, et
        // le message s'adresse en réalité à l'administrateur.
        if ($code === 'AiInsufficientCredits' && ($url = $this->creditsUrl()) !== null) {
            $notification->actions([
                NotificationAction::make('credits')
                    ->label('Ajouter du crédit')
                    ->url($url, shouldOpenInNewTab: true),
            ]);
        }

        $notification->send();

        $this->dispatch('analytics', [
            'event' => 'ai_doc_error',
            'error_code' => $code,
        ]);
    }

    /**
     * Chemin du fichier déposé sur le disque.
     *
     * Filament stocke le fichier et met son chemin dans l'état ; en cours de
     * requête d'upload c'est encore un TemporaryUploadedFile — même dualité
     * que dans ImportAirbnb::resolveUploadedFile().
     */
    private function resolveUploadedPath(mixed $file): ?string
    {
        if (is_array($file)) {
            $file = reset($file) ?: null;
        }

        if (is_string($file)) {
            // Le chemin vient de l'état Livewire : seul un fichier déposé par
            // l'utilisateur connecté, dans son dossier d'attente, est analysé.
            $staging = (DocumentStorage::directory(self::STAGING))() . '/';

            if (! DocumentStorage::isOwnedBy($file, (int) auth()->id()) || ! str_starts_with($file, $staging)) {
                return null;
            }

            return Storage::disk()->exists($file) ? $file : null;
        }

        if ($file instanceof UploadedFile) {
            $path = (DocumentStorage::directory(self::STAGING))() . '/' . $file->hashName();
            Storage::disk()->put($path, (string) file_get_contents($file->getRealPath()));

            return $path;
        }

        return null;
    }

    /**
     * Nombre de champs que l'utilisateur a corrigés : mesure la qualité réelle
     * de l'extraction (objectif « > 80 % acceptés sans correction »), sans
     * jamais enregistrer les valeurs elles-mêmes.
     */
    private function countCorrections(AnalysisResult $result, array $values): int
    {
        $corrected = 0;

        foreach ($result->values as $key => $proposed) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            if ((string) $values[$key] !== (string) $proposed) {
                $corrected++;
            }
        }

        return $corrected;
    }
}
