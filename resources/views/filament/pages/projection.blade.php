<x-filament-panels::page>
    <style>
        .proj-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .proj-table th, .proj-table td { padding: 8px 10px; border: 1px solid var(--fi-border-color, #e5e7eb); text-align: right; }
        .proj-table th { background: var(--fi-bg-muted, #f3f4f6); font-weight: 600; text-align: center; font-size: 12px; }
        .proj-table td:first-child, .proj-table th:first-child { text-align: center; font-weight: 700; }
        .proj-table .real-better { background: #ecfdf5; }
        .proj-table .micro-better { background: #fffbeb; }
        .proj-table .result-cell { font-weight: 700; }
        .proj-header { display: flex; gap: 16px; align-items: end; margin-bottom: 20px; }
        .proj-header label { font-size: 14px; color: var(--fi-fg, #374151); font-weight: 500; }
        .proj-header select, .proj-header input { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
        .proj-legend { display: flex; gap: 20px; margin-top: 12px; font-size: 12px; color: var(--fi-fg-muted, #6b7280); }
        .proj-legend span { display: flex; align-items: center; gap: 6px; }
        .proj-legend .dot { width: 12px; height: 12px; border-radius: 3px; }
        .proj-card { background: var(--fi-body-bg, white); border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid var(--fi-border-color, #e5e7eb); }
    </style>

    <div>
        <div class="proj-header">
            <div>
                <label>Année de début</label><br>
                <select wire:model.live="startYear">
                    @for($y = date('Y') - 3; $y <= date('Y') + 2; $y++)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endfor
                </select>
            </div>
            <div>
                <label>Durée (années)</label><br>
                <select wire:model.live="projectionYears">
                    <option value="5">5 ans</option>
                    <option value="10" selected>10 ans</option>
                    <option value="15">15 ans</option>
                    <option value="20">20 ans</option>
                </select>
            </div>
            <div>
                <label>Croissance revenus (%/an)</label><br>
                <input type="number" wire:model.live="incomeGrowth" step="0.5" min="-5" max="10" style="width:80px;">
            </div>
            <div>
                <label>Croissance charges (%/an)</label><br>
                <input type="number" wire:model.live="expenseGrowth" step="0.5" min="-5" max="10" style="width:80px;">
            </div>
        </div>

        @php $data = $this->projectionData; @endphp

        @if($data['empty'] ?? false)
            <div class="proj-card" style="text-align:center;padding:48px;">
                <p style="font-size:18px;color:#6b7280;">Ajoutez un bien pour voir la projection.</p>
            </div>
        @else
            <div class="proj-card">
                <div style="overflow-x: auto;">
                    <table class="proj-table">
                        <thead>
                            <tr>
                                <th rowspan="2">Année</th>
                                <th rowspan="2">Recettes</th>
                                <th rowspan="2">Charges</th>
                                <th colspan="4">Amortissements</th>
                                <th rowspan="2">Différés</th>
                                <th rowspan="2">Résultat réel</th>
                                <th rowspan="2">Micro-BIC 50%</th>
                                <th rowspan="2">Régime</th>
                            </tr>
                            <tr>
                                <th>Immeuble</th>
                                <th>Travaux</th>
                                <th>Mobilier</th>
                                <th>Déduits</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($data['rows'] as $row)
                                <tr class="{{ $row['recommended'] === 'real' ? 'real-better' : 'micro-better' }}">
                                    <td>{{ $row['year'] }}</td>
                                    <td>{{ number_format($row['income'] / 100, 0, ',', ' ') }}</td>
                                    <td>{{ number_format($row['expenses'] / 100, 0, ',', ' ') }}</td>
                                    <td>{{ number_format($row['dep_building'] / 100, 0, ',', ' ') }}</td>
                                    <td>{{ $row['dep_works'] > 0 ? number_format($row['dep_works'] / 100, 0, ',', ' ') : '—' }}</td>
                                    <td>{{ $row['dep_furniture'] > 0 ? number_format($row['dep_furniture'] / 100, 0, ',', ' ') : '—' }}</td>
                                    <td>{{ number_format($row['capped'] / 100, 0, ',', ' ') }}</td>
                                    <td>{{ $row['deferred'] > 0 ? number_format($row['deferred'] / 100, 0, ',', ' ') : '—' }}</td>
                                    <td class="result-cell" style="color: {{ $row['recommended'] === 'real' ? '#065f46' : '#92400e' }}">
                                        {{ number_format($row['fiscal_result'] / 100, 0, ',', ' ') }} €
                                    </td>
                                    <td style="color:#92400e;">{{ number_format($row['micro_bic_50'] / 100, 0, ',', ' ') }} €</td>
                                    <td>
                                        @if($row['recommended'] === 'real')
                                            <span style="color:#065f46;font-weight:600;">Réel ✓</span>
                                        @else
                                            <span style="color:#92400e;font-weight:600;">Micro-BIC</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @php
                    $tippingYear = null;
                    foreach ($data['rows'] as $row) {
                        if ($row['recommended'] === 'micro_bic') {
                            $tippingYear = $row['year'];
                            break;
                        }
                    }
                @endphp

                @if($tippingYear)
                    <div style="margin-top:12px;padding:12px 16px;background:#fffbeb;border:1px solid #f59e0b;border-radius:8px;font-size:14px;color:#92400e;">
                        <strong>Point de bascule en {{ $tippingYear }}</strong> : à partir de cette année, le micro-BIC 50% devient plus avantageux que le régime réel.
                    </div>
                @else
                    <div style="margin-top:12px;padding:12px 16px;background:#ecfdf5;border:1px solid #10b981;border-radius:8px;font-size:14px;color:#065f46;">
                        <strong>Pas de bascule</strong> : sur toute la période projetée, le régime réel reste plus avantageux.
                    </div>
                @endif

                <div class="proj-legend">
                    <span><span class="dot" style="background:#d1fae5;"></span> Régime réel avantageux</span>
                    <span><span class="dot" style="background:#fef3c7;"></span> Micro-BIC avantageux</span>
                </div>
            </div>

            <div class="proj-card" style="margin-top:16px;">
                <h3 style="font-size:16px;font-weight:600;margin-bottom:8px;">Lecture du tableau</h3>
                <ul style="font-size:13px;color:#6b7280;list-style:disc;padding-left:20px;line-height:1.8;">
                    <li><strong>Amortissements déduits</strong> : montant effectivement déduit après plafonnement (ne peut pas créer de déficit)</li>
                    <li><strong>Différés</strong> : amortissements non déduits cette année, reportables indéfiniment</li>
                    <li><strong>Résultat réel</strong> : base imposable ajoutée au foyer fiscal</li>
                    <li><strong>Micro-BIC 50%</strong> : résultat si vous étiez en micro-BIC meublé classé</li>
                    <li>Quand le <strong>résultat réel dépasse le micro-BIC</strong>, envisagez de repasser en micro-BIC</li>
                </ul>
            </div>

            @php $a = $data['assumptions']; @endphp

            {{-- Panel : Hypothèses de revenus --}}
            <div class="proj-card" style="margin-top:16px;">
                <details>
                    <summary style="font-size:16px;font-weight:600;cursor:pointer;user-select:none;">
                        Hypothèses de revenus
                    </summary>
                    <div style="margin-top:12px;font-size:13px;color:#374151;line-height:1.8;">
                        <p style="margin-bottom:8px;">Les années sans revenus enregistrés utilisent la <strong>moyenne arithmétique</strong> des revenus historiques :</p>
                        <table style="border-collapse:collapse;margin-bottom:12px;">
                            <thead>
                                <tr>
                                    <th style="padding:4px 12px;text-align:left;border-bottom:2px solid #e5e7eb;font-size:12px;">Année</th>
                                    <th style="padding:4px 12px;text-align:right;border-bottom:2px solid #e5e7eb;font-size:12px;">Revenus</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($a['income']['by_year'] as $year => $amount)
                                    <tr>
                                        <td style="padding:4px 12px;">{{ $year }}</td>
                                        <td style="padding:4px 12px;text-align:right;">{{ number_format($amount / 100, 0, ',', ' ') }} &euro;</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr style="border-top:2px solid #e5e7eb;font-weight:600;">
                                    <td style="padding:4px 12px;">Total</td>
                                    <td style="padding:4px 12px;text-align:right;">{{ number_format(array_sum($a['income']['by_year']) / 100, 0, ',', ' ') }} &euro;</td>
                                </tr>
                            </tfoot>
                        </table>
                        <p style="background:#f3f4f6;padding:8px 12px;border-radius:6px;font-family:monospace;font-size:12px;">
                            {{ number_format(array_sum($a['income']['by_year']) / 100, 0, ',', ' ') }} &euro;
                            &divide; {{ count($a['income']['by_year']) }} années
                            = <strong>{{ number_format($a['income']['average'] / 100, 0, ',', ' ') }} &euro;/an</strong>
                        </p>
                    </div>
                </details>
            </div>

            {{-- Panel : Hypothèses de charges --}}
            <div class="proj-card" style="margin-top:16px;">
                <details>
                    <summary style="font-size:16px;font-weight:600;cursor:pointer;user-select:none;">
                        Hypothèses de charges
                    </summary>
                    <div style="margin-top:12px;font-size:13px;color:#374151;line-height:1.8;">
                        @php $prop = $a['properties'][0] ?? null; @endphp
                        @if($prop)
                            <p style="margin-bottom:8px;">
                                <strong>Quote-part :</strong>
                                {{ $prop['rented_area'] }} m&sup2; lou&eacute;s &divide; {{ $prop['total_area'] }} m&sup2; total
                                = <strong>{{ number_format((float) $prop['quota_share'] * 100, 2, ',', ' ') }}%</strong>
                            </p>
                        @endif
                        <table style="border-collapse:collapse;margin-bottom:12px;">
                            <tbody>
                                <tr>
                                    <td style="padding:4px 12px;">Charges d&eacute;di&eacute;es (moyenne/an)</td>
                                    <td style="padding:4px 12px;text-align:right;">{{ number_format($a['expenses']['dedicated'] / 100, 0, ',', ' ') }} &euro;</td>
                                    <td style="padding:4px 12px;color:#6b7280;font-size:12px;">100% d&eacute;ductibles</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 12px;">Charges partag&eacute;es (moyenne/an)</td>
                                    <td style="padding:4px 12px;text-align:right;">{{ number_format($a['expenses']['shared'] / 100, 0, ',', ' ') }} &euro;</td>
                                    <td style="padding:4px 12px;color:#6b7280;font-size:12px;">avant quote-part</td>
                                </tr>
                                <tr style="border-top:1px solid #e5e7eb;">
                                    <td style="padding:4px 12px;">Charges partag&eacute;es apr&egrave;s quote-part</td>
                                    <td style="padding:4px 12px;text-align:right;">{{ number_format($a['expenses']['shared_after_quota'] / 100, 0, ',', ' ') }} &euro;</td>
                                    <td style="padding:4px 12px;color:#6b7280;font-size:12px;">
                                        {{ number_format($a['expenses']['shared'] / 100, 0, ',', ' ') }} &times;
                                        {{ number_format((float) ($prop['quota_share'] ?? 0) * 100, 2, ',', ' ') }}%
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr style="border-top:2px solid #e5e7eb;font-weight:600;">
                                    <td style="padding:4px 12px;">Total projet&eacute;</td>
                                    <td style="padding:4px 12px;text-align:right;">{{ number_format($a['expenses']['total'] / 100, 0, ',', ' ') }} &euro;/an</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </details>
            </div>

            {{-- Panel : Détail des amortissements --}}
            <div class="proj-card" style="margin-top:16px;">
                <details>
                    <summary style="font-size:16px;font-weight:600;cursor:pointer;user-select:none;">
                        D&eacute;tail des amortissements
                    </summary>
                    <div style="margin-top:12px;font-size:13px;color:#374151;line-height:1.8;">
                        @if($prop)
                            <p style="background:#f3f4f6;padding:8px 12px;border-radius:6px;font-family:monospace;font-size:12px;margin-bottom:16px;">
                                <strong>Base amortissable</strong> =
                                {{ number_format(($prop['market_value']) / 100, 0, ',', ' ') }} &euro;
                                &times; {{ 100 - $prop['land_percentage'] }}% (hors terrain)
                                &times; {{ number_format((float) $prop['quota_share'] * 100, 2, ',', ' ') }}% (quote-part)
                                = <strong>{{ number_format($prop['depreciable_base'] / 100, 0, ',', ' ') }} &euro;</strong>
                            </p>
                        @endif

                        {{-- Composants immeuble --}}
                        @if(count($a['depreciation']['components']) > 0)
                            <h4 style="font-weight:600;margin-bottom:6px;">Composants immeuble</h4>
                            <table style="border-collapse:collapse;margin-bottom:16px;width:100%;">
                                <thead>
                                    <tr>
                                        <th style="padding:4px 10px;text-align:left;border-bottom:2px solid #e5e7eb;font-size:12px;">Composant</th>
                                        <th style="padding:4px 10px;text-align:right;border-bottom:2px solid #e5e7eb;font-size:12px;">%</th>
                                        <th style="padding:4px 10px;text-align:right;border-bottom:2px solid #e5e7eb;font-size:12px;">Dur&eacute;e</th>
                                        <th style="padding:4px 10px;text-align:right;border-bottom:2px solid #e5e7eb;font-size:12px;">Annuit&eacute;</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($a['depreciation']['components'] as $comp)
                                        <tr>
                                            <td style="padding:4px 10px;">{{ $comp['name'] }}</td>
                                            <td style="padding:4px 10px;text-align:right;">{{ $comp['percentage'] }}%</td>
                                            <td style="padding:4px 10px;text-align:right;">{{ $comp['duration'] }} ans</td>
                                            <td style="padding:4px 10px;text-align:right;">{{ number_format($comp['annual'] / 100, 0, ',', ' ') }} &euro;</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr style="border-top:2px solid #e5e7eb;font-weight:600;">
                                        <td colspan="3" style="padding:4px 10px;">Total immeuble</td>
                                        <td style="padding:4px 10px;text-align:right;">{{ number_format($a['depreciation']['total_building'] / 100, 0, ',', ' ') }} &euro;/an</td>
                                    </tr>
                                </tfoot>
                            </table>
                        @endif

                        {{-- Travaux --}}
                        @if(count($a['depreciation']['works']) > 0)
                            <h4 style="font-weight:600;margin-bottom:6px;">Travaux</h4>
                            <table style="border-collapse:collapse;margin-bottom:16px;width:100%;">
                                <thead>
                                    <tr>
                                        <th style="padding:4px 10px;text-align:left;border-bottom:2px solid #e5e7eb;font-size:12px;">Description</th>
                                        <th style="padding:4px 10px;text-align:right;border-bottom:2px solid #e5e7eb;font-size:12px;">Montant</th>
                                        <th style="padding:4px 10px;text-align:right;border-bottom:2px solid #e5e7eb;font-size:12px;">Dur&eacute;e</th>
                                        <th style="padding:4px 10px;text-align:right;border-bottom:2px solid #e5e7eb;font-size:12px;">Annuit&eacute;</th>
                                        <th style="padding:4px 10px;text-align:center;border-bottom:2px solid #e5e7eb;font-size:12px;">D&eacute;di&eacute;</th>
                                        <th style="padding:4px 10px;text-align:right;border-bottom:2px solid #e5e7eb;font-size:12px;">Apr&egrave;s quote-part</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($a['depreciation']['works'] as $work)
                                        <tr>
                                            <td style="padding:4px 10px;">{{ $work['description'] }}</td>
                                            <td style="padding:4px 10px;text-align:right;">{{ number_format($work['amount'] / 100, 0, ',', ' ') }} &euro;</td>
                                            <td style="padding:4px 10px;text-align:right;">{{ $work['duration'] }} ans</td>
                                            <td style="padding:4px 10px;text-align:right;">{{ number_format($work['annual'] / 100, 0, ',', ' ') }} &euro;</td>
                                            <td style="padding:4px 10px;text-align:center;">{{ $work['is_dedicated'] ? 'Oui' : 'Non' }}</td>
                                            <td style="padding:4px 10px;text-align:right;">{{ number_format($work['after_quota'] / 100, 0, ',', ' ') }} &euro;</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr style="border-top:2px solid #e5e7eb;font-weight:600;">
                                        <td colspan="5" style="padding:4px 10px;">Total travaux</td>
                                        <td style="padding:4px 10px;text-align:right;">{{ number_format($a['depreciation']['total_works'] / 100, 0, ',', ' ') }} &euro;/an</td>
                                    </tr>
                                </tfoot>
                            </table>
                        @endif

                        {{-- Mobilier --}}
                        @if(count($a['depreciation']['furniture']) > 0)
                            <h4 style="font-weight:600;margin-bottom:6px;">Mobilier</h4>
                            <table style="border-collapse:collapse;margin-bottom:16px;width:100%;">
                                <thead>
                                    <tr>
                                        <th style="padding:4px 10px;text-align:left;border-bottom:2px solid #e5e7eb;font-size:12px;">Description</th>
                                        <th style="padding:4px 10px;text-align:right;border-bottom:2px solid #e5e7eb;font-size:12px;">Montant</th>
                                        <th style="padding:4px 10px;text-align:right;border-bottom:2px solid #e5e7eb;font-size:12px;">Dur&eacute;e</th>
                                        <th style="padding:4px 10px;text-align:right;border-bottom:2px solid #e5e7eb;font-size:12px;">Annuit&eacute;</th>
                                        <th style="padding:4px 10px;text-align:center;border-bottom:2px solid #e5e7eb;font-size:12px;">D&eacute;di&eacute;</th>
                                        <th style="padding:4px 10px;text-align:right;border-bottom:2px solid #e5e7eb;font-size:12px;">Apr&egrave;s quote-part</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($a['depreciation']['furniture'] as $item)
                                        <tr>
                                            <td style="padding:4px 10px;">{{ $item['description'] }}</td>
                                            <td style="padding:4px 10px;text-align:right;">{{ number_format($item['amount'] / 100, 0, ',', ' ') }} &euro;</td>
                                            <td style="padding:4px 10px;text-align:right;">{{ $item['duration'] }} ans</td>
                                            <td style="padding:4px 10px;text-align:right;">{{ number_format($item['annual'] / 100, 0, ',', ' ') }} &euro;</td>
                                            <td style="padding:4px 10px;text-align:center;">{{ $item['is_dedicated'] ? 'Oui' : 'Non' }}</td>
                                            <td style="padding:4px 10px;text-align:right;">{{ number_format($item['after_quota'] / 100, 0, ',', ' ') }} &euro;</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr style="border-top:2px solid #e5e7eb;font-weight:600;">
                                        <td colspan="5" style="padding:4px 10px;">Total mobilier</td>
                                        <td style="padding:4px 10px;text-align:right;">{{ number_format($a['depreciation']['total_furniture'] / 100, 0, ',', ' ') }} &euro;/an</td>
                                    </tr>
                                </tfoot>
                            </table>
                        @endif

                        {{-- Total et formules --}}
                        <div style="background:#ecfdf5;padding:12px;border-radius:6px;margin-bottom:12px;">
                            <p style="font-weight:600;font-size:14px;">
                                Total amortissements annuels :
                                {{ number_format($a['depreciation']['total_building'] / 100, 0, ',', ' ') }}
                                + {{ number_format($a['depreciation']['total_works'] / 100, 0, ',', ' ') }}
                                + {{ number_format($a['depreciation']['total_furniture'] / 100, 0, ',', ' ') }}
                                = <span style="color:#065f46;">{{ number_format($a['depreciation']['grand_total'] / 100, 0, ',', ' ') }} &euro;/an</span>
                            </p>
                        </div>

                        <h4 style="font-weight:600;margin-bottom:6px;">Formules cl&eacute;s</h4>
                        <ul style="font-size:12px;color:#6b7280;list-style:disc;padding-left:20px;line-height:2;">
                            <li><strong>Plafonnement</strong> : Amort. d&eacute;duits = min(amort. disponibles, max(0, recettes &minus; charges)). L'amortissement ne peut jamais cr&eacute;er de d&eacute;ficit.</li>
                            <li><strong>Diff&eacute;r&eacute;s</strong> : Les amortissements non d&eacute;duits sont report&eacute;s sur les ann&eacute;es suivantes, sans limite de dur&eacute;e.</li>
                            <li><strong>Micro-BIC 50%</strong> : Recettes &times; 50% (abattement meubl&eacute; de tourisme class&eacute;).</li>
                        </ul>
                    </div>
                </details>
            </div>
        @endif
    </div>
</x-filament-panels::page>
