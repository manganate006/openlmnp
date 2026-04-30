<x-filament-widgets::widget>
    <style>
        .cg-card { background: var(--fi-body-bg, white); border-radius: 12px; padding: 16px 20px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border: 1px solid var(--fi-border-color, #e5e7eb); }
        .cg-title { font-size: 14px; font-weight: 600; margin-bottom: 12px; color: var(--fi-fg, #374151); }
        .cg-row { display: flex; align-items: center; gap: 4px; margin-bottom: 6px; }
        .cg-year { font-size: 13px; font-weight: 700; color: var(--fi-fg, #374151); width: 45px; flex-shrink: 0; }
        .cg-cell { display: flex; flex-direction: column; align-items: center; width: 48px; flex-shrink: 0; border-radius: 6px; padding: 4px 2px; }
        .cg-filled { background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); }
        .cg-missing { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.2); opacity: 0.5; }
        .cg-emoji { font-size: 16px; line-height: 1; }
        .cg-label { font-size: 7px; color: var(--fi-fg-muted, #9ca3af); margin-top: 2px; }
        .cg-legend { display: flex; gap: 16px; margin-top: 8px; font-size: 11px; color: var(--fi-fg-muted, #6b7280); }
        .cg-dot { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 4px; vertical-align: middle; }
        @media (max-width: 768px) { .cg-cell { width: 32px; } .cg-emoji { font-size: 13px; } .cg-label { display: none; } }
    </style>

    @php $grid = $this->getGrid(); @endphp

    <div class="cg-card">
        <div class="cg-title">Complétude des charges par année</div>
        @foreach($grid as $row)
            <div class="cg-row">
                <span class="cg-year">{{ $row['year'] }}</span>
                @foreach($row['categories'] as $cat)
                    <div class="cg-cell {{ $cat['filled'] ? 'cg-filled' : 'cg-missing' }}" title="{{ $cat['label'] }} {{ $cat['filled'] ? '✓' : '✗' }}">
                        <span class="cg-emoji">{{ $cat['emoji'] }}</span>
                        <span class="cg-label">{{ $cat['label'] }}</span>
                    </div>
                @endforeach
            </div>
        @endforeach
        <div class="cg-legend">
            <span><span class="cg-dot" style="background:rgba(16,185,129,0.3);"></span> Renseigné</span>
            <span><span class="cg-dot" style="background:rgba(239,68,68,0.3);"></span> Manquant</span>
        </div>
    </div>
</x-filament-widgets::widget>
