<div
    x-data="{
        ctxTab: null,
        panelTab: @js($assistantEnabled) ? (localStorage.getItem('openlmnpHelpTab') || 'help') : 'help',
        selectTab(tab) {
            this.panelTab = tab;
            localStorage.setItem('openlmnpHelpTab', tab);
            if (tab === 'assistant') { Livewire.dispatch('ai-assistant-opened'); }
        },
    }"
    x-init="
        // Écouter les clics sur les onglets Filament
        document.addEventListener('click', (e) => {
            const tabBtn = e.target.closest('[class*=fi-tabs-item]');
            if (tabBtn) {
                const label = tabBtn.textContent.trim();
                ctxTab = label;
            }
        });
        // Réinitialiser au changement de page (wire:navigate)
        document.addEventListener('livewire:navigated', () => { ctxTab = null; });
    "
>
    <style>
        .ctx-help-btn { position: fixed; bottom: 24px; right: 24px; z-index: 40; width: 48px; height: 48px; border-radius: 50%; background: var(--olmnp-success-solid-hover); color: var(--olmnp-on-solid); border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0,0,0,0.15); transition: all 0.2s; }
        {{--
            Défaut gênant n°10 du test de bout en bout (2026-10-15) : sur mobile, ce
            bouton flottant (fixed, bottom:24px) recouvrait le rappel sticky « Sera
            enregistrée dans » (dossier-banner.blade.php, slot `form-reminder`,
            `position: sticky; bottom: 0`) ainsi que le bouton d'action collé en bas
            d'un formulaire Filament sur petit écran (barre d'actions sticky). Les
            deux sont des barres PLEINE LARGEUR posées contre le bas du viewport —
            remonter le bouton d'aide suffit, sans toucher à ces vues : il n'a pas
            besoin d'être tout en bas pour rester atteignable au pouce.
        --}}
        @media (max-width: 1023px) { .ctx-help-btn { bottom: 88px; right: 16px; } }
        {{--
            D7 du test bêta du 2026-10-15 : remonter le bouton ne suffit pas pour ce qui
            n'est PAS collé au bas de l'écran. En bas d'une page défilée jusqu'au bout
            (profil à 390 x 844), le bouton (88 à 136 px du bas) se posait sur « Annuler »,
            dernière ligne du formulaire. Une marge basse sur le contenu principal laisse
            toujours la dernière ligne de la page remonter au-dessus du bouton :
            88 + 48 + 24 px d'air = 160 px. Sur toutes les pages, pas seulement le profil.
        --}}
        @media (max-width: 1023px) { .fi-main { padding-bottom: 160px; } }
        .ctx-help-btn:hover { background: var(--olmnp-success-solid-hover); transform: scale(1.1); }
        .ctx-help-btn svg { width: 24px; height: 24px; }
        /* Voile : il ASSOMBRIT la page, il ne la remplace pas. `--olmnp-surface-muted` est un
           aplat OPAQUE — en thème sombre, l'aide effaçait donc entièrement l'écran qu'elle décrit. */
        .ctx-backdrop { position: fixed; inset: 0; z-index: 40; background: color-mix(in oklab, var(--olmnp-code-bg) 45%, transparent); }
        /* Au-delà de 1024 px, l'aide et l'écran tiennent côte à côte : une aide contextuelle sert à
           lire pendant qu'on remplit le formulaire. Ni voile, ni capture des clics — la page reste
           utilisable. La fermeture passe par la croix ou la touche Échap, déjà câblées. */
        @media (min-width: 1024px) { .ctx-backdrop { background: transparent; pointer-events: none; } }
        .ctx-panel { position: fixed; top: 0; right: 0; z-index: 50; height: 100%; width: 100%; max-width: 384px; background: var(--olmnp-surface); box-shadow: -4px 0 24px rgba(0,0,0,0.15); display: flex; flex-direction: column; transition: transform 0.3s ease-in-out; }
        .ctx-panel.ctx-closed { transform: translateX(100%); }
        .ctx-panel.ctx-open { transform: translateX(0); }
        .ctx-panel-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--olmnp-border); flex-shrink: 0; }
        .ctx-panel-header h2 { font-size: 16px; font-weight: 600; color: var(--olmnp-fg-strong); margin: 0; display: flex; align-items: center; gap: 8px; }
        .ctx-panel-header h2 svg { width: 20px; height: 20px; color: var(--olmnp-success-accent); }
        .ctx-panel-close { background: none; border: none; cursor: pointer; padding: 4px; border-radius: 6px; color: var(--olmnp-fg-subtle); transition: all 0.15s; }
        .ctx-panel-close:hover { background: var(--olmnp-surface-alt); color: var(--olmnp-fg); }
        .ctx-panel-close svg { width: 20px; height: 20px; }
        .ctx-panel-tabs { display: flex; flex-shrink: 0; border-bottom: 1px solid var(--olmnp-border); padding: 0 12px; gap: 4px; }
        .ctx-panel-tab { background: none; border: none; border-bottom: 2px solid transparent; padding: 10px 8px; font-size: 13px; font-weight: 500; color: var(--olmnp-fg-subtle); cursor: pointer; transition: color 0.15s, border-color 0.15s; }
        .ctx-panel-tab:hover { color: var(--olmnp-fg); }
        .ctx-panel-tab.ctx-tab-active { color: var(--olmnp-success-accent); border-bottom-color: var(--olmnp-success-accent); }
        .ctx-panel-body { flex: 1; overflow-y: auto; padding: 20px; }
        .ctx-panel-body.ctx-panel-body-ai { display: flex; flex-direction: column; }
        .ctx-panel-footer { flex-shrink: 0; border-top: 1px solid var(--olmnp-border); padding: 12px 20px; text-align: center; }
        .ctx-panel-footer a { font-size: 13px; color: var(--olmnp-success-accent); text-decoration: none; }
        .ctx-panel-footer a:hover { text-decoration: underline; color: var(--olmnp-success-accent); }
        @media (max-width: 640px) { .ctx-panel { max-width: 100%; } }
    </style>

    {{-- Bouton flottant aide --}}
    <button wire:click="toggle" class="ctx-help-btn" aria-label="Aide contextuelle" title="Aide contextuelle">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
        </svg>
    </button>

    {{-- Backdrop --}}
    @if($open)
        <div wire:click="toggle" class="ctx-backdrop"></div>
    @endif

    {{-- Panneau latéral --}}
    <div @keydown.escape.window="if ($wire.open) $wire.toggle()" class="ctx-panel {{ $open ? 'ctx-open' : 'ctx-closed' }}">
        {{-- Header --}}
        <div class="ctx-panel-header">
            <h2>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
                </svg>
                {{ $pageTitle }}
            </h2>
            <button wire:click="toggle" class="ctx-panel-close" aria-label="Fermer l'aide">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        @if ($assistantEnabled)
            {{-- Onglets : l'aide statique reste le repli quand l'IA est indisponible --}}
            <div class="ctx-panel-tabs" role="tablist">
                <button type="button" class="ctx-panel-tab" role="tab"
                        x-on:click="selectTab('help')"
                        x-bind:class="panelTab === 'help' && 'ctx-tab-active'"
                        x-bind:aria-selected="panelTab === 'help'">Aide</button>
                <button type="button" class="ctx-panel-tab" role="tab"
                        x-on:click="selectTab('assistant')"
                        x-bind:class="panelTab === 'assistant' && 'ctx-tab-active'"
                        x-bind:aria-selected="panelTab === 'assistant'">Assistant</button>
            </div>
        @endif

        {{-- Contenu --}}
        <div class="ctx-panel-body" x-show="panelTab === 'help'" x-cloak>
            @include('help._styles')
            @include($helpView)
        </div>

        @if ($assistantEnabled)
            <div class="ctx-panel-body ctx-panel-body-ai" x-show="panelTab === 'assistant'" x-cloak>
                @livewire('ai-assistant', ['routeName' => $routeName])
            </div>
        @endif

        {{-- Footer : l'onglet Assistant porte déjà son propre lien vers le guide --}}
        <div class="ctx-panel-footer" @if ($assistantEnabled) x-show="panelTab === 'help'" x-cloak @endif>
            <a href="{{ \App\Filament\Pages\HelpPage::getUrl() }}" wire:navigate>
                Voir le guide complet
            </a>
        </div>
    </div>
</div>
