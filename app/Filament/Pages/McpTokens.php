<?php

namespace App\Filament\Pages;

use BladeUI\Icons\Components\Icon;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Str;

class McpTokens extends Page
{
    protected string $view = 'filament.pages.mcp-tokens';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'API MCP';

    protected static ?string $title = 'API MCP';

    protected static string|\UnitEnum|null $navigationGroup = 'Paramètres';

    protected static ?int $navigationSort = 10;

    public ?string $newPlainToken = null;

    public static function canAccess(): bool
    {
        return config('mcp.enabled', false) && (auth()->user()?->mcp_enabled ?? false);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggleMcp')
                ->label(fn () => auth()->user()->mcp_enabled ? 'Désactiver MCP' : 'Activer MCP')
                ->icon(fn () => auth()->user()->mcp_enabled ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open')
                ->color(fn () => auth()->user()->mcp_enabled ? 'danger' : 'success')
                ->requiresConfirmation()
                ->visible(fn () => config('mcp.enabled'))
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
                ->visible(fn () => auth()->user()->mcp_enabled)
                ->form([
                    TextInput::make('name')
                        ->label('Nom du token')
                        ->placeholder('Ex : Claude Desktop, Mon script…')
                        ->required()
                        ->maxLength(255),
                ])
                ->modalWidth(MaxWidth::Medium)
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

                    $token = $user->createToken($data['name']);

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
        return auth()->user()->tokens()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
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
