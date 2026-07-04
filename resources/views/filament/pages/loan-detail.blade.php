<x-filament-panels::page>
    <style>
        .ld-card { background: var(--fi-body-bg, white); border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border: 1px solid var(--fi-border-color, #e5e7eb); margin-bottom: 16px; }
        .ld-grid { display: grid; gap: 12px; }
        .ld-grid-4 { grid-template-columns: repeat(4, 1fr); }
        .ld-grid-3 { grid-template-columns: repeat(3, 1fr); }
        .ld-grid-2 { grid-template-columns: repeat(2, 1fr); }
        .ld-stat { text-align: center; }
        .ld-stat-value { font-size: 22px; font-weight: 700; }
        .ld-stat-label { font-size: 11px; color: var(--fi-fg-muted, #6b7280); margin-top: 4px; }
        .ld-stat-green .ld-stat-value { color: #065f46; }
        .ld-stat-amber .ld-stat-value { color: #92400e; }
        .ld-stat-blue .ld-stat-value { color: #1e40af; }
        .ld-bar { width: 100%; background: #e5e7eb; border-radius: 8px; height: 16px; margin: 8px 0; }
        .ld-bar-fill { height: 16px; border-radius: 8px; background: #10b981; transition: width 0.5s; }
        .ld-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .ld-table th, .ld-table td { padding: 6px 8px; border-bottom: 1px solid var(--fi-border-color, #e5e7eb); }
        .ld-table th { background: var(--fi-bg-muted, #f9fafb); text-align: center; font-weight: 600; font-size: 11px; position: sticky; top: 0; }
        .ld-table .r { text-align: right; font-family: monospace; }
        .ld-table .c { text-align: center; }
        .ld-table .past { color: var(--fi-fg-muted, #9ca3af); }
        .ld-table .current { background: #ecfdf5; font-weight: 600; }
        .ld-table .future { }
        .ld-scroll { max-height: 500px; overflow-y: auto; overflow-x: auto; }
        .ld-select { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
        .ld-pagination { display: flex; justify-content: center; gap: 8px; margin-top: 12px; }
        .ld-pagination button { padding: 6px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: var(--fi-body-bg, white); cursor: pointer; font-size: 12px; color: var(--fi-fg, #374151); }
        .ld-pagination button:hover { background: #f3f4f6; }
        .ld-pagination button:disabled { opacity: 0.3; cursor: not-allowed; }
        .ld-pagination span { padding: 6px 10px; font-size: 12px; color: var(--fi-fg-muted, #6b7280); }
        .ld-tabs { display: flex; gap: 0; margin-bottom: 20px; background: var(--fi-bg-muted, #f3f4f6); border-radius: 10px; padding: 4px; width: fit-content; }
        .ld-tab { padding: 8px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; border: none; background: transparent; color: var(--fi-fg-muted, #6b7280); transition: all 0.2s; }
        .ld-tab:hover { color: var(--fi-fg, #374151); }
        .ld-tab-active { background: var(--fi-body-bg, white); color: #10b981; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        @media (max-width: 768px) { .ld-grid-4 { grid-template-columns: repeat(2, 1fr); } .ld-grid-3, .ld-grid-2 { grid-template-columns: 1fr; } .ld-tabs { width: 100%; } .ld-tab { flex: 1; text-align: center; padding: 8px 12px; font-size: 12px; } }
    </style>

    @php
        $data = $this->loanData;
        $fmt = fn($v) => number_format($v / 100, 2, ',', ' ');
        $fmtInt = fn($v) => number_format($v / 100, 0, ',', ' ');
    @endphp

    @if(!$data)
        <div class="ld-card" style="text-align:center;padding:48px;">
            <p style="font-size:18px;color:#6b7280;">Aucun emprunt trouvé. Ajoutez-en un dans Comptabilité → Emprunts.</p>
        </div>
    @else
        @php $loan = $data['loan']; @endphp

        {{-- En-tête --}}
        <div class="ld-card">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                <div>
                    <h3 style="font-size:18px;font-weight:700;">{{ $loan->bank_name ?? 'Emprunt' }}</h3>
                    <p style="font-size:13px;color:#6b7280;">{{ $data['property']?->name ?? 'Bien non rattaché' }} · Taux {{ $loan->annual_rate }}% · {{ $loan->duration_months / 12 }} ans</p>
                </div>
                @if(\App\Models\Loan::count() > 1)
                    <select wire:model.live="loanId" class="ld-select">
                        @foreach(\App\Models\Loan::all() as $l)
                            <option value="{{ $l->id }}">{{ $l->bank_name ?? 'Emprunt' }} — {{ $fmtInt($l->amount) }} €</option>
                        @endforeach
                    </select>
                @endif
            </div>
        </div>

        {{-- KPIs --}}
        <div class="ld-grid ld-grid-4">
            <div class="ld-card ld-stat ld-stat-blue">
                <div class="ld-stat-value">{{ $fmtInt($loan->amount) }} €</div>
                <div class="ld-stat-label">Montant emprunté</div>
            </div>
            <div class="ld-card ld-stat ld-stat-green">
                <div class="ld-stat-value">{{ $fmtInt($data['paid_capital']) }} €</div>
                <div class="ld-stat-label">Capital remboursé</div>
            </div>
            <div class="ld-card ld-stat ld-stat-amber">
                <div class="ld-stat-value">{{ $fmtInt($data['remaining_capital']) }} €</div>
                <div class="ld-stat-label">Capital restant dû</div>
            </div>
            <div class="ld-card ld-stat">
                <div class="ld-stat-value">{{ $fmt($loan->monthly_payment) }} €</div>
                <div class="ld-stat-label">Mensualité</div>
            </div>
        </div>

        {{-- Barre de progression --}}
        <div class="ld-card">
            <div style="display:flex;justify-content:space-between;font-size:13px;">
                <span><strong>{{ $data['progress_pct'] }}%</strong> remboursé</span>
                <span>{{ $data['paid_months'] }} / {{ $data['total_months'] }} mois</span>
            </div>
            <div class="ld-bar"><div class="ld-bar-fill" style="width:{{ $data['progress_pct'] }}%;"></div></div>
        </div>

        {{-- Switch 3 onglets --}}
        <div x-data="{ tab: 'data' }">
            <div class="ld-tabs">
                <button class="ld-tab" :class="tab === 'data' && 'ld-tab-active'" @click="tab = 'data'">📊 Chiffres</button>
                <button class="ld-tab" :class="tab === 'charts' && 'ld-tab-active'" @click="tab = 'charts'">📈 Graphiques</button>
                <button class="ld-tab" :class="tab === 'tables' && 'ld-tab-active'" @click="tab = 'tables'">📋 Tableaux</button>
            </div>

        {{-- === ONGLET CHIFFRES === --}}
        <div x-show="tab === 'data'">
            {{-- Coût total --}}
            <div class="ld-grid ld-grid-3">
                <div class="ld-card ld-stat">
                    <div class="ld-stat-value">{{ $fmtInt($data['total_interest']) }} €</div>
                    <div class="ld-stat-label">Total intérêts</div>
                </div>
                <div class="ld-card ld-stat">
                    <div class="ld-stat-value">{{ $fmtInt($data['paid_interest']) }} €</div>
                    <div class="ld-stat-label">Intérêts déjà payés</div>
                </div>
                <div class="ld-card ld-stat">
                    <div class="ld-stat-value">{{ $fmtInt($data['remaining_interest']) }} €</div>
                    <div class="ld-stat-label">Intérêts restants</div>
                </div>
            </div>

            <div class="ld-grid ld-grid-3" style="margin-top:12px;">
                <div class="ld-card ld-stat">
                    <div class="ld-stat-value">{{ $fmtInt($data['total_insurance']) }} €</div>
                    <div class="ld-stat-label">Total assurance</div>
                </div>
                <div class="ld-card ld-stat">
                    <div class="ld-stat-value">{{ $fmtInt($data['total_interest'] + $data['total_insurance']) }} €</div>
                    <div class="ld-stat-label">Coût total du crédit</div>
                </div>
                <div class="ld-card ld-stat">
                    <div class="ld-stat-value">{{ $fmtInt($loan->amount + $data['total_interest'] + $data['total_insurance']) }} €</div>
                    <div class="ld-stat-label">Montant total remboursé</div>
                </div>
            </div>

        </div> {{-- fin onglet chiffres --}}

        {{-- === ONGLET GRAPHIQUES (utilise visibility pour que Chart.js rende) === --}}
        <div :style="tab === 'charts' ? '' : 'height:0;overflow:hidden;position:absolute;opacity:0;pointer-events:none;'">

        {{-- Graphiques --}}
        @php
            $capitalByYear = [];
            $capitalPaidByYear = [];
            $interestByYearChart = [];
            $interestCumulByYear = [];
            $insuranceByYear = [];
            $cumulInterest = 0;
            $currentYearStr = date('Y');
            $todayIndex = null;

            foreach ($data['payments']->groupBy(fn($p) => $p->payment_date->format('Y')) as $y => $yPayments) {
                $last = $yPayments->last();
                $capitalByYear[$y] = round($last->remaining_capital / 100);
                $capitalPaidByYear[$y] = round(($loan->amount - $last->remaining_capital) / 100);
                $yearInterest = $yPayments->sum('interest_amount');
                $interestByYearChart[$y] = round($yearInterest / 100);
                $cumulInterest += $yearInterest;
                $interestCumulByYear[$y] = round($cumulInterest / 100);
                $insuranceByYear[$y] = round($yPayments->sum('insurance_amount') / 100);
                if ($y === $currentYearStr) $todayIndex = array_search($y, array_keys($capitalByYear));
            }
            $years = array_keys($capitalByYear);
        @endphp

        <div class="ld-grid ld-grid-2">
            {{-- 1. Évolution Capital et Intérêts (comme creditImmo) --}}
            <div class="ld-card">
                <h3 style="font-size:14px;font-weight:600;margin-bottom:12px;text-align:center;">Évolution du Capital et des Intérêts</h3>
                <canvas id="evolutionChart" style="max-height:250px;"></canvas>
            </div>

            {{-- 2. Répartition paiements mensuels (barres empilées) --}}
            <div class="ld-card">
                <h3 style="font-size:14px;font-weight:600;margin-bottom:12px;text-align:center;">Répartition des Paiements Annuels</h3>
                <canvas id="stackedBarChart" style="max-height:250px;"></canvas>
            </div>

            {{-- 3. Intérêts cumulés --}}
            <div class="ld-card">
                <h3 style="font-size:14px;font-weight:600;margin-bottom:12px;text-align:center;">Intérêts Cumulés</h3>
                <canvas id="cumulInterestChart" style="max-height:250px;"></canvas>
            </div>

            {{-- 4. Répartition coût total (doughnut) --}}
            <div class="ld-card">
                <h3 style="font-size:14px;font-weight:600;margin-bottom:12px;text-align:center;">Répartition Coût Total</h3>
                <canvas id="doughnutChart" style="max-height:250px;"></canvas>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3/dist/chartjs-plugin-annotation.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const years = {!! json_encode($years) !!};
                const fmtEur = (v) => v.toLocaleString('fr-FR') + ' €';
                const todayIdx = {{ $todayIndex ?? 'null' }};
                const todayAnnotation = todayIdx !== null ? {
                    annotations: {
                        todayLine: {
                            type: 'line', xMin: todayIdx, xMax: todayIdx,
                            borderColor: '#ef4444', borderWidth: 2, borderDash: [5, 3],
                            label: { display: true, content: "Aujourd'hui", position: 'start', font: { size: 10 }, backgroundColor: '#ef4444', color: '#fff' }
                        }
                    }
                } : {};

                // 1. Évolution Capital (croisement solde restant / capital remboursé)
                new Chart(document.getElementById('evolutionChart'), {
                    type: 'line',
                    data: {
                        labels: years,
                        datasets: [{
                            label: 'Solde restant',
                            data: {!! json_encode(array_values($capitalByYear)) !!},
                            borderColor: '#ef4444', backgroundColor: 'rgba(239,68,68,0.08)', fill: true, tension: 0.3,
                        }, {
                            label: 'Capital remboursé',
                            data: {!! json_encode(array_values($capitalPaidByYear)) !!},
                            borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.08)', fill: true, tension: 0.3,
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { position: 'bottom', labels: { font: { size: 10 } } }, tooltip: { callbacks: { label: (c) => c.dataset.label + ' : ' + fmtEur(c.raw) } }, annotation: todayAnnotation },
                        scales: { y: { ticks: { callback: (v) => Math.round(v/1000) + 'k €', font: { size: 10 } } }, x: { ticks: { font: { size: 9 }, maxRotation: 45 } } }
                    }
                });

                // 2. Barres empilées : capital vs intérêts vs assurance par année
                new Chart(document.getElementById('stackedBarChart'), {
                    type: 'bar',
                    data: {
                        labels: years,
                        datasets: [{
                            label: 'Capital', data: {!! json_encode(array_values($interestByYearChart)) !!}.map((v, i) => {!! json_encode(array_values($capitalPaidByYear)) !!}[i] - ({!! json_encode(array_values($capitalPaidByYear)) !!}[i-1] || 0)),
                            backgroundColor: '#10b981',
                        }, {
                            label: 'Intérêts', data: {!! json_encode(array_values($interestByYearChart)) !!},
                            backgroundColor: '#f59e0b',
                        }, {
                            label: 'Assurance', data: {!! json_encode(array_values($insuranceByYear)) !!},
                            backgroundColor: '#6366f1',
                        }]
                    },
                    options: {
                        responsive: true, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 } } }, tooltip: { callbacks: { label: (c) => c.dataset.label + ' : ' + fmtEur(c.raw) } }, annotation: todayAnnotation },
                        scales: { x: { stacked: true, ticks: { font: { size: 9 }, maxRotation: 45 } }, y: { stacked: true, ticks: { callback: (v) => Math.round(v/1000) + 'k €', font: { size: 10 } } } }
                    }
                });

                // 3. Intérêts cumulés
                new Chart(document.getElementById('cumulInterestChart'), {
                    type: 'line',
                    data: {
                        labels: years,
                        datasets: [{
                            label: 'Intérêts cumulés',
                            data: {!! json_encode(array_values($interestCumulByYear)) !!},
                            borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.1)', fill: true, tension: 0.3, pointRadius: 0,
                        }]
                    },
                    options: {
                        responsive: true, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 } } }, tooltip: { callbacks: { label: (c) => fmtEur(c.raw) } }, annotation: todayAnnotation },
                        scales: { y: { ticks: { callback: (v) => Math.round(v/1000) + 'k €', font: { size: 10 } } }, x: { ticks: { font: { size: 9 }, maxRotation: 45 } } }
                    }
                });

                // 4. Doughnut
                new Chart(document.getElementById('doughnutChart'), {
                    type: 'doughnut',
                    data: {
                        labels: ['Capital', 'Intérêts', 'Assurance'],
                        datasets: [{ data: [{{ $loan->amount }}, {{ $data['total_interest'] }}, {{ $data['total_insurance'] }}], backgroundColor: ['#10b981', '#f59e0b', '#6366f1'], borderWidth: 0 }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { position: 'bottom', labels: { font: { size: 10 } } }, tooltip: { callbacks: { label: (c) => c.label + ' : ' + fmtEur(c.raw / 100) } } }
                    }
                });
            });
        </script>
        </div> {{-- fin onglet graphiques --}}

        {{-- === ONGLET TABLEAUX === --}}
        <div x-show="tab === 'tables'" x-cloak>

        {{-- Prochaines échéances --}}
        @if($data['next_payments']->count() > 0)
            <div class="ld-card">
                <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;">Prochaines échéances</h3>
                <div style="overflow-x:auto;">
                <table class="ld-table">
                    <tr><th>Date</th><th>Capital</th><th>Intérêts</th><th>Assurance</th><th>Mensualité</th><th>Restant dû</th></tr>
                    @foreach($data['next_payments'] as $p)
                        <tr>
                            <td class="c">{{ $p->payment_date->format('m/Y') }}</td>
                            <td class="r">{{ $fmt($p->capital_amount) }} €</td>
                            <td class="r">{{ $fmt($p->interest_amount) }} €</td>
                            <td class="r">{{ $fmt($p->insurance_amount) }} €</td>
                            <td class="r">{{ $fmt($p->capital_amount + $p->interest_amount + $p->insurance_amount) }} €</td>
                            <td class="r">{{ $fmtInt($p->remaining_capital) }} €</td>
                        </tr>
                    @endforeach
                </table>
                </div>
            </div>
        @endif

        {{-- Intérêts déductibles par année --}}
        <div class="ld-card">
            <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;">Intérêts déductibles par année (quote-part {{ number_format((float) $data['quota_share'] * 100, 1) }}%)</h3>
            <div style="overflow-x:auto;">
                <table class="ld-table">
                    <tr><th>Année</th><th>Intérêts</th><th>Assurance</th><th>Déductible (quote-part)</th></tr>
                    @foreach($data['interest_by_year'] as $year => $yData)
                        <tr class="{{ $year == date('Y') ? 'current' : ($year < date('Y') ? 'past' : '') }}">
                            <td class="c">{{ $year }}</td>
                            <td class="r">{{ $fmt($yData['interest']) }} €</td>
                            <td class="r">{{ $fmt($yData['insurance']) }} €</td>
                            <td class="r" style="font-weight:600;color:#065f46;">{{ $fmt($yData['deductible']) }} €</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>

        {{-- Tableau d'amortissement complet avec pagination --}}
        <div class="ld-card" x-data="{ page: 1, perPage: 12, total: {{ $data['total_months'] }} }">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <h3 style="font-size:16px;font-weight:600;">Tableau d'amortissement ({{ $data['total_months'] }} mois)</h3>
                <select x-model="perPage" @change="page=1" style="padding:4px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;">
                    <option value="12">12/page</option>
                    <option value="24">24/page</option>
                    <option value="60">60/page</option>
                    <option value="{{ $data['total_months'] }}">Tout</option>
                </select>
            </div>
            <div style="overflow-x:auto;">
                <table class="ld-table">
                    <thead>
                        <tr><th>N°</th><th>Date</th><th>Capital</th><th>Intérêts</th><th>Assurance</th><th>Mensualité</th><th>Restant dû</th></tr>
                    </thead>
                    <tbody>
                        @foreach($data['payments'] as $idx => $p)
                            @php
                                $isPast = $p->payment_date->format('Y-m-d') < now()->format('Y-m-d');
                                $isCurrent = $p->payment_date->format('Y-m') === now()->format('Y-m');
                            @endphp
                            <tr class="{{ $isCurrent ? 'current' : ($isPast ? 'past' : '') }}"
                                x-show="{{ $idx }} >= (page-1)*perPage && {{ $idx }} < page*perPage">
                                <td class="c">{{ $p->month_number }}</td>
                                <td class="c">{{ $p->payment_date->format('m/Y') }}</td>
                                <td class="r">{{ $fmt($p->capital_amount) }} €</td>
                                <td class="r">{{ $fmt($p->interest_amount) }} €</td>
                                <td class="r">{{ $fmt($p->insurance_amount) }} €</td>
                                <td class="r">{{ $fmt($p->capital_amount + $p->interest_amount + $p->insurance_amount) }} €</td>
                                <td class="r">{{ $fmtInt($p->remaining_capital) }} €</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="ld-pagination">
                <button @click="page = Math.max(1, page-1)" :disabled="page <= 1">← Précédent</button>
                <span x-text="'Page ' + page + ' / ' + Math.ceil(total/perPage)"></span>
                <button @click="page = Math.min(Math.ceil(total/perPage), page+1)" :disabled="page >= Math.ceil(total/perPage)">Suivant →</button>
            </div>
        </div>
        </div> {{-- fin onglet tableaux --}}
        </div> {{-- fin x-data tabs --}}
    @endif
</x-filament-panels::page>
