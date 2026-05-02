<div>
    <style>
        .ctx-help-btn { position: fixed; bottom: 24px; right: 24px; z-index: 40; width: 48px; height: 48px; border-radius: 50%; background: #059669; color: white; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0,0,0,0.15); transition: all 0.2s; }
        .ctx-help-btn:hover { background: #047857; transform: scale(1.1); }
        .ctx-help-btn svg { width: 24px; height: 24px; }
        .ctx-backdrop { position: fixed; inset: 0; z-index: 40; background: rgba(0,0,0,0.3); }
        .ctx-panel { position: fixed; top: 0; right: 0; z-index: 50; height: 100%; width: 100%; max-width: 384px; background: white; box-shadow: -4px 0 24px rgba(0,0,0,0.15); display: flex; flex-direction: column; transition: transform 0.3s ease-in-out; }
        .ctx-panel.ctx-closed { transform: translateX(100%); }
        .ctx-panel.ctx-open { transform: translateX(0); }
        .ctx-panel-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid #e5e7eb; flex-shrink: 0; }
        .ctx-panel-header h2 { font-size: 16px; font-weight: 600; color: #111827; margin: 0; display: flex; align-items: center; gap: 8px; }
        .ctx-panel-header h2 svg { width: 20px; height: 20px; color: #059669; }
        .ctx-panel-close { background: none; border: none; cursor: pointer; padding: 4px; border-radius: 6px; color: #9ca3af; transition: all 0.15s; }
        .ctx-panel-close:hover { background: #f3f4f6; color: #4b5563; }
        .ctx-panel-close svg { width: 20px; height: 20px; }
        .ctx-panel-body { flex: 1; overflow-y: auto; padding: 20px; }
        .ctx-panel-footer { flex-shrink: 0; border-top: 1px solid #e5e7eb; padding: 12px 20px; text-align: center; }
        .ctx-panel-footer a { font-size: 13px; color: #059669; text-decoration: none; }
        .ctx-panel-footer a:hover { text-decoration: underline; color: #047857; }
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

        {{-- Contenu --}}
        <div class="ctx-panel-body">
            @include('help._styles')
            @include($helpView)
        </div>

        {{-- Footer --}}
        <div class="ctx-panel-footer">
            <a href="{{ \App\Filament\Pages\HelpPage::getUrl() }}" wire:navigate>
                Voir le guide complet
            </a>
        </div>
    </div>
</div>
