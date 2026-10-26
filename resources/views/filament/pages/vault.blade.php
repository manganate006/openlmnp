<x-filament-panels::page>
    <style>
        .vf-card { border-radius: 12px; border: 1px solid var(--olmnp-border); padding: 20px; margin-bottom: 20px; background: var(--olmnp-surface); }
        .vf-card-title { font-size: 15px; font-weight: 700; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
        .vf-card-body { font-size: 13px; color: var(--olmnp-fg-muted); line-height: 1.6; }
        .vf-state { border-radius: 12px; padding: 20px; margin-bottom: 20px; border: 1px solid var(--olmnp-border); }
        .vf-state-off { background: var(--olmnp-surface-muted); }
        .vf-state-on { background: var(--olmnp-success-bg); border-color: var(--olmnp-success-border); }
        .vf-state-locked { background: var(--olmnp-warning-bg); border-color: var(--olmnp-warning-border); }
        .vf-state-busy { background: var(--olmnp-info-bg); border-color: var(--olmnp-info-border); }
        .vf-state-title { font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
        .vf-state-on .vf-state-title { color: var(--olmnp-success-fg); }
        .vf-state-locked .vf-state-title { color: var(--olmnp-warning-fg); }
        .vf-state-busy .vf-state-title { color: var(--olmnp-info-fg); }
        .vf-state-body { font-size: 13px; line-height: 1.6; }
        .vf-state-on .vf-state-body { color: var(--olmnp-success-fg); }
        .vf-state-locked .vf-state-body { color: var(--olmnp-warning-fg); }
        .vf-state-busy .vf-state-body { color: var(--olmnp-info-fg); }
        .vf-state-off .vf-state-body { color: var(--olmnp-fg-muted); }
        .vf-actions { margin-top: 14px; display: flex; gap: 10px; flex-wrap: wrap; }
        .vf-btn { padding: 8px 16px; border-radius: 8px; border: none; font-size: 13px; font-weight: 500; cursor: pointer; background: var(--olmnp-info-solid); color: var(--olmnp-on-solid); }
        .vf-btn:hover { background: var(--olmnp-info-solid-hover); }
        .vf-btn-quiet { background: none; border: 1px solid var(--olmnp-border-strong); color: var(--olmnp-fg); }
        /* Filament réinitialise `list-style` sur les `ul` : sans cette ligne, les
           quatre points se lisent comme un seul bloc de texte. */
        .vf-list { font-size: 13px; color: var(--olmnp-fg-muted); line-height: 1.7; margin: 8px 0 0; padding-left: 18px; list-style: disc outside; }
        .vf-list li { margin-bottom: 4px; }
        .vf-list strong { color: var(--olmnp-fg); font-weight: 600; }
        .vf-warning { border-radius: 12px; padding: 18px; border: 1px solid var(--olmnp-danger-border); background: var(--olmnp-danger-bg); margin-bottom: 20px; }
        .vf-warning-title { font-size: 14px; font-weight: 700; color: var(--olmnp-danger-fg); display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
        .vf-warning-body { font-size: 13px; color: var(--olmnp-danger-fg); line-height: 1.6; }
        .vf-report { font-size: 12px; color: var(--olmnp-fg-muted); margin-top: 10px; font-family: monospace; }
    </style>

    @php
        $vault = $this->getVaultRecord();
        $unlocked = $this->isUnlocked();
        $pending = $this->getPendingCount();
    @endphp

    {{-- État courant --}}
    @if ($vault === null)
        <div class="vf-state vf-state-off">
            <div class="vf-state-title">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>
                Coffre-fort désactivé
            </div>
            <div class="vf-state-body">
                Vos justificatifs sont stockés tels quels sur le serveur. L'hébergeur de cette
                instance peut donc les ouvrir, de même que toute personne obtenant une copie du
                disque ou d'une sauvegarde.
            </div>
        </div>
    @elseif ($vault->isMigrating())
        <div class="vf-state vf-state-busy">
            <div class="vf-state-title">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                {{ $vault->status === \App\Models\DocumentVault::STATUS_ENCRYPTING ? 'Chiffrement en cours' : 'Déchiffrement en cours' }}
            </div>
            <div class="vf-state-body">
                @if ($pending > 0)
                    Il reste <strong>{{ $pending }}</strong> fichier(s) à traiter. L'opération avance par
                    lots de {{ \App\Filament\Pages\Vault::BATCH_SIZE }} : relancez-la jusqu'à ce qu'il n'en reste plus.
                    Tant qu'elle n'est pas terminée, le dépôt de nouveaux fichiers est suspendu.
                @else
                    Tous les fichiers ont été traités. Relancez une dernière fois pour finaliser.
                @endif
            </div>
            <div class="vf-actions">
                <button type="button" class="vf-btn" wire:click="continueMigration" wire:loading.attr="disabled">
                    Continuer l'opération
                </button>
                <button type="button" class="vf-btn vf-btn-quiet" wire:click="abandonMigration"
                        wire:confirm="Interrompre l'opération ? Les fichiers non traités resteront dans leur état actuel.">
                    Interrompre
                </button>
            </div>
            @if ($this->lastReport)
                <div class="vf-report">Dernier lot — {{ $this->lastReport }}</div>
            @endif
        </div>
    @elseif ($unlocked)
        <div class="vf-state vf-state-on">
            <div class="vf-state-title">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><path d="m9 16 2 2 4-4"/></svg>
                Coffre-fort actif et déverrouillé
            </div>
            <div class="vf-state-body">
                Vos justificatifs sont chiffrés sur le disque. Vous pouvez les consulter, les
                télécharger et en déposer de nouveaux normalement. La clé reste disponible jusqu'à
                votre déconnexion ou la fermeture de votre navigateur.
            </div>
        </div>
    @else
        <div class="vf-state vf-state-locked">
            <div class="vf-state-title">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Coffre-fort verrouillé
            </div>
            <div class="vf-state-body">
                    Vos justificatifs restent chiffrés tant que vous n'avez pas saisi votre phrase
                    de passe — elle vous sera demandée à la première ouverture, et le déchiffrement
                    se fera dans votre navigateur. <strong>Déposer une nouvelle pièce reste possible</strong> : le chiffrement n'a besoin que de la moitié publique de votre clé.
            </div>
        </div>
    @endif

    {{-- Avertissement, affiché en permanence dès que le coffre existe --}}
    @if ($vault !== null)
        <div class="vf-warning">
            <div class="vf-warning-title">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                Votre phrase de passe est irremplaçable
            </div>
            <div class="vf-warning-body">
                Elle n'est enregistrée nulle part, ni en clair ni sous forme dérivée. Si vous la
                perdez, vos justificatifs sont définitivement illisibles : ni nous, ni personne,
                ne peut les récupérer. Elle est indépendante de votre mot de passe de connexion —
                changer ce dernier n'a aucun effet sur le coffre-fort.
            </div>
        </div>
    @endif

    {{-- Ce que le coffre protège, et ce qu'il ne protège pas --}}
    <div class="vf-card">
        <div class="vf-card-title">Ce que le coffre-fort protège</div>
        <div class="vf-card-body">
            Le <strong>contenu</strong> des fichiers que vous déposez — justificatifs comptables et
            photos de biens — est chiffré sur le disque avec une clé dérivée de votre phrase de
            passe. Une copie du disque, de la réplique de secours ou d'une sauvegarde ne contient
            rien de lisible, et aucune clé.
        </div>
        <ul class="vf-list">
            <li><strong>Restent en clair</strong> : le libellé, le montant et la date de chaque
                pièce, ainsi que le nom des fichiers — le reste de votre comptabilité également.</li>
            <li><strong>Ne protège pas d'un serveur compromis</strong> : quelqu'un capable
                d'exécuter du code sur le serveur pendant que vous êtes déverrouillé peut
                intercepter la clé — mais vos clés ne quittent plus votre navigateur, donc il n'y en
                a plus à voler sur le serveur.</li>
            <li><strong>Export ZIP</strong> : un export groupé produit un fichier en clair, par
                nature, le temps de son téléchargement.</li>
            <li><strong>API MCP</strong> : le dépôt d'un justificatif fonctionne à nouveau
                (chiffrer ne demande que la clé publique). L'export groupé reste refusé : il produirait une archive en clair sur le serveur.</li>
        </ul>
    </div>
</x-filament-panels::page>
