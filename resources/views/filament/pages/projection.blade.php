<x-filament-panels::page>
    <style>
        .proj-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .proj-table th, .proj-table td { padding: 8px 10px; border: 1px solid #e5e7eb; text-align: right; }
        .proj-table th { background: #f3f4f6; font-weight: 600; text-align: center; font-size: 12px; }
        .proj-table td:first-child, .proj-table th:first-child { text-align: center; font-weight: 700; }
        .proj-table .real-better { background: #ecfdf5; }
        .proj-table .micro-better { background: #fffbeb; }
        .proj-table .result-cell { font-weight: 700; }
        .proj-header { display: flex; gap: 16px; align-items: end; margin-bottom: 20px; }
        .proj-header label { font-size: 14px; color: #374151; font-weight: 500; }
        .proj-header select, .proj-header input { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
        .proj-legend { display: flex; gap: 20px; margin-top: 12px; font-size: 12px; color: #6b7280; }
        .proj-legend span { display: flex; align-items: center; gap: 6px; }
        .proj-legend .dot { width: 12px; height: 12px; border-radius: 3px; }
        .proj-card { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e5e7eb; }
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
        @endif
    </div>
</x-filament-panels::page>
