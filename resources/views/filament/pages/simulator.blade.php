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
        .sim-verdict { padding: 16px 20px; border-radius: 12px; display: flex; align-items: center; gap: 12px; margin: 16px 0; }
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
        .sim-summary { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 15px; padding: 16px 20px; background: var(--fi-bg-muted, #f9fafb); border-radius: 10px; margin-bottom: 16px; }
        .sim-summary span { font-weight: 700; font-size: 17px; }
        .sim-summary .op { color: #9ca3af; font-weight: 400; }
        .sim-details-toggle { cursor: pointer; color: #2563eb; font-size: 14px; font-weight: 500; border: none; background: none; padding: 0; }
        .sim-details-toggle:hover { text-decoration: underline; }
        @media (max-width: 768px) { .sim-grid-2, .sim-grid-3 { grid-template-columns: 1fr; } .sim-summary { font-size: 13px; } .sim-summary span { font-size: 15px; } }
    </style>

    {{-- Persist selections in localStorage --}}
    <div x-data="{
        init() {
            const savedYear = localStorage.getItem('sim_year');
            const savedAbatement = localStorage.getItem('sim_abatement');
            if (savedYear) $wire.set('year', parseInt(savedYear));
            if (savedAbatement) $wire.set('abatement', savedAbatement);
        }
    }"></div>

    <div>
        {{-- Filtres --}}
        <div class="sim-grid sim-grid-2" style="margin-bottom: 24px;">
            <div>
                <div class="sim-label">Année</div>
                <select wire:model.live="year" class="sim-select" x-on:change="localStorage.setItem('sim_year', $event.target.value)">
                    @for($y = date('Y') + 1; $y >= date('Y') - 5; $y--)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endfor
                </select>
            </div>
            <div>
                <div class="sim-label">Abattement micro-BIC</div>
                <select wire:model.live="abatement" class="sim-select" x-on:change="localStorage.setItem('sim_abatement', $event.target.value)">
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
            {{-- Résumé rapide du calcul --}}
            <div class="sim-summary">
                <span class="positive">{{ $r['net_income'] }} €</span>
                <span class="op">recettes</span>
                <span class="op">−</span>
                <span class="negative">{{ $r['total_expenses'] }} €</span>
                <span class="op">charges</span>
                <span class="op">−</span>
                <span class="negative">{{ $r['capped_depreciation'] }} €</span>
                <span class="op">amortissements</span>
                <span class="op">=</span>
                <span style="font-size:20px;">{{ $r['fiscal_result'] }} €</span>
                <span class="op">imposable</span>
            </div>

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
                    <svg xmlns="http://www.w3.org/2000/svg" style="width:28px;height:28px;color:#10b981;flex-shrink:0;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    <div>
                        <div style="font-size:16px;font-weight:700;color:#065f46;">Le régime réel est plus avantageux de {{ $r['advantage'] }} €</div>
                        <div style="font-size:13px;color:#047857;margin-top:2px;">
                            Économie d'impôt : {{ $r['tax_saving_11'] }} € (TMI 11%) à {{ $r['tax_saving_30'] }} € (TMI 30%) + {{ $r['ps_saving'] }} € de PS
                        </div>
                    </div>
                </div>
            @else
                <div class="sim-verdict sim-verdict-amber">
                    <svg xmlns="http://www.w3.org/2000/svg" style="width:28px;height:28px;color:#f59e0b;flex-shrink:0;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg>
                    <div>
                        <div style="font-size:16px;font-weight:700;color:#92400e;">Le micro-BIC est plus avantageux</div>
                        <div style="font-size:13px;color:#b45309;">Différence : {{ $r['advantage'] }} € en faveur du micro-BIC</div>
                    </div>
                </div>
            @endif

            <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
            {{-- Graphiques (wire:ignore empêche Livewire de détruire les canvas) --}}
            <div wire:ignore>
                {{-- Waterfall --}}
                <div class="sim-card">
                    <h3 style="font-size:15px;font-weight:600;margin-bottom:8px;">Cascade du résultat fiscal</h3>
                    <div style="height:280px;"><canvas id="simChartWaterfall"></canvas></div>
                </div>
                <div class="sim-grid sim-grid-2">
                    {{-- Barres horizontales comparaison --}}
                    <div class="sim-card">
                        <h3 style="font-size:15px;font-weight:600;margin-bottom:8px;">Base imposable</h3>
                        <div style="height:120px;"><canvas id="simChartCompare"></canvas></div>
                    </div>
                    {{-- Doughnut --}}
                    <div class="sim-card">
                        <h3 style="font-size:15px;font-weight:600;margin-bottom:8px;">Répartition des déductions</h3>
                        <div style="height:220px;"><canvas id="simChartDoughnut"></canvas></div>
                    </div>
                </div>
            </div>
            <script>
                (function() {
                    if (window._simCharts) window._simCharts.forEach(c => c.destroy());
                    window._simCharts = [];
                    let d = @json($r['chart_data']);
                    const eur = v => v / 100;
                    const fmt = v => new Intl.NumberFormat('fr-FR', {maximumFractionDigits:0}).format(v) + ' €';

                    // Écouter les mises à jour Livewire (changement année/abattement)
                    document.addEventListener('livewire:initialized', () => {
                        Livewire.on('sim-chart-update', (event) => {
                            d = event.data || event[0]?.data || event;
                            render();
                        });
                    });

                    function render() {
                        if (window._simCharts.length) window._simCharts.forEach(ch => ch.destroy());
                        window._simCharts = [];

                        // 1. Waterfall (floating bars)
                        const netIncome = eur(d.net_income);
                        const expenses = eur(d.total_expenses);
                        const depreciation = eur(d.total_depreciation);
                        const result = eur(d.fiscal_result);

                        const waterfallData = [
                            [0, netIncome],                                      // Recettes: 0 → 14310
                            [netIncome - expenses, netIncome],                   // Charges: tombe
                            [netIncome - expenses - depreciation, netIncome - expenses], // Amort: tombe
                            [0, result],                                          // Résultat: 0 → 1345
                        ];
                        const waterfallColors = ['#34d399', '#f87171', '#fb923c', '#3b82f6'];
                        const waterfallLabels = [
                            'Recettes\n' + fmt(netIncome),
                            'Charges\n-' + fmt(expenses),
                            'Amort.\n-' + fmt(depreciation),
                            'Résultat\n' + fmt(result)
                        ];

                        const wfEl = document.getElementById('simChartWaterfall');
                        if (wfEl) window._simCharts.push(new Chart(wfEl, {
                            type: 'bar',
                            data: {
                                labels: ['Recettes', 'Charges', 'Amort.', 'Résultat'],
                                datasets: [{
                                    data: waterfallData,
                                    backgroundColor: waterfallColors,
                                    borderRadius: 6,
                                    barPercentage: 0.6,
                                }]
                            },
                            options: {
                                responsive: true, maintainAspectRatio: false,
                                animation: { duration: 600 },
                                plugins: {
                                    legend: { display: false },
                                    tooltip: { callbacks: {
                                        label: ctx => { const r = ctx.raw; return fmt(Math.abs(r[1] - r[0])); }
                                    }}
                                },
                                scales: {
                                    y: { beginAtZero: true, ticks: { callback: v => fmt(v) } },
                                    x: { grid: { display: false } }
                                }
                            }
                        }));

                        // 2. Barres horizontales comparaison
                        const cmpEl = document.getElementById('simChartCompare');
                        if (cmpEl) window._simCharts.push(new Chart(cmpEl, {
                            type: 'bar',
                            data: {
                                labels: ['Micro-BIC', 'Régime réel'],
                                datasets: [{
                                    data: [eur(d.micro_bic), eur(d.real)],
                                    backgroundColor: ['#fbbf24', '#34d399'],
                                    borderRadius: 6,
                                    barPercentage: 0.5,
                                }]
                            },
                            options: {
                                indexAxis: 'y',
                                responsive: true, maintainAspectRatio: false,
                                animation: { duration: 600 },
                                plugins: {
                                    legend: { display: false },
                                    tooltip: { callbacks: { label: ctx => 'Base imposable : ' + fmt(ctx.raw) } }
                                },
                                scales: {
                                    x: { beginAtZero: true, ticks: { callback: v => fmt(v) } },
                                    y: { grid: { display: false } }
                                }
                            }
                        }));

                        // 3. Doughnut (compact, légende en bas)
                        const dd = [
                            {l:'Charges',v:d.expenses_dedicated+d.expenses_shared,cl:'#f87171'},
                            {l:'Emprunt',v:d.loan_interest+d.loan_insurance,cl:'#fbbf24'},
                            {l:'Amort. immeuble',v:d.dep_building,cl:'#34d399'},
                            {l:'Amort. mobilier',v:d.dep_furniture,cl:'#22d3ee'},
                            {l:'Amort. frais acq.',v:d.dep_notary,cl:'#818cf8'},
                        ].filter(x => x.v > 0);

                        const dnEl = document.getElementById('simChartDoughnut');
                        if (dnEl) window._simCharts.push(new Chart(dnEl, {
                            type: 'doughnut',
                            data: {
                                labels: dd.map(x => x.l),
                                datasets: [{ data: dd.map(x => eur(x.v)), backgroundColor: dd.map(x => x.cl), borderWidth: 2, borderColor: '#fff' }]
                            },
                            options: {
                                responsive: true, maintainAspectRatio: false,
                                animation: { duration: 600 },
                                cutout: '55%',
                                plugins: {
                                    legend: { position: 'bottom', labels: { font: {size: 12}, padding: 12, usePointStyle: true, pointStyleWidth: 10 } },
                                    tooltip: { callbacks: { label: ctx => ctx.label + ' : ' + fmt(ctx.raw) } }
                                }
                            }
                        }));
                    }

                    if (typeof Chart !== 'undefined') render();
                    else document.addEventListener('DOMContentLoaded', render);
                })();
            </script>

            {{-- Détail du calcul (accordéon) --}}
            <div class="sim-card" x-data="{ open: false }">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <h3 style="font-size:16px;font-weight:700;margin:0;">Détail du calcul régime réel</h3>
                    <button class="sim-details-toggle" x-on:click="open = !open" x-text="open ? 'Masquer ▲' : 'Afficher ▼'"></button>
                </div>
                <div x-show="open" x-collapse style="margin-top:16px;">
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
            </div>

            {{-- Détail amortissements par bien (accordéon) --}}
            <div class="sim-card" x-data="{ open: false }">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <h3 style="font-size:16px;font-weight:700;margin:0;">Amortissements par composant</h3>
                    <button class="sim-details-toggle" x-on:click="open = !open" x-text="open ? 'Masquer ▲' : 'Afficher ▼'"></button>
                </div>
                <div x-show="open" x-collapse style="margin-top:16px;">
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
            </div>
        @endif
    </div>

</x-filament-panels::page>
