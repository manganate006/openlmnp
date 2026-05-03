<x-filament-panels::page>
    <style>
        .au-card { background: var(--fi-body-bg, white); border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border: 1px solid var(--fi-border-color, #e5e7eb); margin-bottom: 16px; }
        .au-version { font-size: 32px; font-weight: 800; color: #10b981; }
        .au-commit { font-family: monospace; font-size: 13px; color: #6b7280; }
        .au-badge-new { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: #fef3c7; color: #92400e; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .au-badge-ok { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: #ecfdf5; color: #065f46; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .au-badge-error { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: #fef2f2; color: #991b1b; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .au-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: #10b981; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 600; }
        .au-btn:hover { background: #059669; }
        .au-btn:disabled { opacity: 0.5; cursor: wait; }
        .au-btn-secondary { background: #6b7280; }
        .au-btn-secondary:hover { background: #4b5563; }
        .au-commit-list { margin-top: 12px; }
        .au-commit-item { display: flex; gap: 10px; align-items: baseline; padding: 6px 0; border-bottom: 1px solid #f3f4f6; font-size: 13px; }
        .au-commit-sha { font-family: monospace; color: #6366f1; font-weight: 600; min-width: 60px; }
        .au-commit-msg { color: #374151; flex: 1; }
        .au-commit-date { color: #9ca3af; font-size: 11px; white-space: nowrap; }
        .au-result-ok { background: #ecfdf5; border: 1px solid #86efac; border-radius: 12px; padding: 16px; margin-top: 16px; }
        .au-result-fail { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 12px; padding: 16px; margin-top: 16px; }
        .au-changelog { padding: 16px; background: #f9fafb; border-radius: 8px; margin-top: 12px; }
        .au-changelog h4 { font-size: 14px; font-weight: 700; margin-bottom: 4px; }
        .au-changelog p { font-size: 13px; color: #4b5563; white-space: pre-wrap; }
        .au-changelog-date { font-size: 11px; color: #9ca3af; }
        .au-input { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; font-family: monospace; background: var(--fi-body-bg, white); color: var(--fi-fg, #111827); }
        .au-input:focus { outline: none; border-color: #10b981; box-shadow: 0 0 0 2px rgba(16,185,129,.2); }
        .au-label { font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 4px; display: block; }
        .au-hint { font-size: 11px; color: #9ca3af; margin-top: 2px; }
    </style>

    {{-- ================================================================== --}}
    {{-- Section Déploiement branche (développement)                        --}}
    {{-- ================================================================== --}}
    <div class="au-card">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <div>
                <div style="font-size: 14px; font-weight: 700; margin-bottom: 8px;">Déploiement développement</div>
                <div style="display:flex;align-items:center;gap:12px;">
                    <div class="au-version">v{{ $this->getCurrentVersion() }}</div>
                    @if($this->getCurrentCommit())
                        <span class="au-commit">commit {{ substr($this->getCurrentCommit(), 0, 7) }}</span>
                    @else
                        <span class="au-commit" style="color:#ef4444;">aucun commit enregistré</span>
                    @endif
                </div>
            </div>
            <div>
                @if($branchInfo && isset($branchInfo['error']))
                    <span class="au-badge-error">Erreur</span>
                @elseif($branchInfo && ($branchInfo['available'] ?? false))
                    @if($branchInfo['ahead_by'] !== null)
                        <span class="au-badge-new">{{ $branchInfo['ahead_by'] }} commit{{ $branchInfo['ahead_by'] > 1 ? 's' : '' }} en retard</span>
                    @else
                        <span class="au-badge-new">Mise à jour disponible</span>
                    @endif
                @elseif($branchInfo)
                    <span class="au-badge-ok">À jour</span>
                @endif
            </div>
        </div>

        @if($branchInfo && isset($branchInfo['error']))
            <div style="margin-top:12px;padding:12px;background:#fef2f2;border-radius:8px;font-size:13px;color:#991b1b;">
                {{ $branchInfo['error'] }}
            </div>
        @endif

        <div style="margin-top:16px;display:flex;gap:8px;align-items:center;">
            <button wire:click="checkBranch" wire:loading.attr="disabled" wire:target="checkBranch" class="au-btn au-btn-secondary">
                <span wire:loading.remove wire:target="checkBranch">Vérifier</span>
                <span wire:loading wire:target="checkBranch">Vérification...</span>
            </button>

            @if($branchInfo && ($branchInfo['available'] ?? false))
                <button wire:click="applyBranchUpdate" wire:loading.attr="disabled" wire:target="applyBranchUpdate" class="au-btn">
                    <span wire:loading.remove wire:target="applyBranchUpdate">Lancer la mise &agrave; jour</span>
                    <span wire:loading wire:target="applyBranchUpdate">Mise &agrave; jour en cours...</span>
                </button>
            @endif

            <label style="display:inline-flex;align-items:center;gap:8px;margin-left:auto;cursor:pointer;font-size:13px;color:#374151;">
                <button wire:click="toggleAutoUpdate" style="position:relative;width:44px;height:24px;border-radius:12px;border:none;cursor:pointer;transition:background .2s;{{ $autoUpdateEnabled ? 'background:#10b981;' : 'background:#d1d5db;' }}">
                    <span style="position:absolute;top:2px;{{ $autoUpdateEnabled ? 'left:22px;' : 'left:2px;' }}width:20px;height:20px;background:white;border-radius:50%;transition:left .2s;box-shadow:0 1px 3px rgba(0,0,0,.2);"></span>
                </button>
                Mise &agrave; jour automatique
            </label>
        </div>

        {{-- Résultat du déploiement --}}
        @if($deployResult)
            @if($deployResult['success'] ?? false)
                <div class="au-result-ok">
                    <strong>Déploiement réussi !</strong>
                    <p style="font-size:13px;margin-top:4px;">Backup sauvegardé dans : {{ $deployResult['backup_path'] ?? '' }}</p>
                </div>
            @else
                <div class="au-result-fail">
                    <strong>Échec du déploiement</strong>
                    <p style="font-size:13px;margin-top:4px;">{{ $deployResult['error'] ?? 'Erreur inconnue' }}</p>
                </div>
            @endif
        @endif

        {{-- Liste des commits --}}
        @if($branchInfo && !empty($branchInfo['commits']))
            <div class="au-commit-list">
                <div style="font-size:13px;font-weight:600;color:#374151;margin-bottom:8px;">
                    @if($branchInfo['first_deploy'] ?? false)
                        Derniers commits sur main
                    @else
                        Commits manquants
                    @endif
                </div>
                @foreach($branchInfo['commits'] as $commit)
                    <div class="au-commit-item">
                        <span class="au-commit-sha">{{ $commit['sha'] }}</span>
                        <span class="au-commit-msg">{{ $commit['message'] }}</span>
                        <span class="au-commit-date">
                            @if($commit['date'])
                                {{ \Carbon\Carbon::parse($commit['date'])->setTimezone(auth()->user()->timezone ?? 'Europe/Paris')->format('d/m H:i') }}
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ================================================================== --}}
    {{-- Section Releases (production)                                      --}}
    {{-- ================================================================== --}}
    <div class="au-card">
        <div style="font-size: 14px; font-weight: 700; margin-bottom: 12px;">Releases</div>

        @if($updateInfo === null)
            <p style="font-size:13px;color:#6b7280;">Cliquez sur « Vérifier » pour consulter les releases GitHub.</p>
        @else
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <div>
                    @if($updateInfo && ($updateInfo['available'] ?? false))
                        <span class="au-badge-new">v{{ $updateInfo['latest_version'] }} disponible</span>
                    @elseif($updateInfo && !isset($updateInfo['error']))
                        <span class="au-badge-ok">{{ ($updateInfo['info'] ?? '') === 'no_release' ? 'Aucune release publiée' : 'À jour' }}</span>
                    @endif
                </div>
            </div>

            @if($updateInfo && isset($updateInfo['error']))
                <div style="margin-top:12px;padding:12px;background:#fef2f2;border-radius:8px;font-size:13px;color:#991b1b;">
                    {{ $updateInfo['error'] }}
                </div>
            @endif
        @endif

        <div style="margin-top:12px;display:flex;gap:8px;">
            <button wire:click="checkUpdate" wire:loading.attr="disabled" wire:target="checkUpdate" class="au-btn au-btn-secondary">
                <span wire:loading.remove wire:target="checkUpdate">Vérifier les releases</span>
                <span wire:loading wire:target="checkUpdate">Vérification...</span>
            </button>

            @if($updateInfo && ($updateInfo['available'] ?? false))
                <button wire:click="applyUpdate" wire:loading.attr="disabled" wire:target="applyUpdate" class="au-btn">
                    <span wire:loading.remove wire:target="applyUpdate">Installer v{{ $updateInfo['latest_version'] }}</span>
                    <span wire:loading wire:target="applyUpdate">Mise à jour en cours...</span>
                </button>
            @endif
        </div>

        @if($updateResult)
            @if($updateResult['success'] ?? false)
                <div class="au-result-ok">
                    <strong>Mise à jour réussie !</strong>
                    <p style="font-size:13px;margin-top:4px;">Backup sauvegardé dans : {{ $updateResult['backup_path'] ?? '' }}</p>
                </div>
            @else
                <div class="au-result-fail">
                    <strong>Échec de la mise à jour</strong>
                    <p style="font-size:13px;margin-top:4px;">{{ $updateResult['error'] ?? 'Erreur inconnue' }}</p>
                </div>
            @endif
        @endif
    </div>

    {{-- ================================================================== --}}
    {{-- Changelog des releases                                             --}}
    {{-- ================================================================== --}}
    @if($changelog && count($changelog) > 0)
        <div class="au-card">
            <h3 style="font-size:16px;font-weight:700;margin-bottom:16px;">Changelog</h3>
            @foreach($changelog as $release)
                <div class="au-changelog">
                    <h4>v{{ $release['version'] }} — {{ $release['name'] }}</h4>
                    <div class="au-changelog-date">
                        @if($release['published_at'])
                            {{ \Carbon\Carbon::parse($release['published_at'])->format('d/m/Y') }}
                        @endif
                    </div>
                    @if($release['changelog'])
                        <p style="margin-top:8px;">{!! nl2br(e($release['changelog'])) !!}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
    {{-- ================================================================== --}}
    {{-- Configuration GitHub                                              --}}
    {{-- ================================================================== --}}
    <div class="au-card">
        <div style="font-size: 14px; font-weight: 700; margin-bottom: 12px;">Configuration GitHub</div>
        <p style="font-size:12px;color:#6b7280;margin-bottom:16px;">
            Le token est nécessaire uniquement pour les repos privés. Pour un repo public, laissez le champ vide.
        </p>

        <div style="display:grid;gap:12px;">
            <div>
                <label class="au-label" for="github-repo">Dépôt GitHub (owner/repo)</label>
                <input type="text" id="github-repo" wire:model="githubRepo" class="au-input" placeholder="manganate006/openlmnp" style="max-width:400px;">
                <div class="au-hint">Format : propriétaire/nom-du-repo</div>
            </div>
            <div>
                <label class="au-label" for="github-token">Token GitHub (optionnel, requis pour les repos privés)</label>
                <input type="password" id="github-token" wire:model="githubToken" class="au-input" placeholder="ghp_xxxxxxxxxxxxxxxxxxxx" style="max-width:500px;">
                <div class="au-hint">Personal access token avec scope « repo » — <a href="https://github.com/settings/tokens" target="_blank" style="color:#6366f1;">Créer un token</a></div>
            </div>
        </div>

        <div style="margin-top:12px;">
            <button wire:click="saveGithubSettings" wire:loading.attr="disabled" wire:target="saveGithubSettings" class="au-btn">
                <span wire:loading.remove wire:target="saveGithubSettings">Enregistrer</span>
                <span wire:loading wire:target="saveGithubSettings">Enregistrement...</span>
            </button>
        </div>
    </div>
</x-filament-panels::page>
