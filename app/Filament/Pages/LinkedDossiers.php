<?php

namespace App\Filament\Pages;

use App\Filament\Actions\SireneCheckAction;
use App\Filament\Pages\Concerns\NavigationAware;
use App\Models\LinkedDossier;
use App\Models\LinkedDossierInvitation;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\LinkedDossierCreationException;
use App\Services\LinkedDossierInvitationService;
use App\Services\LinkedDossierService;
use App\Services\OpenData\SireneClient;
use App\Support\DossierQuota;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * Gestion des dossiers liés (sous-comptes d'indivision offerts) : création,
 * liste des dossiers dont on est membre, membres, archivage.
 *
 * ⚠️ PAS de `$slug` custom — même piège que `AdminInvitations`/`WhatsNew` : Filament
 * dérive le nom de route du slug, et `HelpContentRegistry` doit viser le même.
 */
class LinkedDossiers extends Page
{
    use NavigationAware;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Dossiers liés';

    protected static ?string $title = 'Dossiers liés';

    protected static string|\UnitEnum|null $navigationGroup = 'Paramètres';

    protected static ?int $navigationSort = 15;

    protected string $view = 'filament.pages.linked-dossiers';

    /** Saisie de l'invitation en cours, par dossier — `wire:model` indexé par id. */
    public array $inviteEmails = [];

    /**
     * Résultat de la vérification Sirene de l'étape 2 de l'assistant de création
     * (écran 5, variante B) : `null` tant qu'elle n'a pas tourné, puis
     * `['ok' => true, 'siren' => ..., 'company' => ...]` ou
     * `['ok' => false, 'message' => ..., 'supportable' => ..., 'retryable' => ...]`.
     *
     * ⚠️ PUREMENT un affichage pour guider l'étape suivante — la création réelle
     * (`getHeaderActions()::action()`) revalide tout depuis zéro via
     * `LinkedDossierService::create()`, sans jamais faire confiance à cet état.
     */
    public ?array $wizardCheck = null;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return (bool) config('dossiers.enabled')
            && $user !== null
            && ! $user->is_linked_dossier;
    }

    public function getSubheading(): ?string
    {
        // Un compte « membre » (lot « dossiers membre », décision du 2026-10-15) n'a
        // jamais de dossier à lui : la phrase générique parlerait de « votre compte »
        // comme s'il pouvait créer quelque chose, ce qui n'est précisément pas le cas.
        if (Auth::user()?->isMemberAccount()) {
            return 'Ce compte est un accès gratuit d\'indivisaire : il ne permet pas de saisir des biens '
                .'personnels, seulement d\'ouvrir les dossiers d\'indivision partagés ci-dessous.';
        }

        return 'Un dossier d\'indivision offert, partagé entre indivisaires, ouvert par bascule depuis votre compte.';
    }

    /** @return Collection<int, LinkedDossier> */
    public function dossiers(): Collection
    {
        $user = Auth::user();

        return LinkedDossier::query()
            ->whereMemberIs($user->id)
            ->with(['user', 'owner', 'members.user', 'invitations' => fn ($q) => $q->orderByDesc('created_at')])
            ->orderByDesc('created_at')
            ->get();
    }

    public function quotaLabel(): string
    {
        $user = Auth::user();
        $quota = DossierQuota::for($user);

        if ($quota === null) {
            return 'Dossiers liés illimités sur cette instance.';
        }

        $used = DossierQuota::used($user);

        return "{$used} / {$quota} dossier(s) lié(s) créé(s).";
    }

    /**
     * Pour la jauge de l'écran 4 (variante C) : `null` si le quota est illimité sur
     * cette instance (la jauge ne se dessine alors pas, seul le texte reste).
     *
     * @return array{used: int, quota: int}|null
     */
    public function quotaGauge(): ?array
    {
        $user = Auth::user();
        $quota = DossierQuota::for($user);

        if ($quota === null || $quota === 0) {
            return null;
        }

        return ['used' => min(DossierQuota::used($user), $quota), 'quota' => $quota];
    }

    /** Initiales (1 ou 2 lettres) pour l'avatar d'un nom ou, à défaut, d'une adresse. */
    public static function initials(?string $label): string
    {
        $label = trim((string) $label);

        if ($label === '') {
            return '?';
        }

        $words = preg_split('/\s+/', $label) ?: [];

        if (count($words) >= 2) {
            return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
        }

        return mb_strtoupper(mb_substr($label, 0, 1));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('creer')
                ->label('Créer un dossier lié')
                ->icon('heroicon-o-plus')
                // Un compte membre n'a jamais de quota propre (DossierQuota::for()
                // rend toujours 0 pour lui) : l'action resterait visible mais
                // éternellement désactivée. Autant la masquer, plutôt que de laisser
                // deviner un état qui n'évoluera jamais — la décision est expliquée
                // dans getSubheading() ci-dessus.
                ->visible(fn () => ! Auth::user()->isDemo() && ! Auth::user()->isMemberAccount())
                ->disabled(fn () => ! DossierQuota::canCreate(Auth::user()))
                ->modalHeading('Créer un dossier lié')
                ->modalWidth('lg')
                ->mountUsing(function (): void {
                    // Repart à zéro à chaque ouverture : une tentative refusée la veille
                    // ne doit jamais colorer l'étape 2 d'un nouvel essai.
                    $this->wizardCheck = null;
                })
                // Assistant en 3 étapes (écran 5, variante B du 2026-10-15) : la
                // vérification Sirene devient une étape VISIBLE, avant le nom — plutôt
                // qu'un échec en notification après la fermeture de la modale, qui
                // perdait la saisie.
                ->steps([
                    Step::make('SIREN')
                        ->schema([
                            TextInput::make('siren')
                                ->label('SIREN de l\'indivision')
                                ->required()
                                ->maxLength(11)
                                ->placeholder('123 456 789')
                                ->helperText('Il figure sur l\'avis de situation Sirene de l\'indivision.')
                                // D4 du test bêta du 2026-10-15 : après un refus, « Précédent »
                                // puis un SIREN corrigé laissait « Suivant » grisé, car rien ne
                                // remettait `wizardCheck` à zéro avant la fermeture de la modale.
                                // `live()` est indispensable : sans lui, la saisie n'atteindrait
                                // le serveur qu'avec le clic sur « Suivant », le bouton même qui
                                // est désactivé. Debounce plutôt qu'onBlur : un clic sur un bouton
                                // encore grisé au moment du blur serait perdu.
                                ->live(debounce: 400)
                                ->afterStateUpdated(function (): void {
                                    $this->wizardCheck = null;
                                })
                                ->hintAction(SireneCheckAction::make(
                                    fn (Get $get) => $get('siren'),
                                    indivision: fn () => true,
                                ))
                                ->rules([
                                    fn () => function (string $attribute, $value, \Closure $fail) {
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
                            Callout::make('Avant de continuer')
                                ->description('Le SIREN et le caractère « indivision » du dossier ne pourront plus être modifiés après la création.')
                                ->icon('heroicon-o-lock-closed'),
                        ])
                        ->afterValidation(function (Get $get): void {
                            $this->inspectWizardSiren((string) $get('siren'));
                        }),

                    Step::make('Sirene')
                        ->schema(fn () => $this->wizardSireneStepSchema())
                        // ⚠️ `afterValidation()` d'une étape tourne en la QUITTANT (donc
                        // juste AVANT d'afficher l'étape 3) — c'est ICI, et pas dans
                        // `afterValidation()` de l'étape « Nom » (qui tournerait trop tard,
                        // à la soumission finale), qu'il faut proposer le nom suggéré par
                        // Sirene pour qu'il soit déjà visible à l'arrivée sur l'étape 3.
                        ->afterValidation(function (Get $get, Set $set): void {
                            if (! (bool) ($this->wizardCheck['ok'] ?? false)) {
                                // Reste sur cette étape : l'erreur y est déjà affichée,
                                // la saisie du SIREN n'est pas perdue (retour possible).
                                throw new \Filament\Support\Exceptions\Halt;
                            }

                            // N'écrase le nom QUE s'il n'a pas déjà été saisi/modifié —
                            // revenir sur cette étape après « Précédent » puis la
                            // requitter ne doit jamais effacer une correction déjà faite.
                            if (filled($get('name'))) {
                                return;
                            }

                            $set('name', $this->proposedDossierName(
                                (array) ($this->wizardCheck['company'] ?? []),
                            ));
                        }),

                    Step::make('Nom')
                        ->schema([
                            TextInput::make('name')
                                ->label('Nom du dossier')
                                ->required()
                                ->maxLength(120)
                                ->placeholder('Indivision Dupont-Martin')
                                ->helperText('C\'est ce nom qui apparaît dans le sélecteur de dossier. Proposé à partir de Sirene quand c\'est possible, modifiable.'),
                            Callout::make('Ce qui sera figé')
                                ->warning()
                                ->description('Après création, le SIREN et le caractère « indivision » de ce dossier sont figés. '
                                    .'Vous renseignerez ensuite les indivisaires depuis le dossier, rubrique « Indivision » du profil.'),
                        ]),
                ])
                // Défaut gênant n°7 du test de bout en bout (2026-10-15) : le bouton
                // « Suivant » restait actif quand le SIREN était refusé — un clic
                // retombait certes sur la même étape (le `Halt` de l'étape « Sirene »
                // ci-dessus), mais rien à l'écran ne le laissait deviner avant de
                // cliquer. Désactivé tant que la dernière vérification n'a pas réussi.
                ->modifyWizardUsing(fn (\Filament\Schemas\Components\Wizard $wizard) => $wizard->nextAction(
                    fn (Action $action) => $action->disabled(fn () => $this->wizardNextDisabled()),
                ))
                ->modalSubmitActionLabel('Créer le dossier')
                ->action(function (array $data): void {
                    $owner = Auth::user();

                    try {
                        $dossier = app(LinkedDossierService::class)->create(
                            $owner,
                            (string) $data['siren'],
                            (string) ($data['name'] ?? ''),
                        );
                    } catch (LinkedDossierCreationException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    $this->wizardCheck = null;

                    // Jamais `purchase` (plan § 5) : ce n'est pas une vente, aucune facture.
                    AppServiceProvider::queueAnalyticsEvent([
                        'event' => 'linked_dossier_created',
                        'kind' => $dossier->kind,
                    ]);

                    Notification::make()
                        ->title('Dossier créé')
                        ->body('Ouvrez-le depuis le sélecteur pour renseigner les indivisaires.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Lance (ou relance) la vérification Sirene de l'étape 2, et range le résultat
     * dans `$this->wizardCheck` pour que l'étape l'affiche. N'écrit jamais rien en
     * base — voir `LinkedDossierService::inspect()`.
     *
     * ⚠️ PUBLIQUE (et pas juste la cible de `afterValidation()` sur l'étape 1) pour
     * être appelable directement depuis les tests Livewire
     * (`tests/Feature/Dossiers/LinkedDossierWizardTest.php`) sans simuler toute la
     * navigation du Wizard, hors de portée du harnais de test (voir le commentaire
     * de tests/Feature/Dossiers/LinkedDossierWizardTest.php à ce sujet).
     */
    public function inspectWizardSiren(string $siren): void
    {
        try {
            $inspected = app(LinkedDossierService::class)->inspect(Auth::user(), $siren);
        } catch (LinkedDossierCreationException $e) {
            $this->wizardCheck = [
                'ok' => false,
                'message' => $e->getMessage(),
                'supportable' => $e->isSupportable(),
                'retryable' => $e->isRetryable(),
            ];

            return;
        }

        $this->wizardCheck = [
            'ok' => true,
            'siren' => $inspected['siren'],
            'company' => $inspected['snapshot'],
        ];
    }

    /** @return array<\Filament\Schemas\Components\Component> */
    private function wizardSireneStepSchema(): array
    {
        $check = $this->wizardCheck;

        if ($check === null) {
            // Ne devrait pas s'afficher (l'étape précédente vient de tourner), mais
            // une étape qui n'a jamais sa donnée ne doit jamais rester muette.
            return [
                Callout::make('En attente')
                    ->description('Revenez à l\'étape précédente et cliquez sur « Vérifier dans Sirene ».'),
            ];
        }

        $ok = (bool) ($check['ok'] ?? false);
        $retryable = (bool) ($check['retryable'] ?? false);

        $callout = Callout::make($ok ? 'Conforme' : ($retryable ? 'Sirene indisponible' : 'Refusé'))
            ->description($ok
                ? 'Ce SIREN correspond à une indivision entre personnes physiques (nature juridique 2110), vérifiée et active.'
                : (string) ($check['message'] ?? ''))
            ->icon($ok ? 'heroicon-o-check-badge' : ($retryable ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-x-circle'));

        $callout = $ok ? $callout->success() : ($retryable ? $callout->warning() : $callout->danger());

        if (! $ok && ($check['supportable'] ?? false)) {
            $callout = $callout->footer([
                Action::make('wizardSupport')
                    ->label('Contacter le support')
                    ->icon('heroicon-o-lifebuoy')
                    ->link()
                    ->url(fn () => $this->wizardSupportMailto((string) ($check['message'] ?? '')))
                    ->openUrlInNewTab(),
            ]);
        }

        if (! $ok) {
            return [
                $callout,
                Html::make(new HtmlString('<p style="font-size:12px;opacity:.75">Retour à l\'étape précédente pour corriger le SIREN'
                    .($retryable ? ', ou pour réessayer sans rien changer' : '').'. Rien n\'a été créé, votre quota n\'a pas bougé.</p>')),
            ];
        }

        return [$callout, Html::make(fn () => $this->wizardCheckFactSheet())];
    }

    /**
     * Défaut gênant n°7 du test de bout en bout (2026-10-15) : le bouton
     * « Suivant » de l'assistant restait actif après un SIREN refusé — extraite
     * pour être la MÊME condition que celle posée sur l'action (`nextAction()`
     * ci-dessus) et pour rester directement testable sans rejouer tout le rendu
     * du Wizard.
     */
    public function wizardNextDisabled(): bool
    {
        // Sirene indisponible (`retryable`) n'est pas un refus : l'étape 2 invite à
        // « réessayer sans rien changer », ce que ferait précisément « Suivant » à
        // l'étape 1 (D4 du test bêta du 2026-10-15). À l'étape 2, le `Halt` de
        // `afterValidation()` garde de toute façon l'utilisateur sur place.
        return $this->wizardCheck !== null
            && ! (bool) ($this->wizardCheck['ok'] ?? false)
            && ! (bool) ($this->wizardCheck['retryable'] ?? false);
    }

    /**
     * Défaut gênant n°8 du test de bout en bout (2026-10-15) : le registre Sirene
     * nomme une indivision « INDIVISION » (littéralement) dans une bonne moitié des
     * cas — un nom proposé qui ne dit rien de plus que ce que l'utilisateur sait
     * déjà. On construit un nom plus utile à partir des dirigeants personnes
     * physiques (les indivisaires eux-mêmes, voir SireneClient::describe()) quand
     * le registre les donne ; sinon on laisse le champ VIDE (le placeholder du
     * champ « Indivision Dupont-Martin » montre l'exemple attendu) plutôt que de
     * reproposer ce même nom creux.
     *
     * @param  array<string, mixed>  $company  résultat de SireneClient::describe()
     */
    private function proposedDossierName(array $company): string
    {
        // `describe()` retire déjà un sigle identique, mais `wizardCheck` peut venir d'un
        // relevé plus ancien : on refait la même normalisation ici (D5, test bêta du 15/10).
        $name = trim((string) SireneClient::withoutRepeatedSigle((string) ($company['name'] ?? '')));
        $leaders = (array) ($company['leaders'] ?? []);

        if ($name !== '' && mb_strtoupper($name) !== 'INDIVISION') {
            return $name;
        }

        if ($leaders !== []) {
            return 'Indivision '.implode(' et ', array_slice($leaders, 0, 2));
        }

        return '';
    }

    private function wizardCheckFactSheet(): HtmlString
    {
        $company = $this->wizardCheck['company'] ?? null;

        if ($company === null) {
            return new HtmlString('<p style="font-size:13px">Vérification facultative sur cette instance (open data Sirene coupé) : aucune fiche disponible.</p>');
        }

        $lines = [];
        $lines[] = ['Nom', $company['name'] ?? '—'];
        $lines[] = ['Forme juridique', trim(($company['legal_form_label'] ?? 'code ').' ('.($company['legal_form'] ?? '').')')];
        $lines[] = ['État', $company['active'] ? 'Entreprise active' : 'Entreprise cessée'];

        if (filled($company['created_on'] ?? null)) {
            try {
                $lines[] = ['Créée le', \Illuminate\Support\Carbon::parse($company['created_on'])->translatedFormat('d/m/Y')];
            } catch (\Throwable) {
                // Date imprévue : on omet la ligne plutôt que de risquer un rendu cassé.
            }
        }

        if (filled($company['ape'] ?? null)) {
            $libelle = $company['rental'][0] ?? null;
            $lines[] = ['Code APE', $company['ape'].($libelle !== null ? ' — '.$libelle : '')];
        }

        $rows = collect($lines)
            ->map(fn (array $line) => '<dt style="font-size:11px;opacity:.65;margin-top:6px">'.e($line[0]).'</dt>'
                .'<dd style="font-size:13px;margin:0">'.e((string) $line[1]).'</dd>')
            ->implode('');

        return new HtmlString('<dl style="margin:4px 0 0">'.$rows.'</dl>'
            .'<p style="font-size:11px;opacity:.65;margin-top:10px">Source : API Recherche d\'entreprises (DINUM, données Insee), Licence Ouverte 2.0.</p>');
    }

    /** Lien `mailto:` vers le support, prérempli avec le motif du refus — jamais le SIREN seul ne suffit à le retrouver. */
    private function wizardSupportMailto(string $reason): string
    {
        return 'mailto:'.config('feedback.links.contact').'?'.http_build_query([
            'subject' => 'Création d\'un dossier lié — '.Auth::user()?->email,
            'body' => $reason,
        ]);
    }

    /**
     * Action Filament « Archiver » (écran 4, variante C) : un menu plutôt qu'un
     * bouton côte à côte avec « Ouvrir », et une vraie modale de confirmation
     * Filament — plus de `wire:confirm` (boîte du navigateur, sans contexte). Montée
     * depuis la vue par `mountAction('archiver', ['id' => $dossier->id])`.
     */
    public function archiverAction(): Action
    {
        return Action::make('archiver')
            ->label('Archiver le dossier…')
            ->icon('heroicon-o-archive-box')
            ->color('danger')
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-archive-box')
            ->modalHeading(fn (array $arguments): string => 'Archiver « '
                .(LinkedDossier::find($arguments['id'] ?? null)?->user?->name ?? '').' » ?')
            ->modalDescription('Le dossier sera fermé : plus personne ne pourra l\'ouvrir. Rien n\'est supprimé.')
            ->modalSubmitActionLabel('Archiver')
            ->action(function (array $arguments): void {
                $this->archiver((int) ($arguments['id'] ?? 0));
            });
    }

    public function archiver(int $id): void
    {
        $dossier = LinkedDossier::findOrFail($id);
        $user = Auth::user();

        if (! $this->peutAdministrer($dossier, $user)) {
            abort(403);
        }

        app(LinkedDossierService::class)->archive($dossier, $user);

        Notification::make()->title('Dossier archivé')->success()->send();
    }

    public function revoquerMembre(int $dossierId, int $memberId): void
    {
        $dossier = LinkedDossier::findOrFail($dossierId);
        $user = Auth::user();

        if (! $this->peutAdministrer($dossier, $user)) {
            abort(403);
        }

        $member = User::findOrFail($memberId);

        try {
            app(LinkedDossierService::class)->revokeMember($dossier, $user, $member);
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Accès révoqué')->success()->send();
    }

    /**
     * Quitter un dossier dont on est membre — y compris, pour le créateur, en
     * cédant de fait l'administration (plan § 10 décision n°5) : voir
     * App\Services\LinkedDossierService::leave() et App\Models\LinkedDossier::
     * actingOwner(), qui recalcule seul qui administre ensuite.
     */
    public function quitter(int $id): void
    {
        $dossier = LinkedDossier::findOrFail($id);
        $user = Auth::user();

        app(LinkedDossierService::class)->leave($dossier, $user);

        Notification::make()->title('Vous avez quitté ce dossier')->success()->send();
    }

    /**
     * Invite un second indivisaire. Réservé au responsable ACTUEL du dossier
     * (`LinkedDossier::actingOwner()`, pas forcément `owner_id` — voir le
     * commentaire de cette méthode) : App\Services\LinkedDossierInvitationService
     * revérifie la même condition, cette garde-ci n'évite qu'un aller-retour inutile.
     */
    public function inviter(int $dossierId): void
    {
        $dossier = LinkedDossier::findOrFail($dossierId);
        $user = Auth::user();
        $email = trim((string) ($this->inviteEmails[$dossierId] ?? ''));

        try {
            [$invitation, $plain] = app(LinkedDossierInvitationService::class)->create($dossier, $email, $user);
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $envoye = app(LinkedDossierInvitationService::class)->notify($invitation->refresh(), $plain);

        $this->inviteEmails[$dossierId] = '';

        Notification::make()
            ->title($envoye ? 'Invitation envoyée' : 'Invitation créée, courriel non parti')
            ->body($envoye ? null : 'Le service d\'envoi de courriel n\'a pas répondu : réessayez la relance plus tard.')
            ->{$envoye ? 'success' : 'warning'}()
            ->send();
    }

    public function relancerInvitation(int $invitationId): void
    {
        $invitation = LinkedDossierInvitation::findOrFail($invitationId);
        $user = Auth::user();

        try {
            $plain = app(LinkedDossierInvitationService::class)->resend($invitation, $user);
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        app(LinkedDossierInvitationService::class)->notify($invitation->refresh(), $plain);

        Notification::make()
            ->title('Invitation relancée')
            ->body('Le lien précédent ne fonctionne plus : relancer, c\'est remplacer.')
            ->success()
            ->send();
    }

    public function revoquerInvitation(int $invitationId): void
    {
        $invitation = LinkedDossierInvitation::findOrFail($invitationId);
        $user = Auth::user();

        try {
            app(LinkedDossierInvitationService::class)->revoke($invitation, $user);
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Invitation révoquée')->success()->send();
    }

    /**
     * Qui administre AUJOURD'HUI ce dossier (inviter, révoquer, archiver) :
     * délègue entièrement à `LinkedDossier::actingOwner()`, recalculé à chaque
     * appel (pas de webhook de suspension — plan § 0). `$user` n'est PAS lu
     * depuis `Auth::user()` ici : paramètre explicite, pour rester testable
     * sans dépendre du contexte d'authentification global.
     */
    public function peutAdministrer(LinkedDossier $dossier, User $user): bool
    {
        return $dossier->actingOwner()?->id === $user->id;
    }
}
