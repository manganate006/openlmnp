<x-filament-panels::page>
    <style>
        .mt-section { margin-bottom: 28px; }
        .mt-section-title { font-size: 15px; font-weight: 700; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid var(--olmnp-border); }
        .mt-table-wrap { overflow: hidden; border-radius: 12px; border: 1px solid var(--olmnp-border); }
        .mt-table { width: 100%; font-size: 13px; border-collapse: collapse; }
        .mt-table th { text-align: left; padding: 10px 14px; background: var(--olmnp-surface-muted); font-weight: 600; color: var(--olmnp-fg); white-space: nowrap; }
        .mt-table td { padding: 10px 14px; border-top: 1px solid var(--olmnp-surface-alt); vertical-align: middle; }
        .mt-muted { color: var(--olmnp-fg-muted); font-size: 12px; }
        .mt-mono { font-family: monospace; font-size: 12px; }
        .mt-btn-sm { padding: 4px 12px; border-radius: 6px; font-size: 12px; font-weight: 500; cursor: pointer; border: none; transition: all .15s; }
        .mt-btn-danger { background: var(--olmnp-danger-bg-strong); color: var(--olmnp-danger-fg); }
        .mt-btn-danger:hover { background: var(--olmnp-danger-border); }
        .mt-alert { border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .mt-alert-warning { background: var(--olmnp-warning-bg); border: 1px solid var(--olmnp-warning-border); }
        .mt-alert-title { font-size: 15px; font-weight: 600; color: var(--olmnp-warning-fg); margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
        .mt-alert-body { font-size: 13px; color: var(--olmnp-warning-fg); margin-bottom: 12px; }
        .mt-token-box { display: flex; align-items: center; gap: 10px; }
        .mt-token-value { flex: 1; background: var(--olmnp-surface); border: 1px solid var(--olmnp-border-strong); border-radius: 8px; padding: 10px 14px; font-family: monospace; font-size: 13px; word-break: break-all; user-select: all; }
        .mt-btn-copy { padding: 8px 16px; border-radius: 8px; background: var(--olmnp-info-solid); color: var(--olmnp-on-solid); border: none; font-size: 13px; font-weight: 500; cursor: pointer; white-space: nowrap; }
        .mt-btn-copy:hover { background: var(--olmnp-info-solid-hover); }
        .mt-dismiss { text-align: right; margin-top: 8px; }
        .mt-dismiss button { background: none; border: none; color: var(--olmnp-fg-muted); font-size: 12px; cursor: pointer; text-decoration: underline; }
        .mt-dismiss button:hover { color: var(--olmnp-fg); }
        .mt-empty { text-align: center; padding: 40px 20px; color: var(--olmnp-fg-muted); font-size: 14px; }
        .mt-snippet { position: relative; }
        .mt-snippet pre { background: var(--olmnp-surface); border: 1px solid var(--olmnp-border); border-radius: 12px; padding: 16px; font-size: 12px; font-family: monospace; overflow-x: auto; line-height: 1.6; }
        .mt-snippet-copy { position: absolute; top: 10px; right: 10px; padding: 4px 12px; border-radius: 6px; background: var(--olmnp-border); border: none; font-size: 11px; font-weight: 500; cursor: pointer; }
        .mt-snippet-copy:hover { background: var(--olmnp-border-strong); }
        .mt-snippet-desc { font-size: 13px; color: var(--olmnp-fg-muted); margin-bottom: 10px; }
        .mt-snippet-desc code { background: var(--olmnp-surface-alt); padding: 2px 6px; border-radius: 4px; font-size: 11px; }
    </style>

    {{-- Nouveau token créé --}}
    @if($this->newPlainToken)
        <div class="mt-alert mt-alert-warning">
            <div class="mt-alert-title">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                Votre nouveau token
            </div>
            <div class="mt-alert-body">Copiez ce token maintenant. Il ne sera plus affiché après fermeture.</div>
            <div class="mt-token-box">
                <div class="mt-token-value">{{ $this->newPlainToken }}</div>
                <button
                    type="button"
                    class="mt-btn-copy"
                    onclick="navigator.clipboard.writeText('{{ $this->newPlainToken }}').then(() => { this.innerText = 'Copié !' ; setTimeout(() => this.innerText = 'Copier', 2000) })"
                >Copier</button>
            </div>
            <div class="mt-dismiss">
                <button type="button" wire:click="dismissToken">J'ai copié mon token</button>
            </div>
        </div>
    @endif

    {{-- Tokens existants --}}
    <div class="mt-section">
        <div class="mt-section-title">Vos tokens API</div>

        @php $tokens = $this->getTokens(); @endphp

        @if(count($tokens) === 0)
            <div class="mt-empty">
                Aucun token API. Créez-en un pour connecter un client MCP.
            </div>
        @else
            <div class="mt-table-wrap">
                <table class="mt-table">
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>Dernière utilisation</th>
                            <th>Créé le</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($tokens as $token)
                            <tr>
                                <td style="font-weight:500;">{{ $token['name'] }}</td>
                                <td class="mt-muted">{{ $token['last_used_at'] }}</td>
                                <td class="mt-muted">{{ $token['created_at'] }}</td>
                                <td style="text-align:right;">
                                    <button
                                        type="button"
                                        wire:click="revokeToken({{ $token['id'] }})"
                                        wire:confirm="Voulez-vous vraiment révoquer ce token ?"
                                        class="mt-btn-sm mt-btn-danger"
                                    >Révoquer</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Configuration Claude Desktop --}}
    <div class="mt-section">
        <div class="mt-section-title">Configuration pour Claude Desktop</div>
        <div class="mt-snippet-desc">
            Ajoutez cette configuration dans votre fichier <code>claude_desktop_config.json</code> :
        </div>
        <div class="mt-snippet">
            <pre id="mt-desktop-config"><code>{{ $this->getConfigSnippet() }}</code></pre>
            <button
                type="button"
                class="mt-snippet-copy"
                onclick="navigator.clipboard.writeText(document.getElementById('mt-desktop-config').textContent).then(() => { this.innerText = 'Copié !' ; setTimeout(() => this.innerText = 'Copier', 2000) })"
            >Copier</button>
        </div>
    </div>

    {{-- Configuration Claude Code --}}
    @php $claudeCmd = 'claude mcp add --transport http -H "Authorization: Bearer VOTRE_TOKEN" openlmnp "' . url('/mcp') . '"'; @endphp
    <div class="mt-section">
        <div class="mt-section-title">Configuration pour Claude Code</div>
        <div class="mt-snippet-desc">
            Exécutez cette commande dans votre terminal (remplacez <code>VOTRE_TOKEN</code>) :
        </div>
        <div class="mt-snippet">
            <pre id="mt-claude-cmd"><code>{{ $claudeCmd }}</code></pre>
            <button
                type="button"
                class="mt-snippet-copy"
                onclick="navigator.clipboard.writeText(document.getElementById('mt-claude-cmd').textContent).then(() => { this.innerText = 'Copié !' ; setTimeout(() => this.innerText = 'Copier', 2000) })"
            >Copier</button>
        </div>
    </div>
</x-filament-panels::page>
