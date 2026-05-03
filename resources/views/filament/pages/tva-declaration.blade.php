<x-filament-panels::page>
    <style>
        .tva-card { background: var(--fi-body-bg, white); border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid var(--fi-border-color, #e5e7eb); margin-bottom: 16px; }
        .tva-grid { display: grid; gap: 16px; }
        .tva-grid-2 { grid-template-columns: repeat(2, 1fr); }
        .tva-grid-3 { grid-template-columns: repeat(3, 1fr); }
        .tva-label { font-size: 14px; color: var(--fi-fg-muted, #6b7280); margin-bottom: 4px; }
        .tva-value { font-size: 24px; font-weight: 700; }
        .tva-sub { font-size: 12px; margin-top: 4px; color: var(--fi-fg-muted, #6b7280); }
        .tva-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .tva-table td, .tva-table th { padding: 10px 14px; border-bottom: 1px solid var(--fi-border-color, #e5e7eb); }
        .tva-table th { text-align: left; font-weight: 600; background: var(--fi-bg-muted, #f9fafb); }
        .tva-table .text-right { text-align: right; font-family: monospace; }
        .tva-table .total { font-weight: 700; background: #f0f9ff; }
        .tva-select { padding: 8px 12px; border-radius: 8px; border: 1px solid #d1d5db; font-size: 14px; }
        .tva-balance-positive { color: #dc2626; }
        .tva-balance-negative { color: #16a34a; }
        .tva-badge { display: inline-block; padding: 4px 10px; border-radius: 9999px; font-size: 12px; font-weight: 600; }
        .tva-badge-red { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .tva-badge-green { background: #ecfdf5; color: #16a34a; border: 1px solid #bbf7d0; }
        @media (max-width: 768px) { .tva-grid-2, .tva-grid-3 { grid-template-columns: 1fr; } }
    </style>

    @php $data = $this->tvaData; @endphp

    {{-- Filtres --}}
    <div class="tva-grid tva-grid-2" style="margin-bottom: 24px; max-width: 400px;">
        <div>
            <div class="tva-label">Exercice</div>
            <select wire:model.live="year" class="tva-select" style="width: 100%;">
                @foreach ($this->availableYears as $y)
                    <option value="{{ $y }}">{{ $y }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <div class="tva-label">Affichage</div>
            <select wire:model.live="period" class="tva-select" style="width: 100%;">
                <option value="annual">Annuel (CA12)</option>
                <option value="quarterly">Trimestriel (CA3)</option>
            </select>
        </div>
    </div>

    @if (isset($data['empty']))
        <div class="tva-card" style="text-align: center; padding: 48px;">
            <div style="font-size: 48px; margin-bottom: 16px;">🏷️</div>
            <div style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">Aucun bien assujetti TVA</div>
            <div class="tva-sub">Pour activer la TVA, modifiez le regime TVA d'un bien dans sa fiche (onglet Location).</div>
        </div>
    @else
        {{-- Totaux --}}
        <div class="tva-grid tva-grid-3">
            <div class="tva-card">
                <div class="tva-label">TVA collectee</div>
                <div class="tva-value">{{ number_format($data['totals']['collected'] / 100, 2, ',', ' ') }} &euro;</div>
                <div class="tva-sub">Sur les loyers encaisses</div>
            </div>
            <div class="tva-card">
                <div class="tva-label">TVA deductible</div>
                <div class="tva-value">{{ number_format($data['totals']['deductible'] / 100, 2, ',', ' ') }} &euro;</div>
                <div class="tva-sub">Charges, travaux, mobilier</div>
            </div>
            <div class="tva-card">
                <div class="tva-label">Solde TVA</div>
                <div class="tva-value {{ $data['totals']['balance'] >= 0 ? 'tva-balance-positive' : 'tva-balance-negative' }}">
                    {{ number_format(abs($data['totals']['balance']) / 100, 2, ',', ' ') }} &euro;
                </div>
                <div class="tva-sub">
                    @if ($data['totals']['balance'] > 0)
                        <span class="tva-badge tva-badge-red">TVA a reverser</span>
                    @elseif ($data['totals']['balance'] < 0)
                        <span class="tva-badge tva-badge-green">Credit de TVA</span>
                    @else
                        Equilibre
                    @endif
                </div>
            </div>
        </div>

        {{-- Detail par bien --}}
        @if (count($data['properties']) > 1)
            <div class="tva-card">
                <h3 style="font-weight: 600; margin-bottom: 12px;">Detail par bien</h3>
                <table class="tva-table">
                    <thead>
                        <tr>
                            <th>Bien</th>
                            <th class="text-right">TVA collectee</th>
                            <th class="text-right">TVA deductible</th>
                            <th class="text-right">Solde</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['properties'] as $prop)
                            <tr>
                                <td>{{ $prop['property_name'] }}</td>
                                <td class="text-right">{{ number_format($prop['collected'] / 100, 2, ',', ' ') }} &euro;</td>
                                <td class="text-right">{{ number_format($prop['deductible'] / 100, 2, ',', ' ') }} &euro;</td>
                                <td class="text-right {{ $prop['balance'] >= 0 ? 'tva-balance-positive' : 'tva-balance-negative' }}">
                                    {{ number_format(abs($prop['balance']) / 100, 2, ',', ' ') }} &euro;
                                    {{ $prop['balance'] < 0 ? '(credit)' : '' }}
                                </td>
                            </tr>
                        @endforeach
                        <tr class="total">
                            <td>Total</td>
                            <td class="text-right">{{ number_format($data['totals']['collected'] / 100, 2, ',', ' ') }} &euro;</td>
                            <td class="text-right">{{ number_format($data['totals']['deductible'] / 100, 2, ',', ' ') }} &euro;</td>
                            <td class="text-right">{{ number_format(abs($data['totals']['balance']) / 100, 2, ',', ' ') }} &euro;</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Ventilation par taux --}}
        @if (! empty($data['by_rate']))
            <div class="tva-card">
                <h3 style="font-weight: 600; margin-bottom: 12px;">Ventilation par taux de TVA</h3>
                <table class="tva-table">
                    <thead>
                        <tr>
                            <th>Taux</th>
                            <th class="text-right">TVA collectee</th>
                            <th class="text-right">TVA deductible</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['by_rate'] as $rate => $amounts)
                            <tr>
                                <td>{{ number_format($rate / 100, 1, ',', '') }} %</td>
                                <td class="text-right">{{ number_format($amounts['collected'] / 100, 2, ',', ' ') }} &euro;</td>
                                <td class="text-right">{{ number_format($amounts['deductible'] / 100, 2, ',', ' ') }} &euro;</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Ventilation trimestrielle --}}
        @if ($period === 'quarterly')
            <div class="tva-card">
                <h3 style="font-weight: 600; margin-bottom: 12px;">Ventilation trimestrielle (CA3)</h3>
                <table class="tva-table">
                    <thead>
                        <tr>
                            <th>Trimestre</th>
                            <th class="text-right">TVA collectee</th>
                            <th class="text-right">TVA deductible</th>
                            <th class="text-right">Solde</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['quarters'] as $q => $amounts)
                            @php $qBalance = $amounts['collected'] - $amounts['deductible']; @endphp
                            <tr>
                                <td>T{{ $q }} ({{ ['janv.-mars', 'avr.-juin', 'juil.-sept.', 'oct.-dec.'][$q - 1] }})</td>
                                <td class="text-right">{{ number_format($amounts['collected'] / 100, 2, ',', ' ') }} &euro;</td>
                                <td class="text-right">{{ number_format($amounts['deductible'] / 100, 2, ',', ' ') }} &euro;</td>
                                <td class="text-right {{ $qBalance >= 0 ? 'tva-balance-positive' : 'tva-balance-negative' }}">
                                    {{ number_format(abs($qBalance) / 100, 2, ',', ' ') }} &euro;
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</x-filament-panels::page>
