<x-filament-panels::page>
    <style>
        .wn-admin { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; background: var(--olmnp-info-bg); border: 1px solid var(--olmnp-info-border); border-radius: 12px; padding: 16px 20px; }
        .wn-admin-text { font-size: 13px; color: var(--olmnp-info-fg); }
        .wn-admin-link { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 8px; background: var(--olmnp-info-solid); color: var(--olmnp-on-solid); font-size: 13px; font-weight: 600; text-decoration: none; white-space: nowrap; }
        .wn-admin-link:hover { background: var(--olmnp-info-solid-hover); }

        /* Carte « Nouveauté » — maquette écran 8, variante B. */
        .wn-novelty { background: var(--olmnp-surface); border: 1px solid var(--olmnp-border); border-radius: 12px; padding: 20px 22px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
        .wn-novelty-head { display: flex; align-items: flex-start; gap: 14px; margin-bottom: 14px; }
        .wn-novelty-icon { flex: none; width: 22px; height: 22px; color: var(--olmnp-success-accent); margin-top: 2px; }
        .wn-novelty-badge { display: inline-block; margin-bottom: 4px; padding: 2px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 600; background: var(--olmnp-success-bg); color: var(--olmnp-success-fg); border: 1px solid var(--olmnp-success-border); }
        .wn-novelty-head h3 { margin: 0; font-size: 16px; font-weight: 700; color: var(--olmnp-fg-strong); }
        .wn-novelty-head p { margin: 2px 0 0; font-size: 13px; color: var(--olmnp-fg-muted); }
        .wn-novelty-close { margin-left: auto; flex: none; background: none; border: none; cursor: pointer; padding: 4px; color: var(--olmnp-fg-subtle); border-radius: 6px; }
        .wn-novelty-close:hover { background: var(--olmnp-surface-alt); color: var(--olmnp-fg-strong); }
        .wn-novelty-pts { list-style: none; margin: 0 0 14px; padding: 0; display: flex; flex-direction: column; gap: 10px; }
        .wn-novelty-pts li { display: flex; align-items: flex-start; gap: 10px; font-size: 13.5px; color: var(--olmnp-fg); }
        .wn-novelty-pts svg { flex: none; width: 16px; height: 16px; margin-top: 2px; color: var(--olmnp-fg-muted); }
        .wn-novelty-note { display: flex; align-items: flex-start; gap: 8px; padding: 10px 12px; border-radius: 8px; background: var(--olmnp-info-bg); border: 1px solid var(--olmnp-info-border); color: var(--olmnp-info-fg); font-size: 13px; margin-bottom: 14px; }
        .wn-novelty-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .wn-novelty-btn-primary { padding: 8px 16px; border-radius: 8px; background: var(--olmnp-success-solid); color: var(--olmnp-on-solid); font-size: 13px; font-weight: 600; text-decoration: none; }
        .wn-novelty-btn-primary:hover { background: var(--olmnp-success-solid-hover); }
        .wn-novelty-btn-secondary { padding: 8px 16px; border-radius: 8px; background: var(--olmnp-surface); color: var(--olmnp-fg); border: 1px solid var(--olmnp-border-strong); font-size: 13px; font-weight: 600; text-decoration: none; }
        .wn-novelty-btn-secondary:hover { background: var(--olmnp-surface-alt); }
        .wn-novelty-small { margin: 10px 0 0; font-size: 12px; color: var(--olmnp-fg-muted); }
    </style>

    {{-- Carte « Nouveauté » (maquette écran 8, variante B) : une seule à la fois, fermable,
         mémorisée PAR VERSION — elle reviendra à la prochaine release qui a quelque chose à
         annoncer. Son contenu varie selon que les dossiers liés sont actifs sur l'instance. --}}
    @if($this->showsNoveltyCard())
        <div class="wn-novelty">
            <div class="wn-novelty-head">
                <x-heroicon-o-sparkles class="wn-novelty-icon" style="width:22px;height:22px" />
                <div>
                    <span class="wn-novelty-badge">Nouveau en {{ $this->currentVersion() }}</span>
                    <h3>Indivision : une liasse, une quote-part par indivisaire</h3>
                    <p>Pour un bien détenu à plusieurs sans société.</p>
                </div>
                <button type="button" wire:click="dismissNoveltyCard" class="wn-novelty-close" aria-label="Masquer cette nouveauté">
                    <x-heroicon-o-x-mark style="width:18px;height:18px" />
                </button>
            </div>

            <ul class="wn-novelty-pts">
                <li>
                    <x-heroicon-o-document-text style="width:16px;height:16px" />
                    <span><strong>Annexe 2031 bis-SD, cadre E.</strong> La liasse répartit le résultat entre indivisaires ; la somme des parts égale le résultat, au centime près.</span>
                </li>
                <li>
                    <x-heroicon-o-calculator style="width:16px;height:16px" />
                    <span><strong>Report de chacun.</strong> Chaque indivisaire voit le montant à reporter sur sa propre 2042-C-PRO.</span>
                </li>
                @if($this->linkedDossiersEnabled())
                    <li>
                        <x-heroicon-o-user-group style="width:16px;height:16px" />
                        <span><strong>Dossiers liés.</strong> Un dossier séparé pour l'indivision, ouvert depuis votre compte, son SIREN vérifié dans la base Sirene.</span>
                    </li>
                @endif
            </ul>

            @if($this->linkedDossiersEnabled())
                <div class="wn-novelty-note">
                    <x-heroicon-o-information-circle style="width:16px;height:16px;flex:none;margin-top:1px;" />
                    <div>Saisissez le bien, les emprunts et les charges pour leur montant total : la quote-part ne s'applique qu'au résultat.</div>
                </div>
                <div class="wn-novelty-actions">
                    <a href="{{ \App\Filament\Pages\LinkedDossiers::getUrl() }}" wire:navigate class="wn-novelty-btn-primary">Créer un dossier lié</a>
                    <a href="{{ \App\Filament\Pages\HelpPage::getUrl() }}" wire:navigate class="wn-novelty-btn-secondary">Lire l'aide</a>
                </div>
            @else
                <div class="wn-novelty-note">
                    <x-heroicon-o-information-circle style="width:16px;height:16px;flex:none;margin-top:1px;" />
                    <div>Ce compte est-il celui de l'indivision ? Activez la rubrique « Indivision » de votre profil. Vos biens personnels relèvent d'un autre compte.</div>
                </div>
                <div class="wn-novelty-actions">
                    <a href="{{ \App\Filament\Pages\EditProfile::getUrl() }}" wire:navigate class="wn-novelty-btn-primary">Ouvrir la rubrique Indivision</a>
                    <a href="{{ \App\Filament\Pages\HelpPage::getUrl() }}" wire:navigate class="wn-novelty-btn-secondary">Lire l'aide</a>
                </div>
                <p class="wn-novelty-small">Les dossiers liés existent aussi, désactivés sur cette instance (<code>LINKED_DOSSIERS_ENABLED</code>) : voyez avec son administrateur.</p>
            @endif
        </div>
    @endif

    @include('filament.partials.version-changelog')

    {{-- Lire les nouveautés est ouvert à tous ; appliquer une mise à jour reste une
         action d'administration, et le renvoi n'a de sens que pour un administrateur. --}}
    @if($this->canManageUpdates())
        <div class="wn-admin">
            <div class="wn-admin-text">
                Vous administrez cette instance : l'installation des mises à jour et la
                configuration du dépôt GitHub se font sur la page dédiée.
            </div>
            <a href="{{ \App\Filament\Pages\AdminUpdate::getUrl() }}" class="wn-admin-link">
                Gérer les mises à jour
            </a>
        </div>
    @endif
</x-filament-panels::page>
