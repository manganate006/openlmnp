<x-filament-panels::page>
    <style>
        .bp-hero { text-align: center; margin-bottom: 28px; padding: 20px; border-radius: 12px; background: linear-gradient(135deg, color-mix(in oklab, var(--olmnp-accent-accent) 6%, transparent), color-mix(in oklab, var(--olmnp-warning-accent) 6%, transparent)); border: 1px solid var(--olmnp-border); }
        .bp-hero-count { font-size: 32px; font-weight: 800; color: var(--olmnp-fg); }
        .bp-hero-label { font-size: 14px; color: var(--olmnp-fg-muted); margin-top: 4px; }
        .bp-hero-sub { font-size: 12px; color: var(--olmnp-fg-muted); margin-top: 8px; }

        .bp-section { margin-bottom: 28px; }
        .bp-section-header { padding: 12px 16px; border-radius: 10px 10px 0 0; background: var(--olmnp-surface-alt); border: 1px solid var(--olmnp-border); border-bottom: none; }
        .bp-section-title { font-size: 16px; font-weight: 700; color: var(--olmnp-fg); display: flex; align-items: center; gap: 8px; }
        .bp-section-desc { font-size: 12px; color: var(--olmnp-fg-muted); margin-top: 4px; }
        .bp-section-stats { font-size: 11px; color: var(--olmnp-fg-muted); margin-top: 2px; }

        .bp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 0; border: 1px solid var(--olmnp-border); border-top: none; border-radius: 0 0 10px 10px; overflow: hidden; }
        .bp-card { padding: 16px 18px; background: var(--olmnp-surface); border-bottom: 1px solid var(--olmnp-border); border-right: 1px solid var(--olmnp-border); transition: all 0.2s; }
        .bp-card:hover { background: var(--olmnp-surface-alt); }
        .bp-card-earned { background: color-mix(in oklab, var(--olmnp-success-accent) 3%, transparent); }
        .bp-card-locked { }

        .bp-header { display: flex; align-items: flex-start; gap: 12px; }
        .bp-icon-wrap { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .bp-icon-earned { background: color-mix(in oklab, var(--olmnp-success-accent) 10%, transparent); }
        .bp-icon-locked { background: var(--olmnp-surface-alt); }
        .bp-icon { width: 24px; height: 24px; }

        .bp-content { flex: 1; min-width: 0; }
        .bp-name { font-size: 14px; font-weight: 700; color: var(--olmnp-fg); display: flex; align-items: center; gap: 6px; }
        .bp-yearly-tag { font-size: 9px; padding: 1px 5px; border-radius: 3px; background: color-mix(in oklab, var(--olmnp-accent-accent) 10%, transparent); color: var(--olmnp-accent-accent); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .bp-desc { font-size: 12px; color: var(--olmnp-fg-muted); line-height: 1.5; margin-top: 4px; }

        /* How to unlock */
        .bp-hint { margin-top: 8px; padding: 8px 10px; border-radius: 6px; background: color-mix(in oklab, var(--olmnp-accent-accent) 4%, transparent); border: 1px dashed color-mix(in oklab, var(--olmnp-accent-accent) 20%, transparent); }
        .bp-hint-label { font-size: 10px; font-weight: 700; color: var(--olmnp-accent-accent); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px; }
        .bp-hint-text { font-size: 11px; color: var(--olmnp-fg-muted); line-height: 1.4; }

        /* Progress bar */
        .bp-progress { margin-top: 8px; }
        .bp-pbar { height: 5px; border-radius: 3px; background: var(--olmnp-surface-muted); overflow: hidden; }
        .bp-pfill { height: 100%; border-radius: 3px; background: var(--olmnp-accent-solid); transition: width 0.3s; }
        .bp-ptext { font-size: 10px; color: var(--olmnp-fg-muted); margin-top: 3px; display: flex; justify-content: space-between; }

        /* Status */
        .bp-meta { margin-top: 8px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .bp-year-pill { display: inline-block; padding: 2px 7px; border-radius: 4px; background: color-mix(in oklab, var(--olmnp-success-accent) 12%, transparent); color: var(--olmnp-success-accent); font-size: 11px; font-weight: 600; }
        .bp-earned-check { display: inline-flex; align-items: center; gap: 4px; color: var(--olmnp-success-accent); font-size: 12px; font-weight: 600; }
        .bp-locked-label { color: var(--olmnp-fg-subtle); font-size: 11px; }
    </style>

    @php $data = $this->getBadgeData(); @endphp

    {{-- Hero counter --}}
    <div class="bp-hero">
        <div class="bp-hero-count">{{ $data['totalEarned'] }} <span style="font-size: 18px; color: var(--olmnp-fg-muted);">/ {{ $data['totalDefinitions'] }}</span></div>
        <div class="bp-hero-label">badge(s) obtenu(s) au total</div>
        <div class="bp-hero-sub">
            {{ $data['earnedThisYear'] }} / {{ $data['totalPossibleThisYear'] }} badges annuels {{ $data['year'] }}
        </div>
    </div>

    @foreach($data['categories'] as $catCode => $cat)
        <div class="bp-section">
            {{-- Section header --}}
            <div class="bp-section-header">
                <div class="bp-section-title">
                    <x-filament::icon :icon="$cat['icon']" />
                    {{ $cat['label'] }}
                    <span style="font-size: 12px; font-weight: 400; color: var(--olmnp-fg-muted); margin-left: auto;">
                        {{ $cat['earned_count'] }} / {{ $cat['total_count'] }}
                    </span>
                </div>
                <div class="bp-section-desc">{{ $cat['description'] }}</div>
            </div>

            {{-- Badge grid --}}
            <div class="bp-grid">
                @foreach($cat['items'] as $item)
                    <div class="bp-card {{ $item['earned'] ? 'bp-card-earned' : 'bp-card-locked' }}">
                        <div class="bp-header">
                            <div class="bp-icon-wrap {{ $item['earned'] ? 'bp-icon-earned' : 'bp-icon-locked' }}">
                                <x-filament::icon
                                    :icon="$item['badge']->icon"
                                    class="bp-icon"
                                    @style([
                                        // `--{couleur}-500` (sans préfixe `fi-`) : palettes injectées à l'exécution par ->colors().
                                        'color: var(--' . $item['badge']->color . '-500, var(--olmnp-fg-muted))' => $item['earned'],
                                        'color: var(--olmnp-fg-subtle)' => !$item['earned'],
                                    ])
                                />
                            </div>
                            <div class="bp-content">
                                <div class="bp-name">
                                    {{ $item['badge']->name }}
                                    @if($item['is_yearly'])
                                        <span class="bp-yearly-tag">annuel</span>
                                    @endif
                                </div>
                                <div class="bp-desc">{{ $item['badge']->description }}</div>
                            </div>
                        </div>

                        {{-- Earned status --}}
                        @if($item['earned'])
                            <div class="bp-meta">
                                @if($item['is_yearly'] && !empty($item['earned_years']))
                                    <span class="bp-earned-check">
                                        <x-filament::icon icon="heroicon-o-check-circle" :size="\Filament\Support\Enums\IconSize::Small" />
                                        Obtenu
                                    </span>
                                    @foreach($item['earned_years'] as $y)
                                        <span class="bp-year-pill">{{ $y }}</span>
                                    @endforeach
                                @elseif(!$item['is_yearly'] && $item['earned_at'])
                                    <span class="bp-earned-check">
                                        <x-filament::icon icon="heroicon-o-check-circle" :size="\Filament\Support\Enums\IconSize::Small" />
                                        Obtenu le {{ $item['earned_at']->format('d/m/Y') }}
                                    </span>
                                @endif
                            </div>
                        @else
                            {{-- How to unlock --}}
                            @if($item['badge']->hint)
                                <div class="bp-hint">
                                    <div class="bp-hint-label">Comment débloquer</div>
                                    <div class="bp-hint-text">{{ $item['badge']->hint }}</div>
                                </div>
                            @endif

                            {{-- Progress bar (if available) --}}
                            @if($item['progress'] && $item['progress']['percentage'] > 0)
                                <div class="bp-progress">
                                    <div class="bp-pbar">
                                        <div class="bp-pfill" style="width: {{ $item['progress']['percentage'] }}%;"></div>
                                    </div>
                                    <div class="bp-ptext">
                                        <span>{{ $item['progress']['current'] }} / {{ $item['progress']['target'] }}</span>
                                        <span>{{ $item['progress']['percentage'] }}%</span>
                                    </div>
                                </div>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</x-filament-panels::page>
