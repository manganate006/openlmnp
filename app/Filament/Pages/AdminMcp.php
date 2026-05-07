<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\RequiresAdmin;
use App\Models\McpAuditLog;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class AdminMcp extends Page
{
    use RequiresAdmin;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;
    protected static string|UnitEnum|null $navigationGroup = 'Administration';
    protected static ?string $navigationLabel = 'API MCP';
    protected static ?string $title = 'Gestion API MCP';
    protected static ?int $navigationSort = 5;
    protected string $view = 'filament.pages.admin-mcp';

    public static function canAccess(): bool
    {
        return \Illuminate\Support\Facades\Auth::check()
            && \Illuminate\Support\Facades\Auth::user()->isAdmin()
            && config('mcp.enabled', false);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('purgeOldLogs')
                ->label('Purger les anciens logs')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Supprimer les logs MCP de plus de ' . config('mcp.audit_retention_days', 90) . ' jours ?')
                ->action(function () {
                    $days = config('mcp.audit_retention_days', 90);
                    $deleted = McpAuditLog::where('created_at', '<', now()->subDays($days))->delete();

                    Notification::make()
                        ->title("{$deleted} logs supprimés")
                        ->success()
                        ->send();

                    $this->redirect(static::getUrl());
                }),
        ];
    }

    public function getStats(): array
    {
        $usersWithMcp = User::where('mcp_enabled', true)->count();
        $totalTokens = DB::table('personal_access_tokens')->count();
        $callsToday = McpAuditLog::whereDate('created_at', today())->count();
        $callsMonth = McpAuditLog::where('created_at', '>=', now()->startOfMonth())->count();
        $totalCalls = McpAuditLog::count();
        $errors = McpAuditLog::where('result_status', 'error')
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $topTool = McpAuditLog::select('tool_name', DB::raw('count(*) as cnt'))
            ->groupBy('tool_name')
            ->orderByDesc('cnt')
            ->value('tool_name');

        $avgDuration = McpAuditLog::whereNotNull('duration_ms')->avg('duration_ms');

        return [
            'users_with_mcp' => $usersWithMcp,
            'total_tokens' => $totalTokens,
            'calls_today' => $callsToday,
            'calls_month' => $callsMonth,
            'total_calls' => $totalCalls,
            'errors_month' => $errors,
            'top_tool' => $topTool ?? '—',
            'avg_duration_ms' => $avgDuration ? round($avgDuration) : null,
        ];
    }

    public function getUsers(): array
    {
        return User::withCount(['mcpAuditLogs'])
            ->orderByDesc('mcp_enabled')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'mcp_enabled' => $user->mcp_enabled,
                'token_count' => $user->tokens()->count(),
                'call_count' => $user->mcp_audit_logs_count,
                'last_call' => McpAuditLog::where('user_id', $user->id)
                    ->latest('created_at')
                    ->value('created_at'),
            ])
            ->toArray();
    }

    public function getAllTokens(): array
    {
        return DB::table('personal_access_tokens')
            ->join('users', 'users.id', '=', 'personal_access_tokens.tokenable_id')
            ->select(
                'personal_access_tokens.id',
                'personal_access_tokens.name as token_name',
                'personal_access_tokens.last_used_at',
                'personal_access_tokens.created_at',
                'users.id as user_id',
                'users.name as user_name',
                'users.email as user_email',
            )
            ->where('personal_access_tokens.tokenable_type', User::class)
            ->orderByDesc('personal_access_tokens.last_used_at')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'token_name' => $row->token_name,
                'user_name' => $row->user_name,
                'user_email' => $row->user_email,
                'last_used_at' => $row->last_used_at
                    ? \Carbon\Carbon::parse($row->last_used_at)->diffForHumans()
                    : 'Jamais',
                'created_at' => \Carbon\Carbon::parse($row->created_at)->format('d/m/Y H:i'),
            ])
            ->toArray();
    }

    public function getRecentAudit(): array
    {
        return McpAuditLog::with('user:id,name,email')
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(fn (McpAuditLog $log) => [
                'id' => $log->id,
                'user_name' => $log->user?->name ?? '—',
                'user_email' => $log->user?->email ?? '—',
                'token_name' => $log->token_name ?? '—',
                'tool_name' => $log->tool_name,
                'result_status' => $log->result_status,
                'ip_address' => $log->ip_address ?? '—',
                'duration_ms' => $log->duration_ms,
                'created_at' => $log->created_at->format('d/m/Y H:i:s'),
            ])
            ->toArray();
    }

    public function getToolStats(): array
    {
        return McpAuditLog::select('tool_name', DB::raw('count(*) as cnt'), DB::raw('avg(duration_ms) as avg_ms'))
            ->groupBy('tool_name')
            ->orderByDesc('cnt')
            ->get()
            ->map(fn ($row) => [
                'tool_name' => $row->tool_name,
                'count' => $row->cnt,
                'avg_ms' => $row->avg_ms ? round($row->avg_ms) : null,
            ])
            ->toArray();
    }

    public function toggleUserMcp(int $userId): void
    {
        $user = User::findOrFail($userId);
        $user->update(['mcp_enabled' => ! $user->mcp_enabled]);

        Notification::make()
            ->title('MCP ' . ($user->mcp_enabled ? 'activé' : 'désactivé') . ' pour ' . $user->name)
            ->success()
            ->send();

        $this->redirect(static::getUrl());
    }

    public function revokeToken(int $tokenId): void
    {
        DB::table('personal_access_tokens')->where('id', $tokenId)->delete();

        Notification::make()
            ->title('Token révoqué')
            ->success()
            ->send();

        $this->redirect(static::getUrl());
    }
}
