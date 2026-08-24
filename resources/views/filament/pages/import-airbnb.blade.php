<x-filament-panels::page>
    <style>
        .import-card { background: var(--olmnp-surface); border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid var(--olmnp-border); }
        .import-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .import-table th { background: var(--olmnp-surface-muted); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; }
        .import-table th, .import-table td { padding: 10px 14px; border-bottom: 1px solid var(--olmnp-border); text-align: left; }
        .import-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
        .import-table th.num { text-align: right; }
        .import-table th.center, .import-table td.center { text-align: center; }
        .import-table tr.dup { opacity: 0.55; }
        .import-table tr.skipped { opacity: 0.45; }
        .import-table tr.skipped td { color: var(--olmnp-fg-muted); }
        .import-badge-skip { background: var(--olmnp-surface-alt); color: var(--olmnp-fg-muted); }
        .import-sep td { padding: 6px 14px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--olmnp-fg-muted); background: var(--olmnp-surface-muted); }
        .import-table tbody tr:hover { background: var(--olmnp-surface-muted); }
        .import-badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .import-badge-new { background: var(--olmnp-success-bg-strong); color: var(--olmnp-success-fg); }
        .import-badge-dup { background: var(--olmnp-warning-bg-strong); color: var(--olmnp-warning-fg); }
        .import-stats { display: flex; gap: 10px; flex-wrap: wrap; }
        .import-stat { display: inline-flex; align-items: center; gap: 4px; padding: 4px 12px; border-radius: 999px; font-size: 13px; font-weight: 600; }
        .import-stat-ok { background: var(--olmnp-success-bg-strong); color: var(--olmnp-success-fg); }
        .import-stat-dup { background: var(--olmnp-warning-bg-strong); color: var(--olmnp-warning-fg); }
        .import-stat-skip { background: var(--olmnp-surface-muted); color: var(--olmnp-fg-muted); }
        .import-actions { display: flex; gap: 10px; margin-top: 16px; }
        .import-btn { display: inline-flex; align-items: center; padding: 8px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; border: none; cursor: pointer; transition: background 0.15s; }
        .import-btn-confirm { background: var(--olmnp-success-solid-hover); color: var(--olmnp-on-solid); }
        .import-btn-confirm:hover { background: var(--olmnp-success-solid-hover); }
        .import-btn-cancel { background: var(--olmnp-surface-muted); color: var(--olmnp-fg); }
        .import-btn-cancel:hover { background: var(--olmnp-border); }
        .import-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .import-result { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .import-result-item { border-radius: 10px; padding: 16px; }
        .import-result-ok { background: var(--olmnp-success-bg); }
        .import-result-skip { background: var(--olmnp-warning-bg); }
        .import-result-val { font-size: 28px; font-weight: 700; }
        .import-result-ok .import-result-val { color: var(--olmnp-success-accent); }
        .import-result-skip .import-result-val { color: var(--olmnp-warning-fg); }
        .import-result-label { font-size: 13px; color: var(--olmnp-fg-muted); margin-top: 2px; }
        .import-errors { margin-top: 16px; padding: 12px 16px; background: var(--olmnp-danger-bg); border-radius: 10px; }
        .import-errors-title { font-size: 13px; font-weight: 600; color: var(--olmnp-danger-fg); margin-bottom: 6px; }
        .import-errors ul { margin: 0; padding-left: 20px; font-size: 13px; color: var(--olmnp-danger-accent); }
        .import-info { background: var(--olmnp-info-bg); border-radius: 12px; padding: 20px; border: 1px solid var(--olmnp-info-border); margin-top: 16px; }
        .import-info h4 { font-weight: 600; color: var(--olmnp-info-fg); margin: 0 0 8px; font-size: 14px; }
        .import-info ul { margin: 0; padding: 0; list-style: none; font-size: 13px; color: var(--olmnp-info-fg); }
        .import-info li { padding: 2px 0; }
        .import-info li::before { content: "•"; margin-right: 8px; }
        .import-mono { font-family: ui-monospace, monospace; font-size: 11px; color: var(--olmnp-fg-muted); }
        .import-warning { margin-bottom: 16px; padding: 12px 16px; background: var(--olmnp-warning-bg); border: 1px solid var(--olmnp-warning-border); border-radius: 10px; font-size: 13px; color: var(--olmnp-warning-fg); }
    </style>

    @if(!$previewData)
        {{-- Étape 1 : Upload (bouton dans footerActions de la Section) --}}
        {{ $this->form }}

        <div class="import-info">
            <h4>Formats supportés</h4>
            <ul>
                <li>Export Airbnb « Réservations » (CSV) — Code de confirmation, Nom du voyageur, Revenus...</li>
                <li>Export Airbnb « Historique des transactions » (CSV) — Date, Amount, Host fee...</li>
                <li>Les doublons (même code de confirmation) sont détectés automatiquement</li>
                <li>Les annulations et montants à 0 € sont ignorés</li>
            </ul>
        </div>
    @else
        {{-- Étape 2 : Preview --}}
        <div class="import-card">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
                <h3 style="font-size: 16px; font-weight: 700; margin: 0;">Aperçu de l'import</h3>
                <div class="import-stats">
                    @php
                        $allRows = collect($previewData['rows']);
                        $activeRows = $allRows->where('skipped', false);
                        $skippedRows = $allRows->where('skipped', true);
                        $importable = $activeRows->where('duplicate', false)->count();
                        $duplicates = $activeRows->where('duplicate', true)->count();
                    @endphp
                    <span class="import-stat import-stat-ok">{{ $importable }} à importer</span>
                    @if($duplicates > 0)
                        <span class="import-stat import-stat-dup">{{ $duplicates }} doublon(s)</span>
                    @endif
                    @if($skippedRows->count() > 0 || $previewData['skipped'] > 0)
                        <span class="import-stat import-stat-skip">{{ $skippedRows->count() + $previewData['skipped'] }} ignorée(s)</span>
                    @endif
                </div>
            </div>

            @if(!empty($previewData['warnings']))
                <div class="import-warning">
                    @foreach($previewData['warnings'] as $warning)
                        <p>{{ $warning }}</p>
                    @endforeach
                </div>
            @endif

            @if(!empty($previewData['errors']))
                <div class="import-errors">
                    <p class="import-errors-title">Erreurs :</p>
                    <ul>
                        @foreach($previewData['errors'] as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @php
                $isTvaLiable = $this->previewPropertyId
                    ? \App\Models\Property::find($this->previewPropertyId)?->isTvaLiable()
                    : false;
                $colCount = $isTvaLiable ? 9 : 7;
            @endphp
            @if(count($previewData['rows']) > 0)
                <div style="overflow-x: auto; border-radius: 8px; border: 1px solid var(--olmnp-border);">
                    <table class="import-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Voyageur</th>
                                <th>Confirmation</th>
                                <th>Check-in</th>
                                <th class="num">{{ $isTvaLiable ? 'Montant TTC' : 'Montant' }}</th>
                                <th class="num">Commission</th>
                                @if($isTvaLiable)
                                    <th class="num">HT</th>
                                    <th class="num">TVA 10%</th>
                                @endif
                                <th class="center">Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($activeRows as $row)
                                @php
                                    $htData = $isTvaLiable ? \App\Helpers\TvaHelper::fromTtc($row['amount'], 1000) : null;
                                @endphp
                                <tr class="{{ $row['duplicate'] ? 'dup' : '' }}">
                                    <td>{{ $row['date'] }}</td>
                                    <td>{{ $row['guest'] ?? '—' }}</td>
                                    <td><span class="import-mono">{{ $row['confirmation'] ?? '—' }}</span></td>
                                    <td>{{ $row['checkin'] ?? '—' }}</td>
                                    <td class="num" style="font-weight: 600;">{{ number_format($row['amount'] / 100, 2, ',', "\u{202F}") }}&nbsp;€</td>
                                    <td class="num">{{ number_format($row['host_fee'] / 100, 2, ',', "\u{202F}") }}&nbsp;€</td>
                                    @if($isTvaLiable)
                                        <td class="num">{{ number_format($htData['ht'] / 100, 2, ',', "\u{202F}") }}&nbsp;€</td>
                                        <td class="num">{{ number_format($htData['tva'] / 100, 2, ',', "\u{202F}") }}&nbsp;€</td>
                                    @endif
                                    <td class="center">
                                        @if($row['duplicate'])
                                            <span class="import-badge import-badge-dup">Doublon</span>
                                        @else
                                            <span class="import-badge import-badge-new">Nouveau</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            @if($skippedRows->count() > 0)
                                <tr class="import-sep">
                                    <td colspan="{{ $colCount }}">Ignorées ({{ $skippedRows->count() }})</td>
                                </tr>
                                @foreach($skippedRows as $row)
                                    <tr class="skipped">
                                        <td>{{ $row['date'] }}</td>
                                        <td>{{ $row['guest'] ?? '—' }}</td>
                                        <td><span class="import-mono">{{ $row['confirmation'] ?? '—' }}</span></td>
                                        <td>{{ $row['checkin'] ?? '—' }}</td>
                                        <td class="num">{{ number_format($row['amount'] / 100, 2, ',', "\u{202F}") }}&nbsp;€</td>
                                        <td class="num">—</td>
                                        @if($isTvaLiable)
                                            <td class="num">—</td>
                                            <td class="num">—</td>
                                        @endif
                                        <td class="center">
                                            <span class="import-badge import-badge-skip">{{ $row['skip_reason'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            @endif
                        </tbody>
                    </table>
                </div>
            @else
                <p style="font-size: 14px; color: var(--olmnp-fg-muted);">Aucune ligne importable trouvée dans le fichier.</p>
            @endif

            <div class="import-actions">
                @if($importable > 0)
                    <button wire:click="confirmImport" wire:loading.attr="disabled" class="import-btn import-btn-confirm">
                        <span wire:loading.remove wire:target="confirmImport">Confirmer l'import ({{ $importable }} recette{{ $importable > 1 ? 's' : '' }})</span>
                        <span wire:loading wire:target="confirmImport">Import en cours...</span>
                    </button>
                @endif
                <button wire:click="cancelPreview" class="import-btn import-btn-cancel">Annuler</button>
            </div>
        </div>
    @endif

    @if($lastResult)
        <div class="import-card">
            <h3 style="font-size: 16px; font-weight: 700; margin: 0 0 16px;">Résultat de l'import</h3>
            <div class="import-result">
                <div class="import-result-item import-result-ok">
                    <div class="import-result-val">{{ $lastResult['imported'] }}</div>
                    <div class="import-result-label">Recettes importées</div>
                </div>
                <div class="import-result-item import-result-skip">
                    <div class="import-result-val">{{ $lastResult['skipped'] }}</div>
                    <div class="import-result-label">Lignes ignorées</div>
                </div>
            </div>
            @if(!empty($lastResult['errors']))
                <div class="import-errors">
                    <p class="import-errors-title">Erreurs :</p>
                    <ul>
                        @foreach($lastResult['errors'] as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
