<?php

namespace App\Filament\Pages;

use App\Filament\Actions\SireneCheckAction;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\Ai\AiClient;
use App\Services\Ai\OpenRouter\OpenRouterOauth;
use App\Services\Ai\Providers\ModelCatalog;
use App\Services\OpenData\SireneClient;
use App\Support\AiAccess;
use App\Support\McpDemo;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use App\Support\Indivision;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use SensitiveParameter;

class EditProfile extends BaseEditProfile
{
    public static function isSimple(): bool
    {
        return false;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Le coffre-fort vit dans « Paramètres », visible seulement en mode
            // avancé — or trois utilisateurs sur quatre sont en mode simple, et
            // n'ont donc aucun chemin vers lui. Ce raccourci en ouvre un depuis le
            // profil, qui est accessible dans tous les modes.
            //
            // Un LIEN, pas une copie des actions : dupliquer activation, changement
            // de phrase et désactivation créerait deux écrans à tenir d'accord, et
            // c'est le genre d'écart qui finit par livrer un bouton qui ment.
            Action::make('coffre')
                ->label(fn () => \App\Support\Vault::isEnabledFor(auth()->user())
                    ? 'Coffre-fort des justificatifs'
                    : 'Chiffrer mes justificatifs')
                ->icon('heroicon-o-lock-closed')
                ->color('gray')
                ->visible(fn () => \App\Support\Vault::availableOnInstance()
                    && (\App\Support\Vault::canBeEnabledBy(auth()->user())
                        || \App\Support\Vault::isEnabledFor(auth()->user())))
                ->url(fn () => \App\Filament\Pages\Vault::getUrl()),

            Action::make('restoreOnboarding')
                ->label('Réafficher le guide de démarrage')
                ->icon('heroicon-o-rocket-launch')
                ->color('gray')
                ->visible(fn () => auth()->user()->onboarding_dismissed_at !== null)
                ->action(function () {
                    auth()->user()->update(['onboarding_dismissed_at' => null]);
                    Notification::make()
                        ->title('Guide réactivé')
                        ->body('Le guide de démarrage est de nouveau visible sur le tableau de bord.')
                        ->success()
                        ->send();
                }),

            $this->deleteAccountAction(),
        ];
    }

    /**
     * « Supprimer mon compte » — droit à l'effacement (RGPD, art. 17). Toute la
     * logique (refus, purge, sort des dossiers d'indivision partagés) vit dans
     * `App\Services\AccountDeletionService`, partagée avec la commande
     * `openlmnp:account-delete`.
     *
     * ⚠️ Masquée entièrement pour un compte de démonstration ou un dossier lié (pas
     * de suppression qui leur soit propre, voir le service) : pour les deux cas
     * restants — dernier administrateur, abonnement cloud potentiellement actif —
     * l'action reste VISIBLE et s'ouvre quand même, mais sa modale devient purement
     * informative (pas de formulaire, pas de bouton de confirmation) : masquer le
     * bouton entier aurait fait croire que la fonctionnalité n'existe pas, alors
     * qu'elle existe et explique pourquoi elle refuse.
     */
    protected function deleteAccountAction(): Action
    {
        $service = app(AccountDeletionService::class);

        return Action::make('deleteAccount')
            ->label('Supprimer mon compte')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (): bool => ($user = auth()->user()) !== null
                && ! $user->is_linked_dossier
                && ! $user->isDemo())
            ->modalHeading('Supprimer mon compte')
            ->modalDescription('Cette action est définitive et irréversible.')
            ->modalSubmitActionLabel('Supprimer définitivement')
            ->modalSubmitAction(fn () => $service->selfServiceRefusalReason(auth()->user()) !== null ? false : null)
            ->modalCancelActionLabel('Annuler')
            ->schema(function () use ($service): array {
                $user = auth()->user();
                $blocked = $service->selfServiceRefusalReason($user);

                if ($blocked !== null) {
                    return [
                        Placeholder::make('deleteAccountBlocked')
                            ->hiddenLabel()
                            ->content($service->refusalMessage($blocked)),
                    ];
                }

                return [
                    Placeholder::make('deleteAccountConsequences')
                        ->hiddenLabel()
                        ->content(new HtmlString($this->deleteAccountConsequences($service->plan($user)))),
                    TextInput::make('password')
                        ->label('Mot de passe actuel')
                        ->password()
                        ->revealable()
                        ->required()
                        ->rule('current_password'),
                    TextInput::make('email_confirmation')
                        ->label('Confirmez en saisissant votre adresse e-mail')
                        ->placeholder($user->email)
                        ->required()
                        ->rules([
                            fn () => function (string $attribute, $value, \Closure $fail) use ($user) {
                                if (mb_strtolower(trim((string) $value)) !== mb_strtolower((string) $user->email)) {
                                    $fail('L\'adresse saisie ne correspond pas à celle de votre compte.');
                                }
                            },
                        ]),
                ];
            })
            ->action(function (array $data) use ($service): void {
                $user = auth()->user();

                if ($service->selfServiceRefusalReason($user) !== null) {
                    // La modale était purement informative (pas de bouton de
                    // soumission) : un appel ici n'arriverait qu'en contournant le
                    // navigateur. Rien à faire, rien à supprimer.
                    return;
                }

                // ⚠️ Déconnexion AVANT suppression, et c'est capital : si le compte
                // porte un `remember_token` (posé par défaut à la création), l'appel
                // `Auth::logout()` fait cycler ce jeton et RÉÉCRIT la ligne via
                // `EloquentUserProvider::updateRememberToken()` → `$user->save()`.
                // Après suppression, `$user->exists` vaut `false` et ce `save()`
                // devient un INSERT qui RESSUSCITE le compte (constaté : la ligne
                // revenait avec un nouvel `is_admin=true`, posé par le même réflexe
                // « premier compte de la base » qui avait motivé ce chantier). Supprimer
                // en dernier évite tout `save()` postérieur sur ce modèle.
                Auth::logout();

                $service->delete($user);

                // ⚠️ `session()` (résolu depuis le conteneur), PAS `request()->session()` :
                // cette dernière ne répond qu'après le passage de `StartSession`, qui ne
                // couvre pas les appels Livewire internes d'un test (« Session store not
                // set on request. »). Le helper global atteint directement le même store.
                session()->invalidate();
                session()->regenerateToken();

                $this->redirect('/');
            });
    }

    /**
     * @param  array{properties: int, fiscal_years: int, mcp_tokens: int, vault_enabled: bool,
     *               documents_on_disk: bool, dossiers_deleted: list<string>, dossiers_kept: list<string>}  $plan
     */
    private function deleteAccountConsequences(array $plan): string
    {
        $lignes = [
            'Seront supprimés : vos biens, exercices fiscaux, recettes, charges, amortissements, '
                .'travaux, mobilier, emprunts, justificatifs (y compris dans le coffre-fort si vous '
                .'l\'avez activé) et jetons MCP.',
        ];

        if ($plan['dossiers_deleted'] !== []) {
            $lignes[] = 'Vous êtes l\'unique membre actif du ou des dossiers d\'indivision suivants : '
                .e(implode(', ', $plan['dossiers_deleted']))
                .'. Ils seront supprimés avec leurs propres données, faute de qui que ce soit pour les faire vivre.';
        }

        if ($plan['dossiers_kept'] !== []) {
            $lignes[] = 'Vous quitterez le ou les dossiers d\'indivision partagés suivants, qui resteront '
                .'accessibles aux autres indivisaires : '.e(implode(', ', $plan['dossiers_kept'])).'.';
        }

        return '<p>'.implode('</p><p>', $lignes).'</p>';
    }

    public function form(Schema $schema): Schema
    {
        $timezones = collect(DateTimeZone::listIdentifiers())
            ->mapWithKeys(fn (string $tz) => [$tz => $tz])
            ->toArray();

        // ⚠️ `(bool)` explicite : un User tout juste créé dans la MÊME requête (jamais
        // relu depuis la base) n'a pas encore cette colonne en mémoire et vaut `null`,
        // pas `false` — `disabled()` est typé strictement `Closure|bool` et lève un
        // TypeError sur `null` (constaté en test).
        $isLinkedDossier = (bool) $this->getUser()->is_linked_dossier;

        return $schema
            ->components([
                $this->getNameFormComponent()
                    ->label($isLinkedDossier ? 'Nom du dossier' : 'Nom'),
                // E-mail et mot de passe : identité du PRINCIPAL, jamais celle d'un
                // dossier (son adresse technique @dossiers.invalid ne reçoit rien et
                // son mot de passe aléatoire n'est la porte d'entrée de personne —
                // plan des dossiers liés § 2.3). En dossier lié, seule l'« identité du
                // dossier » (son nom, ci-dessus) reste éditable ici.
                $this->getEmailFormComponent()->visible(! $isLinkedDossier),
                $this->getPasswordFormComponent()->visible(! $isLinkedDossier),
                TextInput::make('siren')
                    ->label('SIREN')
                    ->maxLength(11) // 9 chiffres + 2 espaces de séparation tolérés à la saisie
                    ->placeholder('123 456 789')
                    ->helperText($isLinkedDossier
                        ? 'Vérifié à la création du dossier : il ne peut plus être modifié (anti-détournement).'
                        : '9 chiffres. Les espaces sont acceptés à la saisie, retirés à l\'enregistrement.')
                    // Confort client seulement — l'interdiction réelle est dans
                    // mutateFormDataBeforeSave(), qui ignore ce champ quoi qu'il arrive.
                    ->disabled($isLinkedDossier)
                    ->hintAction(SireneCheckAction::make(
                        fn () => $this->data['siren'] ?? null,
                        indivision: fn () => (bool) ($this->data['is_indivision'] ?? false),
                    ))
                    ->rules([
                        fn () => function (string $attribute, $value, \Closure $fail) {
                            if (blank($value)) {
                                return;
                            }

                            $digits = preg_replace('/\s+/', '', (string) $value) ?? '';

                            if (! preg_match('/^\d{9}$/', $digits)) {
                                $fail('Le SIREN doit compter 9 chiffres (les espaces de séparation sont tolérés).');

                                return;
                            }

                            if (! SireneClient::luhn($digits)) {
                                $fail('Ce SIREN ne passe pas le contrôle de validité (clé de Luhn) : un chiffre a probablement été mal recopié.');
                            }
                        },
                    ]),
                $this->getIndivisionSection($isLinkedDossier),
                Select::make('timezone')
                    ->label('Fuseau horaire')
                    ->options($timezones)
                    ->searchable()
                    ->required()
                    ->helperText($isLinkedDossier ? 'Repris de votre compte principal.' : null)
                    ->disabled($isLinkedDossier),
                Toggle::make('mcp_enabled')
                    ->label('Activer l\'API MCP')
                    ->helperText('Permet aux assistants IA (Claude, etc.) d\'accéder à vos données comptables via le protocole MCP.')
                    // Un dossier lié n'a jamais de jeton MCP qui lui soit propre
                    // (plan des dossiers liés § 3) : l'interrupteur n'a pas sa place ici.
                    ->visible(fn () => config('mcp.enabled') && ! $isLinkedDossier),
                // IA : clé, modèles, consentement — tout appartient au PRINCIPAL
                // (App\Support\AiAccess::for() y résout systématiquement). Les afficher
                // ici serait soit trompeur (une valeur qui ne sert jamais), soit une
                // fausse impression de pouvoir couper l'IA du dossier depuis son propre
                // compte, qu'on ne contrôle pas pour ce compte principal.
                $this->getAiSection()->visible(fn () => ! $isLinkedDossier && (bool) config('ai.enabled')),
            ]);
    }

    /**
     * Section « Indivision » : le dossier est tenu au nom d'une indivision, dont le résultat
     * est réparti entre indivisaires sur la 2031 bis-SD (voir `App\Support\Indivision`).
     *
     * La quote-part se saisit en pourcentage (`share`) mais se stocke en points de base
     * (`share_bp`) : la conversion se fait au remplissage et à l'enregistrement.
     */
    protected function getIndivisionSection(bool $isLinkedDossier = false): Section
    {
        return Section::make('Indivision')
            ->icon('heroicon-o-users')
            ->description('Un bien détenu à plusieurs sans société : une seule liasse au nom de l\'indivision, puis chacun déclare sa quote-part.')
            ->collapsible()
            ->collapsed(fn () => ! $this->getUser()->is_indivision)
            ->schema([
                Toggle::make('is_indivision')
                    ->label('Ce dossier est celui d\'une indivision')
                    ->helperText($isLinkedDossier
                        ? 'Ce dossier EST une indivision (vérifiée à sa création) : ce réglage ne peut pas être désactivé.'
                        : 'Ajoute à la liasse l\'annexe 2031 bis (répartition du résultat entre indivisaires). '
                            . 'Saisissez le bien et ses charges pour leur montant total : la quote-part ne s\'applique qu\'au résultat. '
                            . 'Vos biens personnels relèvent d\'un autre compte. Le micro-BIC suit les mêmes règles '
                            . 'que pour un dossier individuel.')
                    // Confort client seulement — l'interdiction réelle est dans
                    // mutateFormDataBeforeSave(), qui ignore ce champ quoi qu'il arrive.
                    ->disabled($isLinkedDossier)
                    ->live(),
                Repeater::make('co_owners')
                    ->label('Indivisaires')
                    ->visible(fn (Get $get) => (bool) $get('is_indivision'))
                    ->schema([
                        TextInput::make('name')
                            ->label('Nom et prénoms')
                            ->required()
                            ->maxLength(120),
                        TextInput::make('share')
                            ->label('Quote-part (%)')
                            ->required()
                            ->placeholder('50')
                            ->suffix('%')
                            // ⚠️ Live : c'est elle qui fait vivre le total affiché juste en
                            // dessous (`co_owners_total`). `onBlur` plutôt qu'à chaque frappe,
                            // pour ne pas déclencher un aller-retour serveur par chiffre tapé.
                            ->live(onBlur: true)
                            ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                                $bp = Indivision::percentToBasisPoints($value);
                                if ($bp === null || $bp <= 0 || $bp > Indivision::SHARE_TOTAL) {
                                    $fail('Une quote-part entre 0 et 100 %, deux décimales au plus (ex. 50 ou 33,33).');
                                }
                            }),
                        TextInput::make('address')
                            ->label('Adresse')
                            ->helperText('Demandé sur la 2031 bis.')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('birth')
                            ->label('Date et lieu de naissance')
                            ->helperText('Demandé sur la 2031 bis.')
                            ->placeholder('le 12/03/1985 à Lyon')
                            ->maxLength(120),
                        TextInput::make('tax_id')
                            ->label('N° fiscal (facultatif)')
                            // La notice de la 2031-SD le déclare explicitement optionnel, contrairement
                            // à l'adresse et à la naissance : pas de mention « Demandé ».
                            ->placeholder('13 chiffres')
                            ->maxLength(16)
                            ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                                if (filled($value) && ! preg_match('/^\d{13}$/', preg_replace('/\s+/', '', (string) $value))) {
                                    $fail('Le numéro fiscal compte 13 chiffres.');
                                }
                            }),
                    ])
                    ->columns(2)
                    ->defaultItems(2)
                    ->addActionLabel('Ajouter un indivisaire')
                    // Maquette écran 1, variante B (liste compacte, fiche dépliable) : chaque
                    // indivisaire tient dans un en-tête (nom, quote-part, badge « Demandé sur la
                    // 2031 bis ») et déplie ses champs au clic, plutôt que d'afficher les cinq
                    // champs à plat pour chacun. `collapsible()` seul laisse les fiches ouvertes
                    // par défaut (`isCollapsed` vaut `false` tant qu'on n'appelle pas
                    // `collapsed()`) : rien ne change pour qui n'a que deux indivisaires.
                    ->collapsible()
                    ->itemLabel(fn (array $state): HtmlString => $this->coOwnerItemLabel($state))
                    ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                        if (! ($this->data['is_indivision'] ?? false)) {
                            return;
                        }
                        $rows = array_values((array) $value);
                        if (count($rows) < 2) {
                            $fail('Une indivision compte au moins deux indivisaires.');

                            return;
                        }
                        $total = 0;
                        foreach ($rows as $row) {
                            $total += Indivision::percentToBasisPoints($row['share'] ?? '') ?? 0;
                        }
                        if ($total !== Indivision::SHARE_TOTAL) {
                            $fail('Les quotes-parts totalisent ' . Indivision::basisPointsToPercent($total) . ' % : elles doivent faire 100 %.');
                        }
                    }),
                // Total en direct, à côté du message d'erreur qui ne s'affiche qu'à
                // l'enregistrement : il se recalcule à chaque quote-part modifiée
                // (`share` est `->live()`) sans attendre le clic sur « Enregistrer ».
                Placeholder::make('co_owners_total')
                    ->hiddenLabel()
                    ->visible(fn (Get $get) => (bool) $get('is_indivision'))
                    ->content(fn (Get $get) => $this->coOwnersTotalContent($get('co_owners') ?? [])),
            ]);
    }

    /**
     * Contenu du total vivant des quotes-parts, sous le Repeater « Indivisaires ».
     *
     * Les parts sont tronquées vers zéro à la saisie (`Indivision::percentToBasisPoints()`) :
     * une somme en points de base, jamais en pourcentage flottant, pour que « 100 % pile »
     * se détecte par une égalité entière plutôt qu'une comparaison approximative.
     */
    private function coOwnersTotalContent(array $rows): HtmlString
    {
        $total = 0;
        foreach ($rows as $row) {
            $total += Indivision::percentToBasisPoints($row['share'] ?? '') ?? 0;
        }

        $percent = Indivision::basisPointsToPercent($total);
        $isComplete = $total === Indivision::SHARE_TOTAL;

        // Jauge (maquette écran 1, variante B) : le texte reste la seule source de vérité
        // (testé par `assertSee`), la barre n'est qu'un doublon visuel — jamais l'inverse.
        $barWidth = max(0, min(100, $total / 100));
        $barColor = $isComplete ? 'var(--olmnp-success-solid)' : 'var(--olmnp-warning-solid)';
        $bar = '<div style="height:8px;border-radius:999px;background:var(--olmnp-surface-alt);overflow:hidden;margin-bottom:4px" aria-hidden="true">'
            . '<div style="height:100%;width:' . $barWidth . '%;background:' . $barColor . ';border-radius:999px"></div>'
            . '</div>';

        if ($isComplete) {
            return new HtmlString(
                $bar . '<strong style="color:var(--olmnp-success-fg)">✓ Total : ' . e($percent) . ' %</strong>'
            );
        }

        $ecart = Indivision::basisPointsToPercent(abs(Indivision::SHARE_TOTAL - $total));
        $mention = $total > Indivision::SHARE_TOTAL ? $ecart . ' % en trop' : 'il manque ' . $ecart . ' %';

        return new HtmlString(
            $bar . '<strong style="color:var(--olmnp-warning-fg)">⚠ Total : ' . e($percent) . ' % — ' . e($mention) . '</strong>'
        );
    }

    /**
     * En-tête de fiche d'un indivisaire (maquette écran 1, variante B) : nom, quote-part, et un
     * badge qui dit ce qui manque pour la 2031 bis. Le numéro fiscal reste hors du badge — il
     * est facultatif selon la notice, `checkIndivision()` suit la même règle.
     */
    private function coOwnerItemLabel(array $state): HtmlString
    {
        $name = trim((string) ($state['name'] ?? '')) ?: 'Indivisaire';

        $bp = Indivision::percentToBasisPoints($state['share'] ?? '');
        $sharePart = $bp !== null ? ' — ' . e(Indivision::basisPointsToPercent($bp)) . ' %' : '';

        $missing = (blank($state['address'] ?? null) ? 1 : 0) + (blank($state['birth'] ?? null) ? 1 : 0);

        $badge = $missing === 0
            ? '<span style="margin-left:8px;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:600;'
                . 'background:var(--olmnp-success-bg);color:var(--olmnp-success-fg);border:1px solid var(--olmnp-success-border)">'
                . '✓ Complet pour la 2031 bis</span>'
            : '<span style="margin-left:8px;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:600;'
                . 'background:var(--olmnp-warning-bg);color:var(--olmnp-warning-fg);border:1px solid var(--olmnp-warning-border)">'
                . '⚠ ' . $missing . ' champ' . ($missing > 1 ? 's' : '') . ' à compléter</span>';

        return new HtmlString(e($name) . e($sharePart) . $badge);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = parent::mutateFormDataBeforeFill($data);

        $rows = array_values((array) ($this->getUser()->co_owners ?? []));

        // Défaut gênant n°4 du test de bout en bout (2026-10-15) : le profil d'un
        // dossier d'indivision tout juste créé s'ouvrait avec le Repeater VIDE,
        // alors que son formulaire en prévoit deux par défaut (`defaultItems(2)`).
        // Ce réglage de Filament ne s'applique QU'À LA CRÉATION d'un enregistrement
        // — cette page est TOUJOURS en mode édition, il ne joue donc jamais ici.
        // On pré-remplit nous-mêmes : les indivisaires proposés par Sirene quand le
        // registre les donne (dirigeants personnes physiques, voir SireneClient::
        // describe()), sinon deux lignes vides à compléter.
        if ($rows === [] && $this->getUser()->is_linked_dossier && (bool) ($data['is_indivision'] ?? false)) {
            $leaders = (array) ($this->getUser()->linkedDossier?->sirene_snapshot['leaders'] ?? []);

            $rows = $leaders !== []
                ? array_map(fn (string $name) => ['name' => $name], $leaders)
                : [[], []];
        }

        $data['co_owners'] = array_map(fn (array $row) => [
            'name' => $row['name'] ?? null,
            'share' => isset($row['share_bp']) ? Indivision::basisPointsToPercent((int) $row['share_bp']) : null,
            'address' => $row['address'] ?? null,
            'birth' => $row['birth'] ?? null,
            'tax_id' => $row['tax_id'] ?? null,
        ], $rows);

        return $data;
    }

    /**
     * Section « Intelligence artificielle ».
     *
     * Le champ de clé n'apparaît que si l'instance n'en fournit pas elle-même :
     * sur le cloud (AI_API_KEY renseignée), l'utilisateur ne voit jamais de clé.
     *
     * La clé saisie n'est JAMAIS rechargée en clair : `ai_api_key` est dans
     * `#[Hidden]` sur le modèle User, donc absente de `attributesToArray()` que
     * `fillForm()` utilise. Le champ reste vide à l'affichage et, grâce à
     * `dehydrated(filled)`, un enregistrement à vide n'écrase pas la clé —
     * même mécanique que le champ mot de passe de Filament.
     */
    protected function getAiSection(): Section
    {
        return Section::make('Intelligence artificielle')
            ->icon('heroicon-o-sparkles')
            ->description('Analyse de justificatifs et assistant : ces fonctions font appel à un fournisseur d\'IA externe.')
            ->visible(fn () => (bool) config('ai.enabled'))
            ->schema([
                // En tête : la voie sans copier-coller. Le champ de saisie reste
                // juste en dessous — il est le seul recours pour un serveur
                // local, une passerelle d'entreprise ou une panne du fournisseur.
                Actions::make([
                    Action::make('connectOpenRouter')
                        ->label(fn () => filled($this->getUser()->ai_api_key)
                            ? 'Remplacer par une nouvelle clé ' . $this->aiProviderKeyLabel()
                            : 'Se connecter avec ' . $this->aiProviderKeyLabel())
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->color('primary')
                        // Une clé existante n'est écrasée qu'au retour réussi,
                        // et n'est pas révoquée chez le fournisseur : le dire.
                        //
                        // ⚠️ modalHidden() et non requiresConfirmation() seul :
                        // Filament ouvre le modal dès qu'un titre est défini,
                        // sans consulter la condition de confirmation. Avec
                        // elle seule, « Remplacer la clé enregistrée ? »
                        // s'affichait alors qu'aucune clé ne l'était.
                        ->requiresConfirmation()
                        ->modalHidden(fn (): bool => blank($this->getUser()->ai_api_key))
                        ->modalHeading('Remplacer la clé enregistrée ?')
                        ->modalDescription(fn () => 'La clé actuelle sera écrasée dès qu\'une nouvelle aura été '
                            . 'délivrée. Elle n\'est pas révoquée pour autant : supprimez-la depuis votre compte '
                            . $this->aiProviderKeyLabel() . ' si vous ne vous en servez plus.')
                        ->modalSubmitActionLabel('Continuer')
                        ->action(fn () => $this->redirect(route('filament.admin.ai.openrouter.start'))),
                ])
                    ->columnSpanFull()
                    ->visible(fn () => $this->canConnectProvider()),

                Placeholder::make('ai_oauth_hint')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->content(fn () => $this->aiOauthHint())
                    ->visible(fn () => $this->canConnectProvider()),

                Placeholder::make('ai_provider')
                    ->label('Fournisseur')
                    ->content(fn () => $this->aiProviderSummary())
                    ->visible(fn () => (bool) config('ai.enabled')),

                Placeholder::make('ai_key_status')
                    ->label('Clé d\'API')
                    ->content(fn () => $this->aiKeyStatusLabel())
                    ->visible(fn () => $this->canManageOwnKey()),

                TextInput::make('ai_api_key')
                    ->label(fn () => filled($this->getUser()->ai_api_key)
                        ? 'Remplacer la clé'
                        : $this->aiKeyFieldLabel())
                    ->password()
                    ->revealable(false)
                    ->autocomplete('off')
                    ->placeholder(fn () => $this->aiProviderHint('placeholder'))
                    ->maxLength(255)
                    ->helperText(fn () => $this->aiKeyHelperText())
                    // Champ vide = clé inchangée (patron du champ mot de passe).
                    ->dehydrated(fn (#[SensitiveParameter] $state): bool => filled($state))
                    ->visible(fn () => $this->canManageOwnKey()),

                Actions::make([
                    Action::make('checkAiKey')
                        ->label('Vérifier la clé')
                        ->icon('heroicon-o-check-badge')
                        ->color('gray')
                        ->action(fn () => $this->checkAiKey()),

                    Action::make('deleteAiKey')
                        ->label('Supprimer la clé')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->link()
                        ->requiresConfirmation()
                        ->modalHeading('Supprimer la clé enregistrée ?')
                        ->modalDescription('Les fonctions d\'intelligence artificielle seront désactivées pour votre compte jusqu\'à la saisie d\'une nouvelle clé.')
                        ->visible(fn () => filled($this->getUser()->ai_api_key))
                        ->action(fn () => $this->deleteAiKey()),
                ])->visible(fn () => $this->canManageOwnKey()),

                Toggle::make('ai_data_access')
                    ->label('Autoriser l\'assistant à consulter mes données')
                    ->helperText('Quand cette option est active, l\'assistant peut lire vos biens, recettes, charges, emprunts et exercices (en lecture seule, jamais de modification) pour répondre à vos questions. Ces données sont transmises au fournisseur d\'IA pour la durée de la réponse.')
                    ->visible(fn () => AiAccess::for($this->getUser())->available()),

                // Modèles par utilisateur (CDC § 4.4) : vide = modèle de l'instance.
                Select::make('ai_model_documents')
                    ->label('Modèle pour l\'analyse de justificatifs')
                    ->options(fn () => $this->aiModelOptions('documents'))
                    // « Aucun choix » EST une option — celle du modèle de
                    // l'instance. Sans ces deux lignes, une valeur nulle ne
                    // correspond à aucune clé et Filament affiche un « choisir
                    // une option » qui laisse croire que rien n'est configuré.
                    ->selectablePlaceholder(false)
                    ->formatStateUsing(fn ($state): string => (string) $state)
                    ->helperText(fn () => 'Le drapeau indique le pays de l\'éditeur du modèle. '
                        . $this->aiRoutingNotice())
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? trim((string) $state) : null)
                    ->visible(fn () => AiAccess::for($this->getUser())->available()),

                Select::make('ai_model_chat')
                    ->label('Modèle pour l\'assistant')
                    ->options(fn () => $this->aiModelOptions('chat'))
                    ->selectablePlaceholder(false)
                    ->formatStateUsing(fn ($state): string => (string) $state)
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? trim((string) $state) : null)
                    ->visible(fn () => AiAccess::for($this->getUser())->available()),
            ]);
    }

    /**
     * Options du sélecteur de modèle : le modèle de l'instance, le catalogue du
     * fournisseur, et — s'il en existe une — la valeur déjà choisie hors
     * catalogue, pour ne jamais l'effacer en silence.
     *
     * @return array<string, string>
     */
    protected function aiModelOptions(string $feature): array
    {
        $access = AiAccess::for($this->getUser());

        try {
            $provider = $access->provider();
        } catch (\Throwable) {
            return [];
        }

        $effectif = $access->model($feature);
        $options = ['' => 'Modèle de l\'instance' . ($effectif !== '' ? " ({$effectif})" : '')];
        $options += ModelCatalog::options($provider, $feature);

        $choisi = $feature === 'chat'
            ? $this->getUser()->ai_model_chat
            : $this->getUser()->ai_model_documents;

        if (filled($choisi) && ! isset($options[$choisi])) {
            $options[$choisi] = ModelCatalog::flagForModel((string) $choisi, $provider) . ' ' . $choisi . ' (choix personnalisé)';
        }

        return $options;
    }

    /**
     * Rappel de routage : le pays de l'éditeur du modèle n'est pas celui du
     * service qui achemine la requête. Ne le dire qu'au moment où les deux
     * diffèrent réellement serait pire — l'utilisateur ne saurait pas qu'il y
     * a deux questions.
     */
    protected function aiRoutingNotice(): string
    {
        try {
            $provider = AiAccess::for($this->getUser())->provider();
        } catch (\Throwable) {
            return '';
        }

        $pays = ModelCatalog::countryForProvider($provider);

        if ($pays === 'self') {
            return 'Le modèle tourne sur cette instance : rien ne sort de votre réseau.';
        }

        return 'La requête, elle, transite par ' . $provider->label()
            . ' (' . ModelCatalog::badge($pays) . ') quel que soit le modèle choisi.';
    }

    /** « 🇺🇸 OpenRouter — openrouter.ai », pour savoir où partent ses documents. */
    protected function aiProviderSummary(): string
    {
        try {
            $provider = AiAccess::for($this->getUser())->provider();
        } catch (\Throwable) {
            return 'Configuration incomplète : voir avec l\'administrateur de cette instance.';
        }

        $hote = (string) parse_url($provider->baseUrl(), PHP_URL_HOST);
        $pays = ModelCatalog::countryForProvider($provider);

        return trim(ModelCatalog::flag($pays) . ' ' . $provider->label() . ($hote !== '' ? " — {$hote}" : ''));
    }

    /** Le bouton « Se connecter avec … » est-il proposé ? */
    protected function canConnectProvider(): bool
    {
        return $this->canManageOwnKey() && OpenRouterOauth::isAvailableFor($this->getUser());
    }

    /**
     * Nom du fournisseur, tel qu'on l'écrit dans une phrase (« se connecter
     * avec … », « clé … »). Un serveur local n'en a pas : on dit « d'API ».
     */
    protected function aiProviderKeyLabel(): string
    {
        try {
            $label = AiAccess::for($this->getUser())->provider()->keyLabel();
        } catch (\Throwable) {
            return 'le fournisseur configuré';
        }

        return $label !== '' ? $label : 'le fournisseur configuré';
    }

    /** Libellé du champ de saisie : « Clé OpenRouter », ou « Clé d'API ». */
    protected function aiKeyFieldLabel(): string
    {
        try {
            $label = AiAccess::for($this->getUser())->provider()->keyLabel();
        } catch (\Throwable) {
            $label = '';
        }

        return $label !== '' ? "Clé {$label}" : "Clé d'API";
    }

    /** Aide de saisie déclarée par le fournisseur (placeholder, page des clés). */
    protected function aiProviderHint(string $cle): ?string
    {
        try {
            $provider = AiAccess::for($this->getUser())->provider();
        } catch (\Throwable) {
            return null;
        }

        $valeur = config("ai.providers.{$provider->key()}.key_hint.{$cle}");

        return filled($valeur) ? (string) $valeur : null;
    }

    protected function aiKeyHelperText(): string
    {
        $texte = 'Laissez ce champ vide pour conserver la clé actuelle.';

        if ($url = $this->aiProviderHint('url')) {
            $hote = preg_replace('#^https?://#', '', (string) $url);

            return $texte . " Obtenez une clé sur {$hote}, ou pointez AI_BASE_URL vers un serveur "
                . 'installé chez vous pour que rien n\'en sorte.';
        }

        return $texte . ' Certains serveurs locaux acceptent n\'importe quelle valeur : renseignez '
            . 'celle qu\'attend le vôtre.';
    }

    /**
     * Explication sous le bouton, et avertissement quand le retour ne se fera
     * ni en HTTPS ni sur localhost : le fournisseur ne garantit que ces deux
     * cas. On prévient sans désactiver — la saisie manuelle est juste dessous.
     */
    protected function aiOauthHint(): string
    {
        $texte = $this->aiProviderKeyLabel() . ' vous redirige vers votre compte, puis renvoie à cette '
            . 'instance une clé qui vous appartient. Aucune donnée comptable n\'est transmise à cette étape. '
            . 'Vous pouvez aussi saisir une clé existante ci-dessous.';

        $avertissement = OpenRouterOauth::insecureCallbackWarning(route('filament.admin.ai.openrouter.callback'));

        return $avertissement === null ? $texte : $texte . ' ⚠️ ' . $avertissement;
    }

    /**
     * L'utilisateur gère-t-il sa propre clé ? Faux sur une instance qui fournit
     * la sienne (cloud) : le champ n'a alors aucun sens.
     */
    protected function canManageOwnKey(): bool
    {
        return (bool) config('ai.enabled')
            && (bool) config('ai.allow_user_keys')
            && blank(config('ai.api_key'));
    }

    /** État de la clé, sans jamais en révéler autre chose que la fin. */
    protected function aiKeyStatusLabel(): string
    {
        $key = $this->getUser()->ai_api_key;

        if (blank($key)) {
            return 'Aucune clé enregistrée.';
        }

        return 'Clé enregistrée (se termine par …' . substr((string) $key, -4) . ').';
    }

    protected function checkAiKey(): void
    {
        $user = $this->getUser();

        // La clé fraîchement saisie (non encore enregistrée) prime, pour que le
        // bouton vérifie bien ce que l'utilisateur vient de coller.
        $typed = $this->data['ai_api_key'] ?? null;

        if (filled($typed)) {
            $user = clone $user;
            $user->ai_api_key = $typed;
        }

        $status = AiClient::for($user)->checkKey();

        $notification = Notification::make()
            ->title($status->valid ? 'Clé valide' : 'Clé refusée')
            ->body($status->summary());

        $status->valid ? $notification->success() : $notification->danger();

        $notification->send();
    }

    protected function deleteAiKey(): void
    {
        /** @var User $user */
        $user = $this->getUser();
        $user->forceFill(['ai_api_key' => null])->save();

        $this->data['ai_api_key'] = null;

        Notification::make()
            ->title('Clé supprimée')
            ->body('Les fonctions d\'intelligence artificielle sont désactivées pour votre compte.')
            ->success()
            ->send();
    }

    /**
     * Horodate le consentement à la première activation de l'accès aux données
     * (même horodatage que celui de l'analyse de documents).
     */
    protected function mutateFormDataBeforeSave(#[SensitiveParameter] array $data): array
    {
        // ⚠️ Dossier lié : `disabled()` côté formulaire n'est qu'un confort client (le
        // commentaire de Filament `CanBeDisabled::disabled()` le dit explicitement —
        // Livewire reste manipulable). L'anti-détournement RÉEL est ICI : SIREN et
        // `is_indivision` du dossier ne bougent JAMAIS après sa création, quel que
        // soit ce qu'envoie le navigateur. `co_owners` reste modifiable : ce sont les
        // indivisaires eux-mêmes, données du dossier (plan § 2.3), pas sa vérification.
        if ($this->getUser()->is_linked_dossier) {
            unset(
                $data['siren'], $data['is_indivision'], $data['email'], $data['password'],
                $data['mcp_enabled'], $data['timezone'],
                $data['ai_api_key'], $data['ai_data_access'], $data['ai_model_documents'], $data['ai_model_chat'],
            );
        }

        // Compte démo public (identifiants publiés) : rien de ce qui l'identifie, le
        // protège ou s'affiche chez les autres visiteurs ne se modifie (GHSA-j4gm-g8m8-93x2).
        if (McpDemo::isDemoUser($this->getUser())) {
            unset(
                $data['name'], $data['email'], $data['password'], $data['siren'], $data['mcp_enabled'],
                $data['ai_api_key'], $data['ai_data_access'], $data['ai_model_documents'], $data['ai_model_chat'],
            );
        }

        // Compte démo public : les indivisaires (noms, adresses) s'afficheraient chez les autres
        // visiteurs, même règle que ci-dessus.
        if (McpDemo::isDemoUser($this->getUser())) {
            unset($data['is_indivision'], $data['co_owners']);
        }

        // Le repeater masqué n'est pas transmis : c'est l'interrupteur qui décide. Désactivée,
        // l'indivision ne garde pas d'indivisaires fantômes.
        if (array_key_exists('is_indivision', $data) && ! $data['is_indivision']) {
            $data['co_owners'] = null;
        } elseif (array_key_exists('co_owners', $data)) {
            $data['co_owners'] = array_values(array_map(fn (array $row) => [
                    'name' => trim((string) ($row['name'] ?? '')),
                    'share_bp' => Indivision::percentToBasisPoints($row['share'] ?? '') ?? 0,
                    'address' => filled($row['address'] ?? null) ? trim((string) $row['address']) : null,
                    'birth' => filled($row['birth'] ?? null) ? trim((string) $row['birth']) : null,
                    'tax_id' => filled($row['tax_id'] ?? null) ? preg_replace('/\s+/', '', (string) $row['tax_id']) : null,
                ], (array) $data['co_owners']));
        }

        if (($data['ai_data_access'] ?? false) && blank($this->getUser()->ai_consented_at)) {
            $data['ai_consented_at'] = now();
        }

        // Les espaces de séparation, tolérés à la saisie ("123 456 789"), ne sont pas
        // ceux que porte la colonne (`varchar(9)`, cf. Luhn) : on les retire ici, une
        // fois la validation passée, plutôt que par une transformation du champ qui
        // aurait pu brouiller l'ordre entre validation et dehydration.
        if (array_key_exists('siren', $data)) {
            $data['siren'] = filled($data['siren']) ? preg_replace('/\s+/', '', (string) $data['siren']) : null;
        }

        return $data;
    }

    /**
     * Une fois enregistrée, la clé ne doit plus traîner dans l'état Livewire
     * (donc dans le DOM et dans chaque requête suivante de la page) — même
     * précaution que Filament prend pour le mot de passe.
     */
    protected function afterSave(): void
    {
        $this->data['ai_api_key'] = null;
    }
}
