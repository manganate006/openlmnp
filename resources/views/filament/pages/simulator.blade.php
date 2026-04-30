<x-filament-panels::page>
    <style>
        .sim-card { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e5e7eb; margin-bottom: 16px; }
        .sim-grid { display: grid; gap: 16px; }
        .sim-grid-2 { grid-template-columns: repeat(2, 1fr); }
        .sim-grid-3 { grid-template-columns: repeat(3, 1fr); }
        .sim-label { font-size: 14px; color: #6b7280; margin-bottom: 4px; }
        .sim-value { font-size: 24px; font-weight: 700; }
        .sim-sub { font-size: 12px; margin-top: 4px; }
        .sim-card-amber { background: #fffbeb; border-color: #fbbf24; }
        .sim-card-green { background: #ecfdf5; border-color: #34d399; }
        .sim-card-red { background: #fef2f2; border-color: #f87171; }
        .sim-verdict { padding: 20px; border-radius: 12px; display: flex; align-items: center; gap: 12px; margin: 16px 0; }
        .sim-verdict-green { background: #d1fae5; border: 2px solid #10b981; }
        .sim-verdict-amber { background: #fef3c7; border: 2px solid #f59e0b; }
        .sim-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .sim-table td, .sim-table th { padding: 8px 12px; border-bottom: 1px solid #e5e7eb; }
        .sim-table th { text-align: left; font-weight: 600; background: #f9fafb; }
        .sim-table .text-right { text-align: right; font-family: monospace; }
        .sim-table .total { font-weight: 700; background: #ecfdf5; }
        .sim-select { padding: 8px 12px; border-radius: 8px; border: 1px solid #d1d5db; width: 100%; font-size: 14px; }
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
                    <option value="50">50% (meublé classé)</option>
                    <option value="30">30% (meublé non classé)</option>
                    <option value="71">71% (achat-revente)</option>
                </select>
            </div>
        </div>

        @php $results = $this->simulationResults; @endphp

        @if($results['empty'] ?? false)
            <div class="sim-card" style="text-align: center; padding: 48px;">
                <p style="font-size: 18px; color: #6b7280;">Ajoutez un bien immobilier pour lancer la simulation.</p>
            </div>
        @else
            {{-- Comparaison principale --}}
            <div class="sim-grid sim-grid-3">
                <div class="sim-card">
                    <div class="sim-label">CA brut {{ $results['year'] }}</div>
                    <div class="sim-value">{{ $results['gross_income'] }} €</div>
                </div>
                <div class="sim-card sim-card-amber">
                    <div class="sim-label" style="color: #92400e;">Résultat micro-BIC (abattement {{ $results['abatement'] }}%)</div>
                    <div class="sim-value" style="color: #92400e;">{{ $results['micro_bic_result'] }} €</div>
                    <div class="sim-sub" style="color: #b45309;">Base imposable ajoutée au foyer</div>
                </div>
                <div class="sim-card sim-card-green">
                    <div class="sim-label" style="color: #065f46;">Résultat régime réel</div>
                    <div class="sim-value" style="color: #065f46;">{{ $results['real_result'] }} €</div>
                    <div class="sim-sub" style="color: #047857;">Base imposable ajoutée au foyer</div>
                </div>
            </div>

            {{-- Verdict --}}
            @if($results['recommended'] === 'real')
                <div class="sim-verdict sim-verdict-green">
                    <svg xmlns="http://www.w3.org/2000/svg" style="width:32px;height:32px;color:#10b981;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    <div>
                        <div style="font-size:18px;font-weight:700;color:#065f46;">Le régime réel est plus avantageux de {{ $results['advantage'] }} €</div>
                        <div style="font-size:14px;color:#047857;margin-top:4px;">
                            Économie d'impôt estimée : {{ $results['tax_saving_11'] }} € (TMI 11%) à {{ $results['tax_saving_30'] }} € (TMI 30%)
                            + {{ $results['ps_saving'] }} € de PS
                        </div>
                    </div>
                </div>
            @else
                <div class="sim-verdict sim-verdict-amber">
                    <svg xmlns="http://www.w3.org/2000/svg" style="width:32px;height:32px;color:#f59e0b;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg>
                    <div>
                        <div style="font-size:18px;font-weight:700;color:#92400e;">Le micro-BIC est plus avantageux</div>
                        <div style="font-size:14px;color:#b45309;">Différence : {{ $results['advantage'] }} € en faveur du micro-BIC</div>
                    </div>
                </div>
            @endif

            {{-- Détail --}}
            <div class="sim-grid sim-grid-2">
                <div class="sim-card">
                    <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;">Charges déductibles</h3>
                    <table class="sim-table">
                        <tr><td>Charges 100% dédiées</td><td class="text-right">{{ $results['expenses_dedicated'] }} €</td></tr>
                        <tr><td>Charges au prorata</td><td class="text-right">{{ $results['expenses_shared'] }} €</td></tr>
                    </table>
                </div>
                <div class="sim-card">
                    <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;">Amortissements</h3>
                    <table class="sim-table">
                        @foreach($results['depreciation_details'] as $propertyName => $dep)
                            <tr><th colspan="2">{{ $propertyName }}</th></tr>
                            @foreach($dep['details'] as $detail)
                                @if((int) $detail['amount'] > 0)
                                    <tr><td style="padding-left:20px;color:#6b7280;">{{ $detail['name'] }}</td><td class="text-right">{{ number_format((int) $detail['amount'] / 100, 0, ',', ' ') }} €</td></tr>
                                @endif
                            @endforeach
                            <tr class="total"><td style="padding-left:20px;">Total</td><td class="text-right">{{ number_format((int) $dep['total'] / 100, 0, ',', ' ') }} €</td></tr>
                        @endforeach
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
