<x-filament-panels::page>
    <style>
        .am-grid { display: grid; gap: 12px; }
        .am-grid-4 { grid-template-columns: repeat(4, 1fr); }
        .am-grid-3 { grid-template-columns: repeat(3, 1fr); }
        .am-grid-2 { grid-template-columns: repeat(2, 1fr); }
        .am-card { background: var(--fi-body-bg, white); border-radius: 12px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border: 1px solid var(--fi-border-color, #e5e7eb); }
        .am-card-label { font-size: 11px; color: var(--fi-fg-muted, #6b7280); text-transform: uppercase; letter-spacing: 0.5px; }
        .am-card-value { font-size: 24px; font-weight: 800; margin-top: 4px; }
        .am-card-sub { font-size: 12px; color: #6b7280; margin-top: 2px; }
        .am-section { margin-bottom: 28px; }
        .am-section-title { font-size: 15px; font-weight: 700; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid var(--fi-border-color, #e5e7eb); }
        .am-table { width: 100%; font-size: 13px; border-collapse: collapse; }
        .am-table th { text-align: left; padding: 8px 12px; background: #f9fafb; font-weight: 600; color: #374151; white-space: nowrap; }
        .am-table td { padding: 8px 12px; border-top: 1px solid #f3f4f6; vertical-align: middle; }
        .am-table-wrap { overflow: hidden; border-radius: 12px; border: 1px solid var(--fi-border-color, #e5e7eb); }
        .am-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .am-badge-green { background: #d1fae5; color: #065f46; }
        .am-badge-red { background: #fee2e2; color: #991b1b; }
        .am-badge-gray { background: #f3f4f6; color: #6b7280; }
        .am-badge-yellow { background: #fef3c7; color: #92400e; }
        .am-btn-sm { padding: 3px 10px; border-radius: 6px; font-size: 12px; font-weight: 500; cursor: pointer; border: none; transition: all .15s; }
        .am-btn-danger { background: #fee2e2; color: #991b1b; }
        .am-btn-danger:hover { background: #fca5a5; }
        .am-btn-success { background: #d1fae5; color: #065f46; }
        .am-btn-success:hover { background: #6ee7b7; }
        .am-btn-warning { background: #fef3c7; color: #92400e; }
        .am-btn-warning:hover { background: #fcd34d; }
        .am-highlight { color: #10b981; }
        .am-muted { color: #6b7280; font-size: 12px; }
        .am-mono { font-family: monospace; font-size: 12px; }
        @media (max-width: 900px) {
            .am-grid-4 { grid-template-columns: repeat(2, 1fr); }
            .am-grid-3 { grid-template-columns: repeat(1, 1fr); }
        }
    </style>

    @php
        $stats = $this->getStats();
        $users = $this->getUsers();
        $tokens = $this->getAllTokens();
        $audit = $this->getRecentAudit();
        $toolStats = $this->getToolStats();
    @endphp

    {{-- STATS GLOBALES --}}
    <div class="am-section">
        <div class="am-section-title">Vue d'ensemble</div>
        <div class="am-grid am-grid-4">
            <div class="am-card">
                <div class="am-card-label">Utilisateurs MCP actifs</div>
                <div class="am-card-value am-highlight">{{ $stats['users_with_mcp'] }}</div>
                <div class="am-card-sub">sur {{ count($users) }} utilisateurs</div>
            </div>
            <div class="am-card">
                <div class="am-card-label">Tokens actifs</div>
                <div class="am-card-value">{{ $stats['total_tokens'] }}</div>
                <div class="am-card-sub">toutes clés API</div>
            </div>
            <div class="am-card">
                <div class="am-card-label">Appels aujourd'hui</div>
                <div class="am-card-value">{{ $stats['calls_today'] }}</div>
                <div class="am-card-sub">{{ $stats['calls_month'] }} ce mois</div>
            </div>
            <div class="am-card">
                <div class="am-card-label">Total appels</div>
                <div class="am-card-value">{{ number_format($stats['total_calls'], 0, ',', ' ') }}</div>
                <div class="am-card-sub">{{ $stats['errors_month'] }} erreurs ce mois</div>
            </div>
        </div>

        <div class="am-grid am-grid-3" style="margin-top:12px;">
            <div class="am-card">
                <div class="am-card-label">Outil le plus utilisé</div>
                <div class="am-card-value" style="font-size:16px;">{{ $stats['top_tool'] }}</div>
            </div>
            <div class="am-card">
                <div class="am-card-label">Durée moyenne</div>
                <div class="am-card-value" style="font-size:20px;">
                    {{ $stats['avg_duration_ms'] !== null ? $stats['avg_duration_ms'] . ' ms' : '—' }}
                </div>
            </div>
            <div class="am-card">
                <div class="am-card-label">Rétention logs</div>
                <div class="am-card-value" style="font-size:20px;">{{ config('mcp.audit_retention_days', 90) }} j</div>
                <div class="am-card-sub">rate limit : {{ config('mcp.rate_limit', 60) }} req/min</div>
            </div>
        </div>
    </div>

    {{-- UTILISATEURS --}}
    <div class="am-section">
        <div class="am-section-title">Utilisateurs</div>
        <div class="am-table-wrap">
            <table class="am-table">
                <thead>
                    <tr>
                        <th>Utilisateur</th>
                        <th>Statut MCP</th>
                        <th>Tokens</th>
                        <th>Appels totaux</th>
                        <th>Dernier appel</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($users as $user)
                        <tr>
                            <td>
                                <div class="font-medium">{{ $user['name'] }}</div>
                                <div class="am-muted">{{ $user['email'] }}</div>
                            </td>
                            <td>
                                @if($user['mcp_enabled'])
                                    <span class="am-badge am-badge-green">Activé</span>
                                @else
                                    <span class="am-badge am-badge-gray">Désactivé</span>
                                @endif
                            </td>
                            <td>{{ $user['token_count'] }}</td>
                            <td>{{ $user['call_count'] }}</td>
                            <td class="am-muted">
                                {{ $user['last_call'] ? \Carbon\Carbon::parse($user['last_call'])->diffForHumans() : '—' }}
                            </td>
                            <td>
                                <button
                                    type="button"
                                    wire:click="toggleUserMcp({{ $user['id'] }})"
                                    wire:confirm="{{ $user['mcp_enabled'] ? 'Désactiver MCP pour ' . $user['name'] . ' ?' : 'Activer MCP pour ' . $user['name'] . ' ?' }}"
                                    class="am-btn-sm {{ $user['mcp_enabled'] ? 'am-btn-warning' : 'am-btn-success' }}"
                                >
                                    {{ $user['mcp_enabled'] ? 'Désactiver' : 'Activer' }}
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- TOKENS ACTIFS --}}
    <div class="am-section">
        <div class="am-section-title">Tokens actifs ({{ count($tokens) }})</div>
        @if(count($tokens) === 0)
            <div class="am-muted" style="text-align:center;padding:24px;">Aucun token créé.</div>
        @else
            <div class="am-table-wrap">
                <table class="am-table">
                    <thead>
                        <tr>
                            <th>Token</th>
                            <th>Utilisateur</th>
                            <th>Dernière utilisation</th>
                            <th>Créé le</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($tokens as $token)
                            <tr>
                                <td class="am-mono">{{ $token['token_name'] }}</td>
                                <td>
                                    <div class="font-medium">{{ $token['user_name'] }}</div>
                                    <div class="am-muted">{{ $token['user_email'] }}</div>
                                </td>
                                <td class="am-muted">{{ $token['last_used_at'] }}</td>
                                <td class="am-muted">{{ $token['created_at'] }}</td>
                                <td>
                                    <button
                                        type="button"
                                        wire:click="revokeToken({{ $token['id'] }})"
                                        wire:confirm="Révoquer ce token ?"
                                        class="am-btn-sm am-btn-danger"
                                    >
                                        Révoquer
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- STATS PAR OUTIL --}}
    @if(count($toolStats) > 0)
    <div class="am-section">
        <div class="am-section-title">Utilisation par outil</div>
        <div class="am-table-wrap">
            <table class="am-table">
                <thead>
                    <tr>
                        <th>Outil</th>
                        <th>Appels</th>
                        <th>Durée moyenne</th>
                        <th>Dernière utilisation</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($toolStats as $tool)
                        <tr>
                            <td class="am-mono">{{ $tool['tool_name'] }}</td>
                            <td>{{ number_format($tool['count'], 0, ',', ' ') }}</td>
                            <td class="am-muted">{{ $tool['avg_ms'] !== null ? $tool['avg_ms'] . ' ms' : '—' }}</td>
                            <td class="am-muted">{{ $tool['last_used_at'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- AUDIT LOG --}}
    <div class="am-section">
        <div class="am-section-title">Journal d'audit (100 derniers appels)</div>
        @if(count($audit) === 0)
            <div class="am-muted" style="text-align:center;padding:24px;">Aucun appel MCP enregistré.</div>
        @else
            <div class="am-table-wrap">
                <table class="am-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Utilisateur</th>
                            <th>Token</th>
                            <th>Outil</th>
                            <th>Statut</th>
                            <th>Durée</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($audit as $entry)
                            <tr>
                                <td class="am-muted am-mono" style="white-space:nowrap;">{{ $entry['created_at'] }}</td>
                                <td>
                                    <div class="font-medium" style="font-size:12px;">{{ $entry['user_name'] }}</div>
                                </td>
                                <td class="am-muted am-mono">{{ $entry['token_name'] }}</td>
                                <td class="am-mono">{{ $entry['tool_name'] }}</td>
                                <td>
                                    @if($entry['result_status'] === 'success')
                                        <span class="am-badge am-badge-green">OK</span>
                                    @else
                                        <span class="am-badge am-badge-red">Erreur</span>
                                    @endif
                                </td>
                                <td class="am-muted">{{ $entry['duration_ms'] !== null ? $entry['duration_ms'] . ' ms' : '—' }}</td>
                                <td class="am-muted am-mono">{{ $entry['ip_address'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
