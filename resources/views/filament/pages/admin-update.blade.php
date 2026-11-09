<x-filament-panels::page>
    <style>
        .au-card { background: var(--olmnp-surface); border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border: 1px solid var(--olmnp-border); margin-bottom: 16px; }
        .au-badge-pending { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: var(--olmnp-surface-muted); color: var(--olmnp-fg-muted); border-radius: 20px; font-size: 12px; font-weight: 600; }
        .au-commit { font-family: monospace; font-size: 13px; color: var(--olmnp-fg-muted); }
        .au-badge-new { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: var(--olmnp-warning-bg-strong); color: var(--olmnp-warning-fg); border-radius: 20px; font-size: 12px; font-weight: 600; }
        .au-badge-ok { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: var(--olmnp-success-bg); color: var(--olmnp-success-fg); border-radius: 20px; font-size: 12px; font-weight: 600; }
        .au-badge-error { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: var(--olmnp-danger-bg); color: var(--olmnp-danger-fg); border-radius: 20px; font-size: 12px; font-weight: 600; }
        .au-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: var(--olmnp-success-solid); color: var(--olmnp-on-solid); border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 600; }
        .au-btn:hover { background: var(--olmnp-success-solid-hover); }
        .au-btn:disabled { opacity: 0.5; cursor: wait; }
        .au-btn-secondary { background: var(--olmnp-fg-muted); }
        .au-btn-secondary:hover { background: var(--olmnp-fg-muted); }
        .au-commit-list { margin-top: 12px; }
        .au-commit-item { display: flex; gap: 10px; align-items: baseline; padding: 6px 0; border-bottom: 1px solid var(--olmnp-surface-alt); font-size: 13px; }
        .au-commit-sha { font-family: monospace; color: var(--olmnp-accent-accent); font-weight: 600; min-width: 60px; }
        .au-commit-msg { color: var(--olmnp-fg); flex: 1; }
        .au-commit-date { color: var(--olmnp-fg-subtle); font-size: 11px; white-space: nowrap; }
        .au-result-ok { background: var(--olmnp-success-bg); border: 1px solid var(--olmnp-success-border); border-radius: 12px; padding: 16px; margin-top: 16px; }
        .au-result-fail { background: var(--olmnp-danger-bg); border: 1px solid var(--olmnp-danger-border); border-radius: 12px; padding: 16px; margin-top: 16px; }
        .au-input { width: 100%; padding: 8px 12px; border: 1px solid var(--olmnp-border-strong); border-radius: 8px; font-size: 13px; font-family: monospace; background: var(--olmnp-surface); color: var(--olmnp-fg); }
        .au-input:focus { outline: none; border-color: var(--olmnp-success-solid); box-shadow: 0 0 0 2px color-mix(in oklab, var(--olmnp-success-accent) 20%, transparent); }
        .au-label { font-size: 12px; font-weight: 600; color: var(--olmnp-fg); margin-bottom: 4px; display: block; }
        .au-hint { font-size: 11px; color: var(--olmnp-fg-subtle); margin-top: 2px; }
        .au-notice { background: var(--olmnp-info-bg); border: 1px solid var(--olmnp-info-border); border-radius: 12px; padding: 16px 20px; margin-bottom: 16px; }
        .au-notice-title { font-size: 14px; font-weight: 700; color: var(--olmnp-info-fg); margin-bottom: 6px; }
        .au-notice-text { font-size: 13px; color: var(--olmnp-info-fg); }
        .au-notice-cmd { display: block; margin-top: 10px; padding: 10px 12px; background: var(--olmnp-code-bg); color: var(--olmnp-fg); border-radius: 8px; font-family: monospace; font-size: 12px; overflow-x: auto; }
    </style>

    {{-- ================================================================== --}}
    {{-- Instance immuable : la mise à jour en place est impossible          --}}
    {{-- ================================================================== --}}
    @unless($this->canSelfApply())
        <div class="au-notice">
            <div class="au-notice-title">Instance Docker &mdash; mise &agrave; jour par image</div>
            <div class="au-notice-text">
                Cette instance tourne sur une image immuable : elle ne contient ni
                <code>rsync</code>, ni Composer, ni npm, et son code n'est jamais r&eacute;&eacute;crit sur place.
                La d&eacute;tection des nouvelles versions ci-dessous reste active ; pour appliquer une
                mise &agrave; jour, r&eacute;cup&eacute;rez la nouvelle image puis recr&eacute;ez le conteneur &mdash; les
                donn&eacute;es des volumes (base et <code>storage/</code>) sont conserv&eacute;es.
            </div>
            <code class="au-notice-cmd">{{ $this->dockerUpdateCommand() }}</code>
        </div>
    @endunless

    {{-- Version installée, dernière version publiée et changelog : markup partagé avec la
         page « Nouveautés », ouverte à tous. C'est lui qui porte le `wire:init`. --}}
    @include('filament.partials.version-changelog')

    {{-- ================================================================== --}}
    {{-- Section Déploiement branche (développement)                        --}}
    {{-- ================================================================== --}}
    <div class="au-card">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <div>
                <div style="font-size: 14px; font-weight: 700; margin-bottom: 8px;">Déploiement développement</div>
                <div style="display:flex;align-items:center;gap:12px;">
                    @if($this->getCurrentCommit())
                        <span class="au-commit">commit {{ substr($this->getCurrentCommit(), 0, 7) }}</span>
                    @else
                        <span class="au-commit" style="color:var(--olmnp-danger-accent);">aucun commit enregistré</span>
                    @endif
                </div>
            </div>
            <div>
                @if($branchInfo === null)
                    <span class="au-badge-pending">Vérification en cours&hellip;</span>
                @elseif(isset($branchInfo['error']))
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
            <div style="margin-top:12px;padding:12px;background:var(--olmnp-danger-bg);border-radius:8px;font-size:13px;color:var(--olmnp-danger-fg);">
                {{ $branchInfo['error'] }}
            </div>
        @endif

        <div style="margin-top:16px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
            <button wire:click="checkBranch" wire:loading.attr="disabled" wire:target="checkBranch" class="au-btn au-btn-secondary">
                <span wire:loading.remove wire:target="checkBranch">Vérifier</span>
                <span wire:loading wire:target="checkBranch">Vérification...</span>
            </button>

            @if($this->canSelfApply() && $branchInfo && ($branchInfo['available'] ?? false))
                <button wire:click="applyBranchUpdate" wire:loading.attr="disabled" wire:target="applyBranchUpdate" class="au-btn">
                    <span wire:loading.remove wire:target="applyBranchUpdate">Mise &agrave; jour</span>
                    <span wire:loading wire:target="applyBranchUpdate">Mise &agrave; jour en cours...</span>
                </button>
            @endif
        </div>

        <div style="margin-top:12px;" @unless($this->canSelfApply()) hidden @endunless>
            <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--olmnp-fg);">
                <button wire:click="toggleAutoUpdate" style="position:relative;width:44px;height:24px;border-radius:12px;border:none;cursor:pointer;transition:background .2s;{{ $autoUpdateEnabled ? 'background:var(--olmnp-success-solid);' : 'background:var(--olmnp-border-strong);' }}">
                    <span style="position:absolute;top:2px;{{ $autoUpdateEnabled ? 'left:22px;' : 'left:2px;' }}width:20px;height:20px;background:var(--olmnp-surface);border-radius:50%;transition:left .2s;box-shadow:0 1px 3px rgba(0,0,0,.2);"></span>
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
                <div style="font-size:13px;font-weight:600;color:var(--olmnp-fg);margin-bottom:8px;">
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

        {{-- L'état (version disponible, à jour, erreur) est affiché par le partial en tête de
             page : ici, seulement les actions. « Vérifier » force un relevé neuf. --}}
        <p style="font-size:13px;color:var(--olmnp-fg-muted);">
            Le relevé des releases est automatique et mis en cache
            {{ (int) config('updater.check_ttl') / 3600 }} h. « Vérifier » interroge GitHub immédiatement.
        </p>

        <div style="margin-top:12px;display:flex;gap:8px;">
            <button wire:click="checkUpdate" wire:loading.attr="disabled" wire:target="checkUpdate" class="au-btn au-btn-secondary">
                <span wire:loading.remove wire:target="checkUpdate">Vérifier les releases</span>
                <span wire:loading wire:target="checkUpdate">Vérification...</span>
            </button>

            @if($this->canSelfApply() && $updateInfo && ($updateInfo['available'] ?? false))
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

    {{-- Le changelog est rendu par le partial inclus en tête de page (markup partagé avec
         « Nouveautés »), plus ici : deux rendus, c'était deux mises en forme à maintenir. --}}

    {{-- ================================================================== --}}
    {{-- Configuration GitHub                                              --}}
    {{-- ================================================================== --}}
    <div class="au-card">
        <div style="font-size: 14px; font-weight: 700; margin-bottom: 12px;">Configuration GitHub</div>
        <p style="font-size:12px;color:var(--olmnp-fg-muted);margin-bottom:16px;">
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
                <div class="au-hint">Personal access token avec scope « repo » — <a href="https://github.com/settings/tokens" target="_blank" style="color:var(--olmnp-accent-accent);">Créer un token</a></div>
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
