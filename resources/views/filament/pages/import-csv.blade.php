<x-filament-panels::page>
    <style>
        /* Aucun utilitaire Tailwind ne fonctionne dans le panel Filament : toutes les
           classes utilisées ici doivent être déclarées dans ce bloc, et toutes les
           couleurs viennent des jetons --olmnp-* (partials/theme-tokens). */
        .csvi-card { background: var(--olmnp-surface); border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid var(--olmnp-border); margin-top: 16px; }
        .csvi-title { font-size: 13px; font-weight: 700; color: var(--olmnp-fg-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid var(--olmnp-border); }
        .csvi-hint { font-size: 12px; color: var(--olmnp-fg-muted); line-height: 1.5; margin-bottom: 12px; }

        .csvi-map { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
        .csvi-map-row { display: flex; flex-direction: column; gap: 4px; }
        .csvi-map-label { font-size: 13px; font-weight: 600; color: var(--olmnp-fg); }
        .csvi-required { color: var(--olmnp-danger-accent); margin-left: 3px; }
        .csvi-select { padding: 6px 10px; border: 1px solid var(--olmnp-border-strong); border-radius: 8px; font-size: 13px; background: var(--olmnp-surface); color: var(--olmnp-fg); }

        .csvi-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .csvi-table th { background: var(--olmnp-surface-muted); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; }
        .csvi-table th, .csvi-table td { padding: 8px 12px; border-bottom: 1px solid var(--olmnp-border); text-align: left; }
        .csvi-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
        .csvi-table th.num { text-align: right; }
        .csvi-table tr.dup { opacity: 0.55; }
        .csvi-wrap { overflow-x: auto; }

        .csvi-badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .csvi-badge-new { background: var(--olmnp-success-bg-strong); color: var(--olmnp-success-fg); }
        .csvi-badge-dup { background: var(--olmnp-warning-bg-strong); color: var(--olmnp-warning-fg); }

        .csvi-stats { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
        .csvi-stat { display: inline-flex; align-items: center; gap: 4px; padding: 4px 12px; border-radius: 999px; font-size: 13px; font-weight: 600; }
        .csvi-stat-ok { background: var(--olmnp-success-bg-strong); color: var(--olmnp-success-fg); }
        .csvi-stat-dup { background: var(--olmnp-warning-bg-strong); color: var(--olmnp-warning-fg); }
        .csvi-stat-skip { background: var(--olmnp-surface-muted); color: var(--olmnp-fg-muted); }

        .csvi-actions { display: flex; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
        .csvi-btn { display: inline-flex; align-items: center; padding: 8px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; border: none; cursor: pointer; }
        .csvi-btn-confirm { background: var(--olmnp-success-solid); color: var(--olmnp-on-solid); }
        .csvi-btn-confirm:hover { background: var(--olmnp-success-solid-hover); }
        .csvi-btn-confirm:disabled { opacity: 0.5; cursor: not-allowed; }
        .csvi-btn-secondary { background: var(--olmnp-surface-muted); color: var(--olmnp-fg); }
        .csvi-btn-secondary:hover { background: var(--olmnp-border); }

        .csvi-alert { margin-top: 12px; padding: 12px 16px; border-radius: 10px; font-size: 13px; }
        .csvi-alert-danger { background: var(--olmnp-danger-bg); border: 1px solid var(--olmnp-danger-border); color: var(--olmnp-danger-fg); }
        .csvi-alert-warning { background: var(--olmnp-warning-bg); border: 1px solid var(--olmnp-warning-border); color: var(--olmnp-warning-fg); }
        .csvi-alert-info { background: var(--olmnp-info-bg); border: 1px solid var(--olmnp-info-border); color: var(--olmnp-info-fg); }
        .csvi-alert ul { margin: 6px 0 0; padding-left: 20px; }

        .csvi-result { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 16px; }
        .csvi-result-item { border-radius: 10px; padding: 16px; }
        .csvi-result-ok { background: var(--olmnp-success-bg); }
        .csvi-result-dup { background: var(--olmnp-warning-bg); }
        .csvi-result-skip { background: var(--olmnp-surface-muted); }
        .csvi-result-val { font-size: 28px; font-weight: 700; color: var(--olmnp-fg); }
        .csvi-result-label { font-size: 13px; color: var(--olmnp-fg-muted); margin-top: 2px; }
    </style>

    @if(! $previewData)
        {{ $this->form }}

        @if($lastResult)
            <div class="csvi-card">
                <div class="csvi-title">Résultat du dernier import</div>
                <div class="csvi-result">
                    <div class="csvi-result-item csvi-result-ok">
                        <div class="csvi-result-val">{{ $lastResult['imported'] }}</div>
                        <div class="csvi-result-label">ligne(s) importée(s)</div>
                    </div>
                    <div class="csvi-result-item csvi-result-dup">
                        <div class="csvi-result-val">{{ $lastResult['duplicates'] }}</div>
                        <div class="csvi-result-label">doublon(s) ignoré(s)</div>
                    </div>
                    <div class="csvi-result-item csvi-result-skip">
                        <div class="csvi-result-val">{{ $lastResult['skipped'] }}</div>
                        <div class="csvi-result-label">ligne(s) illisible(s)</div>
                    </div>
                </div>
                @if(! empty($lastResult['errors']))
                    <div class="csvi-alert csvi-alert-danger">
                        <strong>Lignes non importées</strong>
                        <ul>
                            @foreach($lastResult['errors'] as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif

        <div class="csvi-card">
            <div class="csvi-title">Ce que cet écran sait lire</div>
            <p class="csvi-hint">
                N'importe quel tableur exporté en CSV : séparateur virgule, point-virgule ou
                tabulation, montants au format français (<span>1 234,56 €</span>) comme anglo-saxon,
                dates en <span>JJ/MM/AAAA</span> ou <span>AAAA-MM-JJ</span>. Les intitulés de colonnes
                sont reconnus sans tenir compte des accents ni de la casse — et quand la
                reconnaissance se trompe, l'écran suivant vous laisse la corriger avant d'écrire
                quoi que ce soit.
            </p>
            <p class="csvi-hint">
                Pour un export <strong>Airbnb</strong>, préférez l'écran dédié : lui seul reconstitue
                le montant brut quand l'export ne détaille pas la commission.
            </p>
        </div>
    @else
        {{-- Étape 2 : mappage des colonnes --}}
        <div class="csvi-card">
            <div class="csvi-title">Correspondance des colonnes</div>
            <p class="csvi-hint">
                Vérifiez chaque ligne : la proposition vient des intitulés de votre fichier, elle
                peut se tromper. Les champs marqués d'une étoile sont obligatoires.
            </p>

            <div class="csvi-map">
                @foreach($this->targetFields() as $field => $spec)
                    <div class="csvi-map-row">
                        <label class="csvi-map-label">
                            {{ $spec['label'] }}@if($spec['required'])<span class="csvi-required">*</span>@endif
                        </label>
                        <select class="csvi-select" wire:model="mapping.{{ $field }}" wire:change="refreshPreview">
                            <option value="">— ignorer —</option>
                            @foreach($previewData['header'] as $index => $column)
                                <option value="{{ $index }}">{{ $column !== '' ? $column : 'Colonne ' . ($index + 1) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            </div>

            @if(! empty($previewData['missing']))
                <div class="csvi-alert csvi-alert-danger">
                    Colonnes obligatoires non renseignées :
                    <strong>{{ implode(', ', $previewData['missing']) }}</strong>.
                    L'import restera bloqué tant qu'elles ne sont pas associées à une colonne.
                </div>
            @endif
        </div>

        {{-- Étape 3 : aperçu --}}
        <div class="csvi-card">
            <div class="csvi-title">
                Aperçu — {{ count($previewData['rows']) }} ligne(s) sur {{ $previewData['total'] }}
            </div>

            <div class="csvi-stats">
                <span class="csvi-stat csvi-stat-ok">{{ $previewData['total'] - $previewData['duplicates'] }} à importer</span>
                <span class="csvi-stat csvi-stat-dup">{{ $previewData['duplicates'] }} doublon(s)</span>
                <span class="csvi-stat csvi-stat-skip">{{ count($previewData['errors']) }} ligne(s) en erreur</span>
            </div>

            @if(count($previewData['rows']) > 0)
                <div class="csvi-wrap">
                    <table class="csvi-table">
                        <thead>
                            <tr>
                                @foreach($this->targetFields() as $field => $spec)
                                    <th class="{{ in_array($spec['type'], ['money', 'integer'], true) ? 'num' : '' }}">{{ $spec['label'] }}</th>
                                @endforeach
                                <th>État</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($previewData['rows'] as $row)
                                <tr class="{{ $row['duplicate'] ? 'dup' : '' }}">
                                    @foreach($this->targetFields() as $field => $spec)
                                        <td class="{{ in_array($spec['type'], ['money', 'integer'], true) ? 'num' : '' }}">
                                            @if($row[$field] === null || $row[$field] === '')
                                                —
                                            @elseif($spec['type'] === 'money')
                                                {{ number_format($row[$field] / 100, 2, ',', ' ') }} &euro;
                                            @elseif($spec['type'] === 'boolean')
                                                {{ $row[$field] ? 'oui' : 'non' }}
                                            @else
                                                {{ $row[$field] }}
                                            @endif
                                        </td>
                                    @endforeach
                                    <td>
                                        <span class="csvi-badge {{ $row['duplicate'] ? 'csvi-badge-dup' : 'csvi-badge-new' }}">
                                            {{ $row['duplicate'] ? 'doublon' : 'nouveau' }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="csvi-alert csvi-alert-warning">
                    Aucune ligne exploitable avec ce mappage. Vérifiez la correspondance des colonnes
                    ci-dessus — c'est presque toujours la colonne de date ou de montant qui manque.
                </div>
            @endif

            @if(! empty($previewData['errors']))
                <div class="csvi-alert csvi-alert-warning">
                    <strong>Lignes qui seront ignorées</strong>
                    <ul>
                        @foreach($previewData['errors'] as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="csvi-actions">
                <button
                    class="csvi-btn csvi-btn-confirm"
                    wire:click="confirmImport"
                    @disabled(! empty($previewData['missing']) || count($previewData['rows']) === 0)
                >
                    Importer
                </button>
                <button class="csvi-btn csvi-btn-secondary" wire:click="cancelPreview">Annuler</button>
            </div>
        </div>
    @endif
</x-filament-panels::page>
