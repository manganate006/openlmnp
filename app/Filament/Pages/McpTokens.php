<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\NavigationAware;
use App\Models\LinkedDossier;
use App\Models\User;
use App\Support\DossierContext;
use App\Support\McpDemo;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class McpTokens extends Page
{
    use NavigationAware;

    /** Valeur du sélecteur « Pour » qui désigne le compte principal lui-même (jamais une clé vide, voir le formulaire ci-dessous). */
    private const PERSONAL = 'personal';

    protected string $view = 'filament.pages.mcp-tokens';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'API MCP';

    protected static ?string $title = 'API MCP';

    protected static string|\UnitEnum|null $navigationGroup = 'Paramètres';

    protected static ?int $navigationSort = 10;

    public ?string $newPlainToken = null;

    public static function canAccess(): bool
    {
        // Un dossier lié n'a jamais de jeton MCP qui lui soit propre (lots 1-4, voir
        // le plan des dossiers liés) : la page est purement et simplement fermée.
        return config('mcp.enabled', false)
            && (auth()->user()?->mcp_enabled ?? false)
            && ! (auth()->user()?->is_linked_dossier ?? false);
    }

    protected static function isHiddenInSimpleMode(): bool
    {
        return true;
    }

    public function getSubheading(): ?string
    {
        return 'Créez et gérez vos tokens API pour connecter Claude Desktop, Claude Code ou tout autre client MCP à votre compte OpenLMNP.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggleMcp')
                ->label(fn () => auth()->user()->mcp_enabled ? 'Désactiver MCP' : 'Activer MCP')
                ->icon(fn () => auth()->user()->mcp_enabled ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open')
                ->color(fn () => auth()->user()->mcp_enabled ? 'danger' : 'success')
                ->requiresConfirmation()
                ->visible(fn () => config('mcp.enabled') && ! McpDemo::isDemoUser(auth()->user()))
                ->action(function () {
                    $user = auth()->user();
                    $user->update(['mcp_enabled' => ! $user->mcp_enabled]);

                    Notification::make()
                        ->title($user->mcp_enabled ? 'MCP activé' : 'MCP désactivé')
                        ->success()
                        ->send();

                    $this->redirect(static::getUrl());
                }),

            Action::make('createToken')
                ->label('Nouveau token')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->visible(fn () => auth()->user()->mcp_enabled && ! McpDemo::isDemoUser(auth()->user()))
                ->form(function () {
                    $fields = [
                        TextInput::make('name')
                            ->label('Nom du token')
                            ->placeholder('Ex : Claude Desktop, Mon script…')
                            ->required()
                            ->maxLength(255),
                    ];

                    // Lot 5 des dossiers liés : un jeton peut agir au nom d'un
                    // dossier dont le principal est membre ACTIF, plutôt qu'en
                    // son nom propre. Jamais pour la démo publique (jeton
                    // partagé, lecture seule) — cette page lui ferme déjà
                    // l'action elle-même, voir ->visible() ci-dessus ; la liste
                    // reste vide par construction si ce garde-fou venait à
                    // manquer.
                    $dossiers = McpDemo::isDemoUser(auth()->user())
                        ? collect()
                        : DossierContext::dossiersFor(auth()->user());

                    if ($dossiers->isNotEmpty()) {
                        $fields[] = Select::make('dossier_public_id')
                            ->label('Pour')
                            ->native(false)
                            // Défaut gênant n°9 du test de bout en bout (2026-10-15) :
                            // le sélecteur s'ouvrait VIDE. Une clé d'option VIDE
                            // ('') comme valeur par défaut ne s'affiche jamais comme
                            // sélectionnée dans ce composant — un état vide/null est
                            // ce que son rendu interprète comme « rien choisi »,
                            // même si '' est techniquement une clé valide de
                            // `options()`. D'où le jeton `self::PERSONAL`, non vide.
                            ->default(self::PERSONAL)
                            ->helperText('Un jeton « dossier » cesse de fonctionner dès que vous n\'avez plus accès à ce dossier.')
                            ->options(
                                collect([self::PERSONAL => 'Mon dossier personnel'])
                                    ->merge($dossiers->mapWithKeys(
                                        fn (LinkedDossier $dossier) => [$dossier->public_id => $dossier->user?->name ?? 'Dossier']
                                    ))
                                    ->toArray()
                            );
                    }

                    return $fields;
                })
                ->modalWidth('md')
                ->action(function (array $data) {
                    $user = auth()->user();

                    $maxTokens = config('mcp.max_tokens_per_user', 5);
                    if ($user->tokens()->count() >= $maxTokens) {
                        Notification::make()
                            ->title('Limite atteinte')
                            ->body("Vous ne pouvez pas créer plus de {$maxTokens} tokens.")
                            ->danger()
                            ->send();
                        return;
                    }

                    // Revérifié ICI, jamais confié à la seule liste affichée par
                    // le formulaire : appartenance ACTIVE au moment de la
                    // création, pas au moment où le formulaire a été rendu.
                    $dossierUserId = null;
                    $dossierPublicId = $data['dossier_public_id'] ?? null;

                    if (filled($dossierPublicId) && $dossierPublicId !== self::PERSONAL) {
                        $dossier = DossierContext::dossiersFor($user)->firstWhere('public_id', $dossierPublicId);

                        if ($dossier === null) {
                            Notification::make()
                                ->title('Dossier introuvable')
                                ->body('Vous n\'êtes plus membre de ce dossier, ou son identifiant est invalide.')
                                ->danger()
                                ->send();
                            return;
                        }

                        $dossierUserId = $dossier->user_id;
                    }

                    $token = $user->createToken($data['name']);

                    if ($dossierUserId !== null) {
                        $token->accessToken->update(['dossier_user_id' => $dossierUserId]);
                    }

                    $this->newPlainToken = $token->plainTextToken;

                    Notification::make()
                        ->title('Token créé')
                        ->body('Copiez-le maintenant, il ne sera plus affiché.')
                        ->warning()
                        ->send();
                }),
        ];
    }

    public function revokeToken(int $tokenId): void
    {
        abort_if(McpDemo::isDemoUser(auth()->user()), 403);

        $token = auth()->user()->tokens()->findOrFail($tokenId);
        $token->delete();

        Notification::make()
            ->title('Token révoqué')
            ->success()
            ->send();

        $this->redirect(static::getUrl());
    }

    public function dismissToken(): void
    {
        $this->newPlainToken = null;
    }

    public function getTokens(): array
    {
        $tokens = auth()->user()->tokens()
            ->orderByDesc('created_at')
            ->get();

        // Un seul aller-retour pour nommer les dossiers de TOUS les jetons
        // affichés, plutôt qu'une requête par ligne.
        $dossierNames = User::query()
            ->whereIn('id', $tokens->pluck('dossier_user_id')->filter()->unique())
            ->pluck('name', 'id');

        return $tokens
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'dossier_name' => $token->dossier_user_id !== null
                    ? ($dossierNames[$token->dossier_user_id] ?? 'Dossier supprimé')
                    : null,
                'last_used_at' => $token->last_used_at?->diffForHumans() ?? 'Jamais',
                'created_at' => $token->created_at->format('d/m/Y H:i'),
            ])
            ->toArray();
    }

    public function getConfigSnippet(): string
    {
        $url = url('/mcp');

        return json_encode([
            'mcpServers' => [
                'openlmnp' => [
                    'url' => $url,
                    'headers' => [
                        'Authorization' => 'Bearer VOTRE_TOKEN_ICI',
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
