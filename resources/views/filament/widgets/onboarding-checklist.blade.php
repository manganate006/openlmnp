<x-filament-widgets::widget>
    <style>
        .oc-card { background: var(--fi-body-bg, white); border-radius: 12px; padding: 20px 24px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border: 1px solid var(--fi-border-color, #e5e7eb); }
        .oc-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .oc-title { font-size: 15px; font-weight: 600; color: var(--fi-fg, #374151); display: flex; align-items: center; gap: 8px; }
        .oc-progress-wrap { margin-bottom: 20px; }
        .oc-progress-bar { height: 8px; border-radius: 4px; background: rgba(0,0,0,0.06); overflow: hidden; }
        .oc-progress-fill { height: 100%; border-radius: 4px; background: #10b981; transition: width 0.4s ease; }
        .oc-progress-label { font-size: 12px; color: var(--fi-fg-muted, #6b7280); margin-top: 4px; text-align: right; }
        .oc-steps { display: flex; flex-direction: column; gap: 0; }
        .oc-step { display: flex; align-items: flex-start; gap: 12px; padding: 12px 0; position: relative; }
        .oc-step + .oc-step { border-top: 1px solid rgba(0,0,0,0.04); }
        .oc-circle { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 13px; font-weight: 600; }
        .oc-circle-completed { background: #d1fae5; color: #059669; }
        .oc-circle-current { background: #dbeafe; color: #2563eb; animation: oc-pulse 2s infinite; }
        .oc-circle-pending { background: rgba(0,0,0,0.04); color: #9ca3af; }
        @keyframes oc-pulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(37,99,235,0.2); } 50% { box-shadow: 0 0 0 6px rgba(37,99,235,0); } }
        .oc-content { flex: 1; min-width: 0; }
        .oc-label { font-size: 14px; font-weight: 500; color: var(--fi-fg, #374151); }
        .oc-label-completed { text-decoration: line-through; color: var(--fi-fg-muted, #6b7280); }
        .oc-desc { font-size: 12px; color: var(--fi-fg-muted, #9ca3af); margin-top: 2px; }
        .oc-action { flex-shrink: 0; align-self: center; }
        .oc-btn { display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 500; text-decoration: none; background: #2563eb; color: white; transition: background 0.2s; }
        .oc-btn:hover { background: #1d4ed8; }
        .oc-footer { margin-top: 16px; text-align: right; }
        .oc-dismiss { font-size: 12px; color: var(--fi-fg-muted, #9ca3af); cursor: pointer; background: none; border: none; padding: 4px 8px; border-radius: 4px; }
        .oc-dismiss:hover { color: var(--fi-fg, #374151); background: rgba(0,0,0,0.04); }
        .oc-year-select { font-size: 12px; padding: 4px 8px; border-radius: 4px; border: 1px solid var(--fi-border-color, #e5e7eb); background: var(--fi-body-bg, white); color: var(--fi-fg, #374151); }
    </style>

    @php $data = $this->getData(); @endphp

    @if($data['progress'] < 100 || !auth()->user()->onboarding_dismissed_at)
    <div class="oc-card">
        <div class="oc-header">
            <div class="oc-title">
                <x-filament::icon icon="heroicon-o-rocket-launch" class="w-5 h-5" style="color: #2563eb;" />
                Guide de demarrage
            </div>
            <select class="oc-year-select" wire:change="setYear(parseInt($event.target.value))">
                @for($y = (int) date('Y'); $y >= (int) date('Y') - 3; $y--)
                    <option value="{{ $y }}" @selected($y === $data['year'])>{{ $y }}</option>
                @endfor
            </select>
        </div>

        <div class="oc-progress-wrap">
            <div class="oc-progress-bar">
                <div class="oc-progress-fill" style="width: {{ $data['progress'] }}%"></div>
            </div>
            <div class="oc-progress-label">{{ $data['progress'] }}% — Exercice {{ $data['year'] }}</div>
        </div>

        <div class="oc-steps">
            @foreach($data['steps'] as $step)
                <div class="oc-step">
                    <div class="oc-circle oc-circle-{{ $step['status'] }}">
                        @if($step['status'] === 'completed')
                            <x-filament::icon icon="heroicon-o-check" class="w-4 h-4" />
                        @elseif($step['status'] === 'current')
                            <x-filament::icon icon="{{ $step['icon'] }}" class="w-4 h-4" />
                        @else
                            {{ $loop->iteration }}
                        @endif
                    </div>
                    <div class="oc-content">
                        <div class="oc-label {{ $step['status'] === 'completed' ? 'oc-label-completed' : '' }}">
                            {{ $step['label'] }}
                        </div>
                        <div class="oc-desc">{{ $step['description'] }}</div>
                    </div>
                    @if($step['status'] === 'current')
                        <div class="oc-action">
                            <a href="{{ $step['url'] }}" class="oc-btn">
                                <x-filament::icon icon="heroicon-o-arrow-right" class="w-3 h-3" />
                                Commencer
                            </a>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="oc-footer">
            <button class="oc-dismiss" wire:click="dismiss">Masquer ce guide</button>
        </div>
    </div>
    @endif
</x-filament-widgets::widget>
