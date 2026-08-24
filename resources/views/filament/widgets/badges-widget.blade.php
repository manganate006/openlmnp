<x-filament-widgets::widget>
    <style>
        .bw-card { background: var(--olmnp-surface); border-radius: 12px; padding: 16px 20px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border: 1px solid var(--olmnp-border); }
        .bw-title { font-size: 14px; font-weight: 600; margin-bottom: 12px; color: var(--olmnp-fg); display: flex; align-items: center; gap: 8px; }
        .bw-grid { display: flex; flex-direction: column; gap: 16px; }

        /* Completeness */
        .bw-progress { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 8px; }
        .bw-pitem { flex: 1; min-width: 100px; }
        .bw-pbar { height: 6px; border-radius: 3px; background: var(--olmnp-surface-muted); overflow: hidden; margin-top: 4px; }
        .bw-pfill { height: 100%; border-radius: 3px; transition: width 0.3s; }
        .bw-plabel { font-size: 11px; color: var(--olmnp-fg-muted); display: flex; justify-content: space-between; }

        /* Heatmap */
        .bw-heatmap { display: flex; gap: 4px; margin-top: 8px; }
        .bw-hcell { flex: 1; text-align: center; }
        .bw-hdot { width: 100%; height: 24px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 600; color: var(--olmnp-on-solid); }
        .bw-hcomplete { background: var(--olmnp-success-solid); }
        .bw-hpartial { background: var(--olmnp-warning-solid); }
        .bw-hempty { background: var(--olmnp-surface-muted); }
        .bw-hmonth { font-size: 9px; color: var(--olmnp-fg-muted); margin-top: 2px; }

        /* Badges */
        .bw-badges { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
        .bw-badge { display: flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 8px; background: var(--olmnp-surface-alt); border: 1px solid var(--olmnp-border); font-size: 12px; }
        .bw-badge-icon { width: 20px; height: 20px; }
        .bw-badge-info { display: flex; flex-direction: column; }
        .bw-badge-name { font-weight: 600; color: var(--olmnp-fg); font-size: 12px; }
        .bw-badge-date { font-size: 10px; color: var(--olmnp-fg-muted); }

        /* Next badge */
        .bw-next { margin-top: 8px; padding: 8px 12px; border-radius: 8px; background: color-mix(in oklab, var(--olmnp-accent-accent) 6%, transparent); border: 1px solid color-mix(in oklab, var(--olmnp-accent-accent) 15%, transparent); }
        .bw-next-title { font-size: 12px; font-weight: 600; color: var(--olmnp-accent-accent); }
        .bw-next-desc { font-size: 11px; color: var(--olmnp-fg-muted); margin-top: 2px; }

        /* Counter */
        .bw-counter { font-size: 12px; color: var(--olmnp-fg-muted); margin-top: 8px; }
        .bw-counter strong { color: var(--olmnp-fg); }
    </style>

    @php $data = $this->getData(); @endphp

    <div class="bw-card">
        <div class="bw-title">
            <x-filament::icon icon="heroicon-o-trophy" style="color: var(--olmnp-warning-accent);" />
            Suivi et badges {{ $data['year'] }}
        </div>

        <div class="bw-grid">
            {{-- Left: Completeness + Heatmap --}}
            <div>
                <div style="font-size: 12px; font-weight: 600; color: var(--olmnp-fg-muted); margin-bottom: 6px;">
                    Completude de l'exercice : {{ $data['completeness']['total'] }}%
                </div>
                <div class="bw-progress">
                    @php
                        $items = [
                            ['label' => 'Recettes', 'value' => $data['completeness']['incomes'], 'max' => 25, 'color' => 'var(--olmnp-success-solid)'],
                            ['label' => 'Charges', 'value' => $data['completeness']['expenses'], 'max' => 25, 'color' => 'var(--olmnp-warning-solid)'],
                            ['label' => 'Amortiss.', 'value' => $data['completeness']['depreciation'], 'max' => 25, 'color' => 'var(--olmnp-accent-solid)'],
                            ['label' => 'Justif.', 'value' => $data['completeness']['receipts'], 'max' => 25, 'color' => 'var(--olmnp-info-solid)'],
                        ];
                    @endphp
                    @foreach($items as $item)
                        <div class="bw-pitem">
                            <div class="bw-plabel">
                                <span>{{ $item['label'] }}</span>
                                <span>{{ $item['value'] }}/{{ $item['max'] }}</span>
                            </div>
                            <div class="bw-pbar">
                                <div class="bw-pfill" style="width: {{ ($item['value'] / $item['max']) * 100 }}%; background: {{ $item['color'] }};"></div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div style="font-size: 12px; font-weight: 600; color: var(--olmnp-fg-muted); margin-top: 12px; margin-bottom: 2px;">
                    Saisie mensuelle
                </div>
                <div class="bw-heatmap">
                    @foreach($data['heatmap'] as $month => $status)
                        <div class="bw-hcell">
                            <div class="bw-hdot bw-h{{ $status }}">
                                @if($status === 'complete') &#10003; @elseif($status === 'partial') ~ @endif
                            </div>
                            <div class="bw-hmonth">{{ $data['months'][$month - 1] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Right: Badges --}}
            <div>
                <div style="font-size: 12px; font-weight: 600; color: var(--olmnp-fg-muted); margin-bottom: 6px;">
                    Derniers badges obtenus
                </div>
                @if($data['recentBadges']->isEmpty())
                    <div style="font-size: 12px; color: var(--olmnp-fg-muted); padding: 8px 0;">
                        Aucun badge obtenu pour le moment.
                    </div>
                @else
                    <div class="bw-badges">
                        @foreach($data['recentBadges'] as $ub)
                            <div class="bw-badge">
                                {{-- `--{couleur}-500` (sans préfixe `fi-`) : palettes injectées à l'exécution par ->colors(). --}}
                                <x-filament::icon :icon="$ub->definition->icon" class="bw-badge-icon"
                                    style="color: var(--{{ $ub->definition->color }}-500, var(--olmnp-fg-muted));" />
                                <div class="bw-badge-info">
                                    <span class="bw-badge-name">{{ $ub->definition->name }}@if($ub->fiscal_year) {{ $ub->fiscal_year }}@endif</span>
                                    <span class="bw-badge-date">{{ $ub->unlocked_at->format('d/m/Y') }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if($data['nextBadge'])
                    <div class="bw-next">
                        <div class="bw-next-title">
                            <x-filament::icon :icon="$data['nextBadge']['badge']->icon" :size="\Filament\Support\Enums\IconSize::Small" style="display: inline;" />
                            Prochain : {{ $data['nextBadge']['badge']->name }}
                        </div>
                        <div class="bw-next-desc">{{ $data['nextBadge']['badge']->description }}</div>
                        <div class="bw-pbar" style="margin-top: 6px;">
                            <div class="bw-pfill" style="width: {{ $data['nextBadge']['progress']['percentage'] }}%; background: var(--olmnp-accent-solid);"></div>
                        </div>
                        <div style="font-size: 10px; color: var(--olmnp-fg-muted); margin-top: 2px;">
                            {{ $data['nextBadge']['progress']['current'] }} / {{ $data['nextBadge']['progress']['target'] }}
                        </div>
                    </div>
                @endif

                <div class="bw-counter">
                    <strong>{{ $data['totalBadges'] }}</strong> badge(s) obtenu(s)
                    &middot; <strong>{{ $data['yearlyEarned'] }}/{{ $data['yearlyTotal'] }}</strong> badges annuels {{ $data['year'] }}
                    <br>
                    <a href="/badges" style="color: var(--olmnp-accent-accent); text-decoration: none; font-size: 11px;">Voir tous les badges &rarr;</a>
                </div>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
