<x-filament-panels::page>
    <style>
        .sim-card { background: var(--fi-body-bg, white); border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid var(--fi-border-color, #e5e7eb); margin-bottom: 16px; }
        .sim-grid { display: grid; gap: 16px; }
        .sim-grid-2 { grid-template-columns: repeat(2, 1fr); }
        .sim-grid-3 { grid-template-columns: repeat(3, 1fr); }
        .sim-label { font-size: 14px; color: var(--fi-fg-muted, #6b7280); margin-bottom: 4px; }
        .sim-value { font-size: 24px; font-weight: 700; }
        .sim-sub { font-size: 12px; margin-top: 4px; }
        .sim-card-amber { background: #fffbeb; border-color: #fbbf24; }
        .sim-card-green { background: #ecfdf5; border-color: #34d399; }
        .sim-verdict { padding: 20px; border-radius: 12px; display: flex; align-items: center; gap: 12px; margin: 16px 0; }
        .sim-verdict-green { background: #d1fae5; border: 2px solid #10b981; }
        .sim-verdict-amber { background: #fef3c7; border: 2px solid #f59e0b; }
        .sim-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .sim-table td, .sim-table th { padding: 8px 12px; border-bottom: 1px solid var(--fi-border-color, #e5e7eb); }
        .sim-table th { text-align: left; font-weight: 600; background: var(--fi-bg-muted, #f9fafb); }
        .sim-table .text-right { text-align: right; font-family: monospace; }
        .sim-table .total { font-weight: 700; border-top: 2px solid #d1d5db; }
        .sim-table .subtotal { font-weight: 600; color: #374151; }
        .sim-table .indent { padding-left: 24px; color: #6b7280; }
        .sim-table .positive { color: #059669; }
        .sim-table .negative { color: #dc2626; }
        .sim-table .result { font-weight: 700; font-size: 15px; background: #f0fdf4; }
        .sim-select { padding: 8px 12px; border-radius: 8px; border: 1px solid #d1d5db; width: 100%; font-size: 14px; }
        .sim-chart-container { position: relative; height: 260px; }
        @media (max-width: 768px) { .sim-grid-2, .sim-grid-3 { grid-template-columns: 1fr; } }
    </style>

    <div>
        {{-- Filtres --}}
        <div class="sim-grid sim-grid-2" style="margin-bottom: 24px;">
            <div>
                <div class="sim-label">Année</div>
                <select wire:model.live="year" class="sim-select">
                    @for($y = date('Y') + 1; $y >= date('Y') - 5; $y--)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endfor
                </select>
            </div>
            <div>
                <div class="sim-label">Abattement micro-BIC</div>
                <select wire:model.live="abatement" class="sim-select">
                    <option value="30">30% (meublé non classé)</option>
                    <option value="50">50% (meublé classé)</option>
                    <option value="71">71% (achat-revente)</option>
                </select>
            </div>
        </div>

        @php $r = $this->simulationResults; @endphp

        @if($r['empty'] ?? false)
            <div class="sim-card" style="text-align: center; padding: 48px;">
                <p style="font-size: 18px; color: var(--fi-fg-muted, #6b7280);">Ajoutez un bien immobilier pour lancer la simulation.</p>
            </div>
        @else
            {{-- Comparaison principale --}}
            <div class="sim-grid sim-grid-3">
                <div class="sim-card">
                    <div class="sim-label">CA brut {{ $r['year'] }}</div>
                    <div class="sim-value">{{ $r['gross_income'] }} €</div>
                </div>
                <div class="sim-card sim-card-amber">
                    <div class="sim-label" style="color: #92400e;">Résultat micro-BIC (abattement {{ $r['abatement'] }}%)</div>
                    <div class="sim-value" style="color: #92400e;">{{ $r['micro_bic_result'] }} €</div>
                    <div class="sim-sub" style="color: #b45309;">Base imposable ajoutée au foyer</div>
                </div>
                <div class="sim-card sim-card-green">
                    <div class="sim-label" style="color: #065f46;">Résultat régime réel</div>
                    <div class="sim-value" style="color: #065f46;">{{ $r['real_result'] }} €</div>
                    <div class="sim-sub" style="color: #047857;">Base imposable ajoutée au foyer</div>
                </div>
            </div>

            {{-- Verdict --}}
            @if($r['recommended'] === 'real')
                <div class="sim-verdict sim-verdict-green">
                    <svg xmlns="http://www.w3.org/2000/svg" style="width:32px;height:32px;color:#10b981;flex-shrink:0;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    <div>
                        <div style="font-size:18px;font-weight:700;color:#065f46;">Le régime réel est plus avantageux de {{ $r['advantage'] }} €</div>
                        <div style="font-size:14px;color:#047857;margin-top:4px;">
                            Économie d'impôt estimée : {{ $r['tax_saving_11'] }} € (TMI 11%) à {{ $r['tax_saving_30'] }} € (TMI 30%)
                            + {{ $r['ps_saving'] }} € de PS
                        </div>
                    </div>
                </div>
            @else
                <div class="sim-verdict sim-verdict-amber">
                    <svg xmlns="http://www.w3.org/2000/svg" style="width:32px;height:32px;color:#f59e0b;flex-shrink:0;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg>
                    <div>
                        <div style="font-size:18px;font-weight:700;color:#92400e;">Le micro-BIC est plus avantageux</div>
                        <div style="font-size:14px;color:#b45309;">Différence : {{ $r['advantage'] }} € en faveur du micro-BIC</div>
                    </div>
                </div>
            @endif

            {{-- Détail du calcul (waterfall) --}}
            <div class="sim-card">
                <h3 style="font-size:16px;font-weight:700;margin-bottom:16px;">Détail du calcul régime réel</h3>
                <table class="sim-table">
                    <tr><td>Recettes brutes</td><td class="text-right positive">{{ $r['gross_income'] }} €</td></tr>
                    <tr><td class="indent">Frais de plateforme</td><td class="text-right negative">-{{ $r['platform_fees'] }} €</td></tr>
                    <tr class="subtotal"><td>= Recettes nettes</td><td class="text-right">{{ $r['net_income'] }} €</td></tr>

                    <tr><td colspan="2" style="padding-top:12px;font-weight:600;">Charges déductibles</td></tr>
                    @foreach($r['expenses_by_category'] as $cat => $data)
                        @if($data['effective'] > 0)
                            <tr>
                                <td class="indent">{{ $data['label'] }}@if($data['shared'] > 0) <span style="color:#9ca3af;font-size:12px;">(QP)</span>@endif</td>
                                <td class="text-right negative">-{{ number_format($data['effective'] / 100, 0, ',', ' ') }} €</td>
                            </tr>
                        @endif
                    @endforeach
                    @if($r['loan_interest'] !== '0')
                        <tr><td class="indent">Intérêts d'emprunt <span style="color:#9ca3af;font-size:12px;">(QP)</span></td><td class="text-right negative">-{{ $r['loan_interest'] }} €</td></tr>
                    @endif
                    @if($r['loan_insurance'] !== '0')
                        <tr><td class="indent">Assurance emprunteur <span style="color:#9ca3af;font-size:12px;">(QP)</span></td><td class="text-right negative">-{{ $r['loan_insurance'] }} €</td></tr>
                    @endif
                    <tr class="subtotal"><td>= Total charges</td><td class="text-right negative">-{{ $r['total_expenses'] }} €</td></tr>

                    <tr style="background:#f8fafc;"><td style="font-weight:600;">= Bénéfice avant amortissements</td><td class="text-right" style="font-weight:600;">{{ $r['result_before_depreciation'] }} €</td></tr>

                    <tr><td colspan="2" style="padding-top:12px;font-weight:600;">Amortissements</td></tr>
                    <tr><td class="indent">Immeuble (composants)</td><td class="text-right negative">-{{ $r['depreciation_building'] }} €</td></tr>
                    <tr><td class="indent">Mobilier</td><td class="text-right negative">-{{ $r['depreciation_furniture'] }} €</td></tr>
                    <tr><td class="indent">Frais d'acquisition (notaire, agence)</td><td class="text-right negative">-{{ $r['depreciation_notary'] }} €</td></tr>
                    <tr class="subtotal"><td>= Total amortissements</td><td class="text-right negative">-{{ $r['total_depreciation'] }} €</td></tr>

                    @if($r['deferred_depreciation'] !== '0')
                        <tr><td class="indent" style="color:#f59e0b;">dont plafonné (reporté)</td><td class="text-right" style="color:#f59e0b;">{{ $r['deferred_depreciation'] }} €</td></tr>
                        <tr class="subtotal"><td>= Amortissement déduit</td><td class="text-right negative">-{{ $r['capped_depreciation'] }} €</td></tr>
                    @endif

                    <tr class="result"><td>= Résultat fiscal (base imposable)</td><td class="text-right">{{ $r['fiscal_result'] }} €</td></tr>
                </table>
            </div>

            {{-- Graphiques (Alpine.js pour survivre au re-rendu Livewire) --}}
            <div class="sim-grid sim-grid-2" x-data="simCharts({{ json_encode($r['chart_data']) }})" x-init="render()">
                <div class="sim-card">
                    <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;">Comparaison régimes</h3>
                    <div class="sim-chart-container">
                        <canvas x-ref="chartComparison"></canvas>
                    </div>
                </div>
                <div class="sim-card">
                    <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;">Répartition des déductions</h3>
                    <div class="sim-chart-container">
                        <canvas x-ref="chartDeductions"></canvas>
                    </div>
                </div>
            </div>

            {{-- Détail amortissements par bien --}}
            <div class="sim-card">
                <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;">Détail des amortissements par composant</h3>
                <table class="sim-table">
                    @foreach($r['depreciation_details'] as $propertyName => $dep)
                        <tr><th colspan="2">{{ $propertyName }}</th></tr>
                        @foreach($dep['details'] as $detail)
                            @if((int) $detail['amount'] > 0)
                                <tr><td class="indent">{{ $detail['name'] }}</td><td class="text-right">{{ number_format((int) $detail['amount'] / 100, 0, ',', ' ') }} €</td></tr>
                            @endif
                        @endforeach
                        <tr class="total"><td>Total</td><td class="text-right">{{ number_format((int) $dep['total'] / 100, 0, ',', ' ') }} €</td></tr>
                    @endforeach
                </table>
            </div>

            <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
            <script>
                document.addEventListener('alpine:init', () => {
                    Alpine.data('simCharts', (data) => ({
                        charts: [],
                        render() {
                            this.$nextTick(() => {
                                this.charts.forEach(c => c.destroy());
                                this.charts = [];
                                const fmt = v => new Intl.NumberFormat('fr-FR', {style:'currency',currency:'EUR',maximumFractionDigits:0}).format(v/100);

                                this.charts.push(new Chart(this.$refs.chartComparison, {
                                    type: 'bar',
                                    data: {
                                        labels: ['Micro-BIC', 'Régime réel'],
                                        datasets: [{
                                            label: 'Base imposable',
                                            data: [data.micro_bic / 100, data.real / 100],
                                            backgroundColor: ['#fbbf24', '#34d399'],
                                            borderRadius: 8,
                                            barPercentage: 0.6,
                                        }]
                                    },
                                    options: {
                                        responsive: true, maintainAspectRatio: false,
                                        plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => fmt(ctx.raw * 100) } } },
                                        scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString('fr-FR') + ' €' } } }
                                    }
                                }));

                                const deductions = [
                                    { label: 'Charges dédiées', value: data.expenses_dedicated, color: '#f87171' },
                                    { label: 'Charges mixtes (QP)', value: data.expenses_shared, color: '#fb923c' },
                                    { label: 'Intérêts emprunt', value: data.loan_interest, color: '#fbbf24' },
                                    { label: 'Assurance emprunt', value: data.loan_insurance, color: '#facc15' },
                                    { label: 'Amort. immeuble', value: data.dep_building, color: '#34d399' },
                                    { label: 'Amort. mobilier', value: data.dep_furniture, color: '#22d3ee' },
                                    { label: 'Amort. notaire/agence', value: data.dep_notary, color: '#818cf8' },
                                ].filter(d => d.value > 0);

                                this.charts.push(new Chart(this.$refs.chartDeductions, {
                                    type: 'doughnut',
                                    data: {
                                        labels: deductions.map(d => d.label),
                                        datasets: [{ data: deductions.map(d => d.value / 100), backgroundColor: deductions.map(d => d.color), borderWidth: 2, borderColor: '#fff' }]
                                    },
                                    options: {
                                        responsive: true, maintainAspectRatio: false,
                                        plugins: {
                                            legend: { position: 'right', labels: { font: { size: 12 }, padding: 8, usePointStyle: true } },
                                            tooltip: { callbacks: { label: ctx => ctx.label + ': ' + fmt(ctx.raw * 100) } }
                                        }
                                    }
                                }));
                            });
                        }
                    }));
                });
            </script>
        @endif
    </div>
</x-filament-panels::page>
