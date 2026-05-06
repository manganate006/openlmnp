<x-filament-panels::page>
    <style>
        .de-card { background: var(--fi-body-bg, white); border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border: 1px solid var(--fi-border-color, #e5e7eb); margin-bottom: 16px; }
        .de-grid { display: grid; gap: 12px; }
        .de-grid-4 { grid-template-columns: repeat(4, 1fr); }
        .de-grid-main { grid-template-columns: 1fr 380px; }
        .de-stat { text-align: center; }
        .de-stat-value { font-size: 22px; font-weight: 700; }
        .de-stat-label { font-size: 11px; color: var(--fi-fg-muted, #6b7280); margin-top: 4px; }
        .de-stat-green .de-stat-value { color: #059669; }
        .de-stat-amber .de-stat-value { color: #d97706; }
        .de-stat-red .de-stat-value { color: #dc2626; }
        .de-stat-blue .de-stat-value { color: #1e40af; }
        .de-select { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; background: var(--fi-body-bg, white); color: var(--fi-fg, #374151); }
        .de-section-title { font-size: 13px; font-weight: 700; color: var(--fi-fg-muted, #6b7280); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid var(--fi-border-color, #e5e7eb); }

        .de-comp { display: grid; grid-template-columns: 28px 1fr; gap: 12px; align-items: start; padding: 12px 0; border-bottom: 1px solid var(--fi-border-color, #e5e7eb); }
        .de-comp:last-child { border-bottom: none; }
        .de-comp-disabled { opacity: 0.45; }
        .de-comp-checkbox { width: 18px; height: 18px; accent-color: #10b981; cursor: pointer; margin-top: 3px; }
        .de-comp-name { font-weight: 600; font-size: 14px; color: var(--fi-fg, #374151); }
        .de-comp-emoji { margin-right: 6px; }
        .de-comp-row { display: flex; align-items: center; gap: 12px; margin-top: 6px; flex-wrap: wrap; }

        .de-slider-container { flex: 1; min-width: 120px; display: flex; align-items: center; gap: 8px; }
        .de-slider { -webkit-appearance: none; appearance: none; width: 100%; height: 6px; border-radius: 3px; background: #e5e7eb; outline: none; cursor: pointer; }
        .de-slider::-webkit-slider-thumb { -webkit-appearance: none; appearance: none; width: 18px; height: 18px; border-radius: 50%; background: #10b981; cursor: pointer; border: 2px solid white; box-shadow: 0 1px 3px rgba(0,0,0,.2); }
        .de-slider::-moz-range-thumb { width: 18px; height: 18px; border-radius: 50%; background: #10b981; cursor: pointer; border: 2px solid white; box-shadow: 0 1px 3px rgba(0,0,0,.2); }
        .de-slider:disabled { opacity: 0.3; cursor: not-allowed; }
        .de-pct { font-family: monospace; font-weight: 700; font-size: 15px; min-width: 42px; text-align: right; color: var(--fi-fg, #374151); }
        .de-duration-input { width: 52px; padding: 3px 6px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; text-align: center; background: var(--fi-body-bg, white); color: var(--fi-fg, #374151); }
        .de-duration-label { font-size: 12px; color: var(--fi-fg-muted, #6b7280); }
        .de-amount { font-family: monospace; font-size: 13px; color: var(--fi-fg-muted, #6b7280); min-width: 90px; text-align: right; }

        .de-actions { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .de-btn { padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; }
        .de-btn-primary { background: #10b981; color: white; }
        .de-btn-primary:hover { background: #059669; }
        .de-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
        .de-btn-secondary { background: transparent; color: var(--fi-fg-muted, #6b7280); border: 1px solid #d1d5db; }
        .de-btn-secondary:hover { background: var(--fi-bg-muted, #f3f4f6); }

        .de-chart-container { position: sticky; top: 80px; }
        .de-chart-legend { margin-top: 16px; }
        .de-chart-legend-item { display: flex; align-items: center; gap: 8px; font-size: 12px; padding: 3px 0; color: var(--fi-fg, #374151); }
        .de-chart-legend-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }

        .de-dirty-badge { display: inline-block; background: #fef3c7; color: #92400e; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 12px; margin-left: 8px; }

        @media (max-width: 1024px) {
            .de-grid-4 { grid-template-columns: repeat(2, 1fr); }
            .de-grid-main { grid-template-columns: 1fr; }
            .de-chart-container { position: static; }
        }
        @media (max-width: 640px) {
            .de-grid-4 { grid-template-columns: 1fr; }
            .de-comp-row { flex-direction: column; align-items: stretch; }
            .de-slider-container { min-width: 100%; }
        }
    </style>

    @php $data = $this->editorData; @endphp

    @if($data['empty'] ?? true)
        <div class="de-card" style="text-align:center;padding:48px;">
            <p style="font-size:18px;color:#6b7280;">Aucun bien enregistré. Ajoutez un bien dans Mes biens pour configurer les composants d'amortissement.</p>
        </div>
    @else
        <div
            x-data="depreciationEditor(@js($data))"
            @components-loaded.window="reload($event.detail.data)"
            wire:ignore
        >
            {{-- Sélecteur de bien --}}
            @if(count($this->properties) > 1)
                <div class="de-card" style="display:flex;align-items:center;gap:12px;">
                    <label style="font-weight:600;font-size:14px;">Bien :</label>
                    <select
                        class="de-select"
                        @change="$wire.set('propertyId', parseInt($event.target.value))"
                    >
                        @foreach($this->properties as $prop)
                            <option value="{{ $prop['id'] }}" @if($prop['id'] === $this->propertyId) selected @endif>
                                {{ $prop['name'] }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif

            {{-- KPIs --}}
            <div class="de-grid de-grid-4">
                <div class="de-card de-stat de-stat-blue">
                    <div class="de-stat-value" x-text="formatEuros(depreciableBase)"></div>
                    <div class="de-stat-label">Base amortissable</div>
                </div>
                <div class="de-card de-stat" :class="getTotalClass()">
                    <div class="de-stat-value" x-text="getTotalPercentage() + ' %'"></div>
                    <div class="de-stat-label">Total alloué</div>
                </div>
                <div class="de-card de-stat de-stat-green">
                    <div class="de-stat-value" x-text="formatEuros(getTotalAnnualDepreciation())"></div>
                    <div class="de-stat-label">Amortissement annuel</div>
                </div>
                <div class="de-card de-stat de-stat-blue">
                    <div class="de-stat-value" x-text="getWeightedDuration() + ' ans'"></div>
                    <div class="de-stat-label">Durée moyenne pondérée</div>
                </div>
            </div>

            {{-- Layout principal --}}
            <div class="de-grid de-grid-main">
                {{-- Colonne gauche : composants --}}
                <div>
                    {{-- Standards --}}
                    <div class="de-card">
                        <div class="de-section-title">Composants standards</div>
                        <template x-for="(comp, idx) in components" :key="idx">
                            <div x-show="!comp.optional" class="de-comp">
                                <input
                                    type="checkbox"
                                    class="de-comp-checkbox"
                                    :checked="comp.enabled"
                                    @change="toggleStandard(idx)"
                                >
                                <div>
                                    <div class="de-comp-name">
                                        <span class="de-comp-emoji" x-text="getEmoji(comp.name)"></span>
                                        <span x-text="comp.name"></span>
                                    </div>
                                    <div class="de-comp-row">
                                        <div class="de-slider-container">
                                            <input
                                                type="range"
                                                class="de-slider"
                                                min="0" max="100" step="1"
                                                :value="comp.percentage"
                                                @input="onSlider(idx, parseInt($event.target.value))"
                                                :disabled="!comp.enabled"
                                            >
                                        </div>
                                        <span class="de-pct" x-text="comp.percentage + ' %'"></span>
                                        <input
                                            type="number"
                                            class="de-duration-input"
                                            min="1" max="100"
                                            :value="comp.duration"
                                            @change="comp.duration = parseInt($event.target.value); markDirty()"
                                            :disabled="!comp.enabled"
                                        >
                                        <span class="de-duration-label">ans</span>
                                        <span class="de-amount" x-text="formatEuros(depreciableBase * comp.percentage / 100) + ' €'"></span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- Optionnels --}}
                    <div class="de-card">
                        <div class="de-section-title">Composants optionnels (maison)</div>
                        <template x-for="(comp, idx) in components" :key="idx">
                            <div x-show="comp.optional" class="de-comp" :class="!comp.enabled && 'de-comp-disabled'">
                                <input
                                    type="checkbox"
                                    class="de-comp-checkbox"
                                    :checked="comp.enabled"
                                    @change="toggleOptional(idx)"
                                >
                                <div>
                                    <div class="de-comp-name">
                                        <span class="de-comp-emoji" x-text="getEmoji(comp.name)"></span>
                                        <span x-text="comp.name"></span>
                                    </div>
                                    <div class="de-comp-row">
                                        <div class="de-slider-container">
                                            <input
                                                type="range"
                                                class="de-slider"
                                                min="0" max="100" step="1"
                                                :value="comp.percentage"
                                                @input="onSlider(idx, parseInt($event.target.value))"
                                                :disabled="!comp.enabled"
                                            >
                                        </div>
                                        <span class="de-pct" x-text="comp.percentage + ' %'"></span>
                                        <input
                                            type="number"
                                            class="de-duration-input"
                                            min="1" max="100"
                                            :value="comp.duration"
                                            @change="comp.duration = parseInt($event.target.value); markDirty()"
                                            :disabled="!comp.enabled"
                                        >
                                        <span class="de-duration-label">ans</span>
                                        <span class="de-amount" x-text="comp.enabled ? formatEuros(depreciableBase * comp.percentage / 100) + ' €' : '—'"></span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- Actions --}}
                    <div class="de-card de-actions">
                        <button class="de-btn de-btn-secondary" @click="$wire.resetToDefaults()">
                            Réinitialiser par défaut
                        </button>
                        <div style="display:flex;align-items:center;gap:12px;">
                            <span class="de-dirty-badge" x-show="isDirty" x-cloak>Modifications non enregistrées</span>
                            <button
                                class="de-btn de-btn-primary"
                                @click="save()"
                                :disabled="getTotalPercentage() !== 100"
                            >
                                Enregistrer
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Colonne droite : camembert --}}
                <div>
                    <div class="de-card de-chart-container">
                        <div class="de-section-title">Répartition</div>
                        <canvas x-ref="doughnutCanvas" style="max-height:300px;"></canvas>
                        <div class="de-chart-legend">
                            <template x-for="(item, i) in getEnabledComponents()" :key="item.name">
                                <div class="de-chart-legend-item">
                                    <span class="de-chart-legend-dot" :style="'background:' + chartColors[i % chartColors.length]"></span>
                                    <span x-text="getEmoji(item.name)"></span>
                                    <span x-text="item.name"></span>
                                    <span style="margin-left:auto;font-weight:600;font-family:monospace;" x-text="item.percentage + ' %'"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('depreciationEditor', (initialData) => ({
                    components: [],
                    depreciableBase: 0,
                    chart: null,
                    isDirty: false,
                    savedState: '',
                    _version: 0,

                    chartColors: [
                        '#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6',
                        '#06b6d4', '#ec4899', '#f97316', '#14b8a6', '#6366f1', '#84cc16'
                    ],

                    emojiMap: {
                        'Gros \u0153uvre': '\u{1F3D7}\uFE0F',
                        'Toiture': '\u{1F3E0}',
                        'Installations \u00E9lectriques': '\u26A1',
                        '\u00C9tanch\u00E9it\u00E9': '\u2600\uFE0F',
                        'Agencements int\u00E9rieurs': '\u{1F3A8}',
                        'Plomberie / sanitaire': '\u{1F6BF}',
                        'Piscine': '\u{1F3CA}',
                        'Climatisation / chauffage': '\u2744\uFE0F',
                        'Cuisine \u00E9quip\u00E9e': '\u{1F373}',
                        'VRD (voirie, r\u00E9seaux)': '\u{1F6A7}',
                        'Am\u00E9nagements ext\u00E9rieurs': '\u{1F333}',
                    },

                    init() {
                        this.loadData(initialData);
                        this.$nextTick(() => this.initChart());
                    },

                    reload(data) {
                        if (data && !data.empty) {
                            this.loadData(data);
                            this.$nextTick(() => this.updateChart());
                        }
                    },

                    loadData(data) {
                        this.components = JSON.parse(JSON.stringify(data.components));
                        this.depreciableBase = data.depreciableBase;
                        this.savedState = JSON.stringify(this.components);
                        this.isDirty = false;
                        this._version++;
                    },

                    getEmoji(name) {
                        return this.emojiMap[name] || '\u{1F4E6}';
                    },

                    getEnabledComponents() {
                        // Force reactivity via _version
                        void this._version;
                        return this.components.filter(c => c.enabled && c.percentage > 0);
                    },

                    getTotalPercentage() {
                        void this._version;
                        return this.components
                            .filter(c => c.enabled)
                            .reduce((s, c) => s + c.percentage, 0);
                    },

                    getTotalClass() {
                        const t = this.getTotalPercentage();
                        return t === 100 ? 'de-stat-green' : (t < 100 ? 'de-stat-amber' : 'de-stat-red');
                    },

                    getTotalAnnualDepreciation() {
                        void this._version;
                        return this.getEnabledComponents().reduce((s, c) => {
                            return s + (c.duration > 0 ? (this.depreciableBase * c.percentage / 100) / c.duration : 0);
                        }, 0);
                    },

                    getWeightedDuration() {
                        const enabled = this.getEnabledComponents();
                        if (enabled.length === 0) return 0;
                        const totalPct = enabled.reduce((s, c) => s + c.percentage, 0);
                        if (totalPct === 0) return 0;
                        const weighted = enabled.reduce((s, c) => s + c.duration * c.percentage, 0);
                        return Math.round(weighted / totalPct);
                    },

                    formatEuros(val) {
                        return Math.round(val).toLocaleString('fr-FR') + ' \u20AC';
                    },

                    markDirty() {
                        this._version++;
                        this.isDirty = JSON.stringify(this.components) !== this.savedState;
                        this.updateChart();
                    },

                    findGrosOeuvre() {
                        return this.components.find(c => c.name === 'Gros \u0153uvre');
                    },

                    toggleOptional(idx) {
                        const comp = this.components[idx];
                        comp.enabled = !comp.enabled;
                        const go = this.findGrosOeuvre();

                        if (comp.enabled) {
                            const pct = comp.suggestedPercentage;
                            if (go && go.enabled && go.percentage >= pct) {
                                comp.percentage = pct;
                                go.percentage -= pct;
                            } else {
                                comp.percentage = pct;
                                this.redistributeFrom(idx, pct);
                            }
                        } else {
                            const freed = comp.percentage;
                            comp.percentage = 0;
                            if (go && go.enabled) {
                                go.percentage += freed;
                            } else {
                                this.redistributeTo(freed);
                            }
                        }
                        this.markDirty();
                    },

                    toggleStandard(idx) {
                        const comp = this.components[idx];
                        comp.enabled = !comp.enabled;
                        if (!comp.enabled) {
                            const freed = comp.percentage;
                            comp.percentage = 0;
                            this.redistributeTo(freed);
                        }
                        this.markDirty();
                    },

                    redistributeFrom(changedIdx, amount) {
                        const others = this.components.filter((c, i) => i !== changedIdx && c.enabled && c.percentage > 0);
                        const total = others.reduce((s, c) => s + c.percentage, 0);
                        if (total === 0) return;
                        let remaining = amount;
                        others.forEach((c, i) => {
                            if (i === others.length - 1) {
                                c.percentage -= remaining;
                            } else {
                                const share = Math.round(amount * c.percentage / total);
                                c.percentage -= share;
                                remaining -= share;
                            }
                            c.percentage = Math.max(0, c.percentage);
                        });
                    },

                    redistributeTo(amount) {
                        const others = this.components.filter(c => c.enabled && c.percentage > 0);
                        if (others.length === 0) return;
                        const total = others.reduce((s, c) => s + c.percentage, 0);
                        let remaining = amount;
                        others.forEach((c, i) => {
                            if (i === others.length - 1) {
                                c.percentage += remaining;
                            } else {
                                const share = Math.round(amount * c.percentage / total);
                                c.percentage += share;
                                remaining -= share;
                            }
                        });
                    },

                    onSlider(idx, newValue) {
                        const comp = this.components[idx];
                        const oldValue = comp.percentage;
                        const diff = newValue - oldValue;
                        if (diff === 0) return;

                        comp.percentage = newValue;

                        const others = this.components.filter((c, i) => i !== idx && c.enabled && c.percentage > 0);
                        const othersTotal = others.reduce((s, c) => s + c.percentage, 0);

                        if (othersTotal > 0) {
                            let distributed = 0;
                            others.forEach((c, i) => {
                                if (i === others.length - 1) {
                                    c.percentage -= (diff - distributed);
                                } else {
                                    const share = Math.round(diff * c.percentage / othersTotal);
                                    c.percentage -= share;
                                    distributed += share;
                                }
                                c.percentage = Math.max(0, c.percentage);
                            });
                            this.fixRounding();
                        }

                        this.markDirty();
                    },

                    fixRounding() {
                        const enabled = this.components.filter(c => c.enabled);
                        const total = enabled.reduce((s, c) => s + c.percentage, 0);
                        if (total === 100 || enabled.length === 0) return;

                        const diff = 100 - total;
                        let biggest = enabled.reduce((a, b) => a.percentage >= b.percentage ? a : b);
                        biggest.percentage += diff;
                        if (biggest.percentage < 0) biggest.percentage = 0;
                    },

                    initChart() {
                        const ctx = this.$refs.doughnutCanvas;
                        if (!ctx) return;

                        this.chart = new Chart(ctx, {
                            type: 'doughnut',
                            data: this.getChartData(),
                            options: {
                                responsive: true,
                                maintainAspectRatio: true,
                                cutout: '55%',
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        callbacks: {
                                            label: (context) => {
                                                const enabled = this.getEnabledComponents();
                                                const comp = enabled[context.dataIndex];
                                                if (!comp) return '';
                                                const base = Math.round(this.depreciableBase * comp.percentage / 100);
                                                return ` ${comp.percentage} % \u2014 ${base.toLocaleString('fr-FR')} \u20AC`;
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    },

                    getChartData() {
                        const enabled = this.getEnabledComponents();
                        return {
                            labels: enabled.map(c => this.getEmoji(c.name) + ' ' + c.name),
                            datasets: [{
                                data: enabled.map(c => c.percentage),
                                backgroundColor: this.chartColors.slice(0, enabled.length),
                                borderWidth: 2,
                                borderColor: getComputedStyle(document.documentElement).getPropertyValue('--fi-body-bg') || '#fff',
                            }]
                        };
                    },

                    updateChart() {
                        if (!this.chart) return;
                        const data = this.getChartData();
                        this.chart.data.labels = data.labels;
                        this.chart.data.datasets[0].data = data.datasets[0].data;
                        this.chart.data.datasets[0].backgroundColor = data.datasets[0].backgroundColor;
                        this.chart.update('none');
                    },

                    save() {
                        if (this.getTotalPercentage() !== 100) return;
                        const payload = JSON.parse(JSON.stringify(this.components));
                        this.$wire.saveComponents(payload).then(() => {
                            this.savedState = JSON.stringify(this.components);
                            this.isDirty = false;
                        });
                    },
                }));
            });
        </script>
    @endif
</x-filament-panels::page>
