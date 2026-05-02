<x-filament-panels::page>
    <style>
        .as-grid { display: grid; gap: 12px; }
        .as-grid-4 { grid-template-columns: repeat(4, 1fr); }
        .as-grid-3 { grid-template-columns: repeat(3, 1fr); }
        .as-grid-2 { grid-template-columns: repeat(2, 1fr); }
        .as-card { background: var(--fi-body-bg, white); border-radius: 12px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border: 1px solid var(--fi-border-color, #e5e7eb); }
        .as-card-label { font-size: 11px; color: var(--fi-fg-muted, #6b7280); text-transform: uppercase; letter-spacing: 0.5px; }
        .as-card-value { font-size: 24px; font-weight: 800; margin-top: 4px; }
        .as-card-sub { font-size: 12px; color: #6b7280; margin-top: 2px; }
        .as-section { margin-bottom: 24px; }
        .as-section-title { font-size: 16px; font-weight: 700; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid var(--fi-border-color, #e5e7eb); }
        .as-table { width: 100%; font-size: 13px; border-collapse: collapse; }
        .as-table th { text-align: left; padding: 8px; background: #f9fafb; font-weight: 600; }
        .as-table td { padding: 8px; border-top: 1px solid #f3f4f6; }
        .as-highlight { color: #10b981; }
        .as-muted { color: #6b7280; }
        @media (max-width: 768px) { .as-grid-4 { grid-template-columns: repeat(2, 1fr); } .as-grid-3 { grid-template-columns: repeat(1, 1fr); } }
    </style>

    @php $stats = $this->getStats(); @endphp

    {{-- UTILISATEURS --}}
    <div class="as-section">
        <div class="as-section-title">Utilisateurs</div>
        <div class="as-grid as-grid-3">
            <div class="as-card">
                <div class="as-card-label">Total inscrits</div>
                <div class="as-card-value">{{ $stats['users']['total'] }}</div>
            </div>
            <div class="as-card">
                <div class="as-card-label">Nouveaux (30j)</div>
                <div class="as-card-value">{{ $stats['users']['last_30_days'] }}</div>
            </div>
            <div class="as-card">
                <div class="as-card-label">Derniere inscription</div>
                <div class="as-card-value" style="font-size:14px;">
                    {{ $stats['users']['last_registered'] ? \Carbon\Carbon::parse($stats['users']['last_registered'])->format('d/m/Y H:i') : '-' }}
                </div>
            </div>
        </div>
    </div>

    {{-- BIENS --}}
    <div class="as-section">
        <div class="as-section-title">Patrimoine immobilier</div>
        <div class="as-grid as-grid-4">
            <div class="as-card">
                <div class="as-card-label">Biens enregistres</div>
                <div class="as-card-value">{{ $stats['properties']['total'] }}</div>
            </div>
            <div class="as-card">
                <div class="as-card-label">Valeur totale du parc</div>
                <div class="as-card-value as-highlight">{{ number_format(($stats['properties']['total_value_cents'] ?? 0) / 100, 0, ',', ' ') }} &euro;</div>
            </div>
            <div class="as-card">
                <div class="as-card-label">Surface louee totale</div>
                <div class="as-card-value">{{ $stats['properties']['total_rented_area'] ?? 0 }} m&sup2;</div>
            </div>
            <div class="as-card">
                <div class="as-card-label">Avec emprunt</div>
                <div class="as-card-value">{{ $stats['properties']['with_loans'] }}</div>
            </div>
        </div>
        @if(!empty($stats['properties']['by_type']))
            <div class="as-card" style="margin-top:12px;">
                <div class="as-card-label" style="margin-bottom:8px;">Repartition par type</div>
                <div style="display:flex;gap:16px;flex-wrap:wrap;">
                    @foreach($stats['properties']['by_type'] as $type => $count)
                        <span style="font-size:13px;"><strong>{{ ucfirst($type) }}</strong> : {{ $count }}</span>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- FINANCIER --}}
    <div class="as-section">
        <div class="as-section-title">Finances {{ $stats['financial']['year'] }}</div>
        <div class="as-grid as-grid-4">
            <div class="as-card">
                <div class="as-card-label">Recettes totales</div>
                <div class="as-card-value as-highlight">{{ number_format(($stats['financial']['total_income_cents'] ?? 0) / 100, 0, ',', ' ') }} &euro;</div>
            </div>
            <div class="as-card">
                <div class="as-card-label">Charges totales</div>
                <div class="as-card-value" style="color:#ef4444;">{{ number_format(($stats['financial']['total_expenses_cents'] ?? 0) / 100, 0, ',', ' ') }} &euro;</div>
            </div>
            <div class="as-card">
                <div class="as-card-label">Capital emprunte total</div>
                <div class="as-card-value">{{ number_format(($stats['financial']['total_loans_capital_cents'] ?? 0) / 100, 0, ',', ' ') }} &euro;</div>
            </div>
            <div class="as-card">
                <div class="as-card-label">Reservations {{ $stats['financial']['year'] }}</div>
                <div class="as-card-value">{{ $stats['financial']['reservation_count'] }}</div>
            </div>
        </div>

        @if(!empty($stats['financial']['income_by_source']))
            <div class="as-card" style="margin-top:12px;">
                <div class="as-card-label" style="margin-bottom:8px;">Recettes par source</div>
                <table class="as-table">
                    <thead><tr><th>Source</th><th>Nombre</th><th>Montant</th></tr></thead>
                    <tbody>
                    @foreach($stats['financial']['income_by_source'] as $source => $data)
                        <tr>
                            <td>{{ ucfirst($source) }}</td>
                            <td>{{ $data->count ?? 0 }}</td>
                            <td class="as-highlight">{{ number_format(($data->total ?? 0) / 100, 0, ',', ' ') }} &euro;</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if(!empty($stats['financial']['expense_by_category']))
            <div class="as-card" style="margin-top:12px;">
                <div class="as-card-label" style="margin-bottom:8px;">Charges par categorie</div>
                <table class="as-table">
                    <thead><tr><th>Categorie</th><th>Nombre</th><th>Montant</th></tr></thead>
                    <tbody>
                    @foreach($stats['financial']['expense_by_category'] as $cat => $data)
                        <tr>
                            <td>{{ ucfirst(str_replace('_', ' ', $cat)) }}</td>
                            <td>{{ $data->count ?? 0 }}</td>
                            <td style="color:#ef4444;">{{ number_format(($data->total ?? 0) / 100, 0, ',', ' ') }} &euro;</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ACTIVITE / DONNEES --}}
    <div class="as-section">
        <div class="as-section-title">Volume de donnees</div>
        <div class="as-grid as-grid-4">
            <div class="as-card">
                <div class="as-card-label">Recettes enregistrees</div>
                <div class="as-card-value">{{ $stats['activity']['total_incomes'] }}</div>
            </div>
            <div class="as-card">
                <div class="as-card-label">Charges enregistrees</div>
                <div class="as-card-value">{{ $stats['activity']['total_expenses'] }}</div>
            </div>
            <div class="as-card">
                <div class="as-card-label">Emprunts</div>
                <div class="as-card-value">{{ $stats['activity']['total_loans'] }}</div>
            </div>
            <div class="as-card">
                <div class="as-card-label">Mobilier</div>
                <div class="as-card-value">{{ $stats['activity']['total_furniture'] }}</div>
            </div>
            <div class="as-card">
                <div class="as-card-label">Travaux</div>
                <div class="as-card-value">{{ $stats['activity']['total_works'] }}</div>
            </div>
            <div class="as-card">
                <div class="as-card-label">Composants</div>
                <div class="as-card-value">{{ $stats['activity']['total_components'] }}</div>
            </div>
            <div class="as-card">
                <div class="as-card-label">Ecritures comptables</div>
                <div class="as-card-value">{{ $stats['activity']['total_accounting_entries'] }}</div>
            </div>
        </div>
    </div>

    {{-- FISCAL --}}
    <div class="as-section">
        <div class="as-section-title">Exercices fiscaux</div>
        <div class="as-grid as-grid-4">
            <div class="as-card">
                <div class="as-card-label">Brouillons</div>
                <div class="as-card-value">{{ $stats['fiscal']['by_status']['draft'] ?? 0 }}</div>
            </div>
            <div class="as-card">
                <div class="as-card-label">Clotures</div>
                <div class="as-card-value as-highlight">{{ $stats['fiscal']['by_status']['closed'] ?? 0 }}</div>
            </div>
            <div class="as-card">
                <div class="as-card-label">PDF generes</div>
                <div class="as-card-value">{{ $stats['fiscal']['with_pdf'] }}</div>
            </div>
            <div class="as-card">
                <div class="as-card-label">FEC generes</div>
                <div class="as-card-value">{{ $stats['fiscal']['with_fec'] }}</div>
            </div>
        </div>
        <div class="as-card" style="margin-top:12px;">
            <div class="as-card-label">Amortissements differes cumules (toutes annees)</div>
            <div class="as-card-value">{{ number_format(($stats['fiscal']['total_deferred_cents'] ?? 0) / 100, 0, ',', ' ') }} &euro;</div>
            <div class="as-card-sub">Report d'amortissements non imputes, utilisable sur les exercices futurs</div>
        </div>
    </div>
</x-filament-panels::page>
