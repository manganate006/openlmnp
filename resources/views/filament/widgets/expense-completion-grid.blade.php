<x-filament-widgets::widget>
    <style>
        .cg-card { background: var(--fi-body-bg, white); border-radius: 12px; padding: 16px 20px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border: 1px solid var(--fi-border-color, #e5e7eb); }
        .cg-title { font-size: 14px; font-weight: 600; margin-bottom: 12px; color: var(--fi-fg, #374151); }
        .cg-scroll { overflow-x: auto; overflow-y: visible; -webkit-overflow-scrolling: touch; padding-top: 32px; margin-top: -32px; }
        .cg-row { display: flex; align-items: center; gap: 4px; margin-bottom: 6px; }
        .cg-year { font-size: 13px; font-weight: 700; color: var(--fi-fg, #374151); width: 45px; flex-shrink: 0; }
        .cg-cell { display: flex; flex-direction: column; align-items: center; flex: 1; min-width: 36px; border-radius: 6px; padding: 4px 2px; cursor: pointer; position: relative; }
        .cg-filled { background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); }
        .cg-missing { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.2); opacity: 0.6; }
        .cg-emoji { font-size: 16px; line-height: 1; }
        .cg-label { font-size: 7px; color: var(--fi-fg-muted, #9ca3af); margin-top: 2px; }
        .cg-tooltip { display: none; position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); background: #1f2937; color: white; padding: 4px 8px; border-radius: 6px; font-size: 11px; white-space: nowrap; z-index: 50; margin-bottom: 4px; }
        .cg-tooltip::after { content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%); border: 4px solid transparent; border-top-color: #1f2937; }
        .cg-cell:hover .cg-tooltip, .cg-cell:active .cg-tooltip, .cg-cell:focus .cg-tooltip { display: block; }
        .cg-legend { display: flex; gap: 16px; margin-top: 8px; font-size: 11px; color: var(--fi-fg-muted, #6b7280); }
        .cg-dot { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 4px; vertical-align: middle; }
        @media (max-width: 768px) { .cg-cell { width: 36px; } .cg-emoji { font-size: 14px; } .cg-label { display: none; } }
    </style>

    @php $grid = $this->getGrid(); @endphp

    <div class="cg-card">
        <div class="cg-title">Suivi par année</div>
        <div class="cg-scroll">
            @foreach($grid as $row)
                <div class="cg-row">
                    <span class="cg-year">{{ $row['year'] }}</span>
                    @foreach($row['categories'] as $cat)
                        <div class="cg-cell {{ $cat['filled'] ? 'cg-filled' : 'cg-missing' }}" tabindex="0">
                            <span class="cg-tooltip">{{ $cat['tooltip'] }}</span>
                            <span class="cg-emoji">{{ $cat['emoji'] }}</span>
                            <span class="cg-label">{{ $cat['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
        <div class="cg-legend">
            <span><span class="cg-dot" style="background:rgba(16,185,129,0.3);"></span> Renseigné</span>
            <span><span class="cg-dot" style="background:rgba(239,68,68,0.3);"></span> Manquant</span>
        </div>
    </div>
</x-filament-widgets::widget>
