<x-filament-panels::page>
    <style>
        .au-card { background: var(--fi-body-bg, white); border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border: 1px solid var(--fi-border-color, #e5e7eb); margin-bottom: 16px; }
        .au-version { font-size: 32px; font-weight: 800; color: #10b981; }
        .au-badge-new { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: #fef3c7; color: #92400e; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .au-badge-ok { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: #ecfdf5; color: #065f46; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .au-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: #10b981; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 600; }
        .au-btn:hover { background: #059669; }
        .au-btn:disabled { opacity: 0.5; cursor: wait; }
        .au-btn-secondary { background: #6b7280; }
        .au-btn-secondary:hover { background: #4b5563; }
        .au-changelog { padding: 16px; background: #f9fafb; border-radius: 8px; margin-top: 12px; }
        .au-changelog h4 { font-size: 14px; font-weight: 700; margin-bottom: 4px; }
        .au-changelog p { font-size: 13px; color: #4b5563; white-space: pre-wrap; }
        .au-changelog-date { font-size: 11px; color: #9ca3af; }
        .au-result-ok { background: #ecfdf5; border: 1px solid #86efac; border-radius: 12px; padding: 16px; margin-top: 16px; }
        .au-result-fail { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 12px; padding: 16px; margin-top: 16px; }
    </style>

    <div class="au-card">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <div>
                <div style="font-size:12px;color:#6b7280;margin-bottom:4px;">Version actuelle</div>
                <div class="au-version">v{{ $this->getCurrentVersion() }}</div>
            </div>
            <div>
                @if($updateInfo && ($updateInfo['available'] ?? false))
                    <span class="au-badge-new">Mise a jour disponible : v{{ $updateInfo['latest_version'] }}</span>
                @elseif($updateInfo && !isset($updateInfo['error']))
                    <span class="au-badge-ok">{{ ($updateInfo['info'] ?? '') === 'no_release' ? 'Aucune release publiee' : 'A jour' }}</span>
                @endif
            </div>
        </div>

        @if($updateInfo && isset($updateInfo['error']))
            <div style="margin-top:12px;padding:12px;background:#fef2f2;border-radius:8px;font-size:13px;color:#991b1b;">
                {{ $updateInfo['error'] }}
            </div>
        @endif

        <div style="margin-top:16px;display:flex;gap:8px;">
            <button wire:click="checkUpdate" wire:loading.attr="disabled" wire:target="checkUpdate" class="au-btn au-btn-secondary">
                <span wire:loading.remove wire:target="checkUpdate">Verifier</span>
                <span wire:loading wire:target="checkUpdate">Verification...</span>
            </button>

            @if($updateInfo && ($updateInfo['available'] ?? false))
                <button wire:click="applyUpdate" wire:loading.attr="disabled" wire:target="applyUpdate" class="au-btn">
                    <span wire:loading.remove wire:target="applyUpdate">Installer v{{ $updateInfo['latest_version'] }}</span>
                    <span wire:loading wire:target="applyUpdate">Mise a jour en cours...</span>
                </button>
            @endif
        </div>

        @if($updateResult)
            @if($updateResult['success'] ?? false)
                <div class="au-result-ok">
                    <strong>Mise a jour reussie !</strong>
                    <p style="font-size:13px;margin-top:4px;">Backup sauvegarde dans : {{ $updateResult['backup_path'] ?? '' }}</p>
                </div>
            @else
                <div class="au-result-fail">
                    <strong>Echec de la mise a jour</strong>
                    <p style="font-size:13px;margin-top:4px;">{{ $updateResult['error'] ?? 'Erreur inconnue' }}</p>
                </div>
            @endif
        @endif
    </div>

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
</x-filament-panels::page>
