<x-filament-widgets::widget>
    <style>
        .oc-card { background: var(--olmnp-surface); border-radius: 12px; padding: 20px 24px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border: 1px solid var(--olmnp-border); }
        .oc-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .oc-title { font-size: 15px; font-weight: 600; color: var(--olmnp-fg); display: flex; align-items: center; gap: 8px; }
        .oc-progress-wrap { margin-bottom: 20px; }
        .oc-progress-bar { height: 8px; border-radius: 4px; background: var(--olmnp-surface-muted); overflow: hidden; }
        .oc-progress-fill { height: 100%; border-radius: 4px; background: var(--olmnp-success-solid); transition: width 0.4s ease; }
        .oc-progress-label { font-size: 12px; color: var(--olmnp-fg-muted); margin-top: 4px; text-align: right; }
        .oc-steps { display: flex; flex-direction: column; gap: 0; }
        .oc-step { display: flex; align-items: flex-start; gap: 12px; padding: 12px 0; position: relative; }
        .oc-step + .oc-step { border-top: 1px solid var(--olmnp-border); }
        .oc-circle { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 13px; font-weight: 600; }
        .oc-circle-completed { background: var(--olmnp-success-bg-strong); color: var(--olmnp-success-accent); }
        .oc-circle-current { background: var(--olmnp-info-bg-strong); color: var(--olmnp-info-accent); animation: oc-pulse 2s infinite; }
        .oc-circle-pending { background: var(--olmnp-surface-alt); color: var(--olmnp-fg-subtle); }
        @keyframes oc-pulse { 0%, 100% { box-shadow: 0 0 0 0 color-mix(in oklab, var(--olmnp-info-accent) 20%, transparent); } 50% { box-shadow: 0 0 0 6px color-mix(in oklab, var(--olmnp-info-accent) 0%, transparent); } }
        .oc-content { flex: 1; min-width: 0; }
        .oc-label { font-size: 14px; font-weight: 500; color: var(--olmnp-fg); }
        .oc-label-completed { text-decoration: line-through; color: var(--olmnp-fg-muted); }
        .oc-desc { font-size: 12px; color: var(--olmnp-fg-muted); margin-top: 2px; }
        .oc-action { flex-shrink: 0; align-self: center; }
        .oc-btn { display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 500; text-decoration: none; background: var(--olmnp-info-solid); color: var(--olmnp-on-solid); transition: background 0.2s; }
        .oc-btn:hover { background: var(--olmnp-info-solid-hover); }
        .oc-footer { margin-top: 16px; text-align: right; }
        .oc-dismiss { font-size: 12px; color: var(--olmnp-fg-muted); cursor: pointer; background: none; border: none; padding: 4px 8px; border-radius: 4px; }
        .oc-dismiss:hover { color: var(--olmnp-fg); background: var(--olmnp-surface-alt); }
        .oc-year-select { font-size: 12px; padding: 4px 8px; border-radius: 4px; border: 1px solid var(--olmnp-border); background: var(--olmnp-surface); color: var(--olmnp-fg); }
    </style>

    @php $data = $this->getData(); @endphp

    <div class="oc-card">
        <div class="oc-header">
            <div class="oc-title">
                <x-filament::icon icon="heroicon-o-rocket-launch" style="color: var(--olmnp-info-accent);" />
                Guide de demarrage
            </div>
            <select class="oc-year-select" wire:change="setYear($event.target.value)">
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
                            <x-filament::icon icon="heroicon-o-check" :size="\Filament\Support\Enums\IconSize::Small" />
                        @elseif($step['status'] === 'current')
                            <x-filament::icon icon="{{ $step['icon'] }}" :size="\Filament\Support\Enums\IconSize::Small" />
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
                                <x-filament::icon icon="heroicon-o-arrow-right" :size="\Filament\Support\Enums\IconSize::ExtraSmall" />
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
</x-filament-widgets::widget>
