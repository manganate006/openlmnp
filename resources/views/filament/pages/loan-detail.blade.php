<x-filament-panels::page>
    <style>
        .ld-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border: 1px solid #e5e7eb; margin-bottom: 16px; }
        .ld-grid { display: grid; gap: 12px; }
        .ld-grid-4 { grid-template-columns: repeat(4, 1fr); }
        .ld-grid-3 { grid-template-columns: repeat(3, 1fr); }
        .ld-grid-2 { grid-template-columns: repeat(2, 1fr); }
        .ld-stat { text-align: center; }
        .ld-stat-value { font-size: 22px; font-weight: 700; }
        .ld-stat-label { font-size: 11px; color: #6b7280; margin-top: 4px; }
        .ld-stat-green .ld-stat-value { color: #065f46; }
        .ld-stat-amber .ld-stat-value { color: #92400e; }
        .ld-stat-blue .ld-stat-value { color: #1e40af; }
        .ld-bar { width: 100%; background: #e5e7eb; border-radius: 8px; height: 16px; margin: 8px 0; }
        .ld-bar-fill { height: 16px; border-radius: 8px; background: #10b981; transition: width 0.5s; }
        .ld-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .ld-table th, .ld-table td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
        .ld-table th { background: #f9fafb; text-align: center; font-weight: 600; font-size: 11px; position: sticky; top: 0; }
        .ld-table .r { text-align: right; font-family: monospace; }
        .ld-table .c { text-align: center; }
        .ld-table .past { color: #9ca3af; }
        .ld-table .current { background: #ecfdf5; font-weight: 600; }
        .ld-table .future { }
        .ld-scroll { max-height: 500px; overflow-y: auto; overflow-x: auto; }
        .ld-select { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
        @media (max-width: 768px) { .ld-grid-4 { grid-template-columns: repeat(2, 1fr); } .ld-grid-3 { grid-template-columns: repeat(1, 1fr); } }
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
                    <p style="font-size:13px;color:#6b7280;">{{ $data['property']->name }} · Taux {{ $loan->annual_rate }}% · {{ $loan->duration_months / 12 }} ans</p>
                </div>
                @if(\App\Models\Loan::whereHas('property', fn ($q) => $q->where('user_id', auth()->id()))->count() > 1)
                    <select wire:model.live="loanId" class="ld-select">
                        @foreach(\App\Models\Loan::whereHas('property', fn ($q) => $q->where('user_id', auth()->id()))->get() as $l)
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

        {{-- Prochaines échéances --}}
        @if($data['next_payments']->count() > 0)
            <div class="ld-card">
                <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;">Prochaines échéances</h3>
                <table class="ld-table">
                    <tr><th>Date</th><th>Capital</th><th>Intérêts</th><th>Mensualité</th><th>Restant dû</th></tr>
                    @foreach($data['next_payments'] as $p)
                        <tr>
                            <td class="c">{{ $p->payment_date->format('m/Y') }}</td>
                            <td class="r">{{ $fmt($p->capital_amount) }} €</td>
                            <td class="r">{{ $fmt($p->interest_amount) }} €</td>
                            <td class="r">{{ $fmt($p->capital_amount + $p->interest_amount) }} €</td>
                            <td class="r">{{ $fmtInt($p->remaining_capital) }} €</td>
                        </tr>
                    @endforeach
                </table>
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

        {{-- Tableau d'amortissement complet --}}
        <div class="ld-card">
            <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;">Tableau d'amortissement complet ({{ $data['total_months'] }} mois)</h3>
            <div class="ld-scroll">
                <table class="ld-table">
                    <thead>
                        <tr><th>N°</th><th>Date</th><th>Capital</th><th>Intérêts</th><th>Mensualité</th><th>Capital restant</th></tr>
                    </thead>
                    <tbody>
                        @foreach($data['payments'] as $p)
                            @php
                                $isPast = $p->payment_date->format('Y-m-d') < now()->format('Y-m-d');
                                $isCurrent = $p->payment_date->format('Y-m') === now()->format('Y-m');
                            @endphp
                            <tr class="{{ $isCurrent ? 'current' : ($isPast ? 'past' : '') }}">
                                <td class="c">{{ $p->month_number }}</td>
                                <td class="c">{{ $p->payment_date->format('m/Y') }}</td>
                                <td class="r">{{ $fmt($p->capital_amount) }} €</td>
                                <td class="r">{{ $fmt($p->interest_amount) }} €</td>
                                <td class="r">{{ $fmt($p->capital_amount + $p->interest_amount) }} €</td>
                                <td class="r">{{ $fmtInt($p->remaining_capital) }} €</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>
