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

        /* Le tableau débordait de sa carte : les colonnes de droite étaient invisibles et
           les en-têtes coupés, sans que rien n'indique qu'il fallait faire défiler.
           `max-content` empêche les cellules de s'écraser, le conteneur devient la zone
           de défilement, et la PREMIÈRE COLONNE reste visible pendant qu'on défile — sans
           elle on ne sait plus de quelle ligne on lit les valeurs. */
        .csvi-wrap { overflow-x: auto; max-width: 100%; }
        .csvi-wrap .csvi-table { min-width: max-content; }
        .csvi-table th:first-child, .csvi-table td:first-child { position: sticky; left: 0; z-index: 1; }
        .csvi-table td:first-child { background: var(--olmnp-surface); }
        .csvi-table th:first-child { background: var(--olmnp-surface-muted); }
        .csvi-scroll-hint { font-size: 11.5px; color: var(--olmnp-fg-muted); margin-bottom: 8px; }

        /* Aperçu BRUT : le fichier tel qu'il est, en-tête réel compris. */
        .csvi-raw-head { font-family: monospace; font-size: 11.5px; text-transform: none; letter-spacing: 0; }
        .csvi-raw td { font-family: monospace; font-size: 12px; white-space: nowrap; }
        .csvi-colnum { display: block; font-size: 10px; font-weight: 400; color: var(--olmnp-fg-subtle); text-transform: uppercase; }
        .csvi-rownum { color: var(--olmnp-fg-subtle); font-variant-numeric: tabular-nums; }

        /* Exemple tiré du fichier, à côté du libellé du champ : c'est lui qui dit d'un
           coup d'œil si la colonne choisie est la bonne. */
        .csvi-sample { display: block; margin-top: 3px; font-family: monospace; font-size: 11.5px; color: var(--olmnp-fg-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .csvi-sample-empty { font-family: inherit; font-style: italic; }

        .csvi-badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .csvi-badge-new { background: var(--olmnp-success-bg-strong); color: var(--olmnp-success-fg); }
        .csvi-badge-dup { background: var(--olmnp-warning-bg-strong); color: var(--olmnp-warning-fg); }

        .csvi-stats { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
        .csvi-stat { display: inline-flex; align-items: center; gap: 4px; padding: 4px 12px; border-radius: 999px; font-size: 13px; font-weight: 600; }
        .csvi-stat-ok { background: var(--olmnp-success-bg-strong); color: var(--olmnp-success-fg); }
        .csvi-stat-dup { background: var(--olmnp-warning-bg-strong); color: var(--olmnp-warning-fg); }
        .csvi-stat-skip { background: var(--olmnp-surface-muted); color: var(--olmnp-fg-muted); }
        .csvi-stat-err { background: var(--olmnp-danger-bg-strong); color: var(--olmnp-danger-fg); }

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
                        <div class="csvi-result-val">{{ $lastResult['errors_total'] ?? $lastResult['skipped'] }}</div>
                        <div class="csvi-result-label">ligne(s) illisible(s)</div>
                    </div>
                    @if(($lastResult['empty'] ?? 0) > 0)
                        <div class="csvi-result-item csvi-result-skip">
                            <div class="csvi-result-val">{{ $lastResult['empty'] }}</div>
                            <div class="csvi-result-label">ligne(s) vide(s) ignorée(s)</div>
                        </div>
                    @endif
                </div>
                @if(! empty($lastResult['errors']))
                    <div class="csvi-alert csvi-alert-danger">
                        <strong>Lignes non importées</strong>
                        @if(($lastResult['errors_total'] ?? 0) > count($lastResult['errors']))
                            &mdash; les {{ count($lastResult['errors']) }} premières sur
                            {{ $lastResult['errors_total'] }}.
                        @endif
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
        {{-- Contrôle de cohérence : les intitulés du fichier contredisent la nature choisie.
             C'est une QUESTION, jamais un refus — la liste d'intitulés qui la déclenche se
             périmera, et un import bloqué par une devinette coûterait plus cher que la
             méprise qu'il prétend éviter. Mais la méprise, elle, est silencieuse : des
             recettes importées en « Charges » inversent le signe du résultat fiscal. --}}
        @if($this->suspectedTargetLabel())
            <div class="csvi-alert csvi-alert-warning">
                <strong>Est-ce bien la bonne nature&nbsp;?</strong>
                Les intitulés de ce fichier ressemblent à
                <strong>{{ $this->suspectedTargetLabel() }}</strong>, et vous avez choisi
                <strong>{{ \App\Services\Csv\CsvProfile::targetLabels()[$previewTarget] ?? $previewTarget }}</strong>.
                Se tromper de nature inverse le signe de votre résultat fiscal, et rien ne
                le signalera ensuite. Annulez pour changer, ou poursuivez si c'est voulu.
            </div>
        @endif

        {{-- Étape 2 : le fichier tel qu'il est.
             ⚠️ Il n'était JAMAIS montré. `preview()` ne rendait que des lignes déjà
             converties par le mappage, et l'en-tête réel du fichier ne servait qu'à
             deviner la correspondance. On demandait donc de vérifier un mappage sans
             rien donner à vérifier — d'où « dans le select ce sont les champs
             disponibles ? chaque bloc correspond à une colonne du tableau ? ». --}}
        <div class="csvi-card">
            <div class="csvi-title">
                Votre fichier &mdash; {{ count($previewData['raw_rows']) }} première(s) ligne(s) sur {{ $previewData['total'] }}
            </div>
            <p class="csvi-hint">
                Les colonnes ci-dessous sont celles de votre fichier, telles qu'il les porte.
                C'est à elles que le bloc suivant associe les champs d'OpenLMNP.
            </p>

            @if(count($previewData['header']) > 5)
                <p class="csvi-scroll-hint">
                    Tableau plus large que l'écran : faites-le défiler horizontalement.
                    La première colonne reste visible.
                </p>
            @endif

            <div class="csvi-wrap">
                <table class="csvi-table csvi-raw">
                    <thead>
                        <tr>
                            <th class="csvi-raw-head">Ligne</th>
                            @foreach($previewData['header'] as $index => $column)
                                <th class="csvi-raw-head">
                                    <span class="csvi-colnum">Colonne {{ $index + 1 }}</span>
                                    {{ $column !== '' ? $column : '—' }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($previewData['raw_rows'] as $rowIndex => $rawRow)
                            <tr>
                                {{-- +2 : l'en-tête occupe la ligne 1 du fichier. --}}
                                <td class="csvi-rownum">{{ $rowIndex + 2 }}</td>
                                @foreach($previewData['header'] as $index => $column)
                                    <td>{{ trim((string) ($rawRow[$index] ?? '')) !== '' ? $rawRow[$index] : '—' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Étape 3 : mappage des colonnes --}}
        <div class="csvi-card">
            <div class="csvi-title">Correspondance des colonnes</div>
            <p class="csvi-hint">
                Un bloc par <strong>champ d'OpenLMNP</strong> ; la liste déroulante contient les
                <strong>colonnes de votre fichier</strong>. Sous chaque liste, la première valeur
                trouvée dans la colonne choisie : c'est elle qui dit d'un coup d'œil si la
                correspondance est la bonne. La proposition vient des intitulés de votre fichier,
                elle peut se tromper. Les champs marqués d'une étoile sont obligatoires.
            </p>

            <div class="csvi-map">
                @foreach($this->targetFields() as $field => $spec)
                    @php $sample = $this->sampleFor($field); @endphp
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
                        @if($sample !== null)
                            <span class="csvi-sample">Exemple : {{ $sample }}</span>
                        @else
                            <span class="csvi-sample csvi-sample-empty">Aucune valeur d'exemple pour cette colonne.</span>
                        @endif
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

        {{-- Étape 4 : aperçu converti, doublons repérés --}}
        <div class="csvi-card">
            <div class="csvi-title">
                Aperçu après correspondance — {{ count($previewData['rows']) }} ligne(s) sur {{ $previewData['total'] }}
            </div>

            {{-- ⚠️ Ces quatre compteurs se partagent EXACTEMENT le total du fichier.
                 Ils ne le faisaient pas : « à importer » valait `total - doublons`, donc y
                 comptait les lignes en erreur et les lignes vides, et « en erreur »
                 affichait la taille d'une liste tronquée à vingt — un plafond présenté
                 comme un décompte. Sur 121 lignes, l'écran annonçait 60 + 61 + 20 = 141. --}}
            <div class="csvi-stats">
                <span class="csvi-stat csvi-stat-ok">{{ $previewData['importable'] }} à importer</span>
                <span class="csvi-stat csvi-stat-dup">
                    {{ $previewData['duplicates'] }} doublon(s)
                    @if(($previewData['repeats'] ?? 0) > 0)
                        (dont {{ $previewData['repeats'] }} répété(s) dans le fichier)
                    @endif
                </span>
                <span class="csvi-stat csvi-stat-err">{{ $previewData['errors_total'] }} ligne(s) en erreur</span>
                @if($previewData['empty'] > 0)
                    <span class="csvi-stat csvi-stat-skip">{{ $previewData['empty'] }} ligne(s) vide(s) ignorée(s)</span>
                @endif
            </div>

            @if(count($previewData['rows']) > 0)
                @if(count($this->targetFields()) > 5)
                    <p class="csvi-scroll-hint">
                        Tableau plus large que l'écran : faites-le défiler horizontalement.
                        La première colonne reste visible.
                    </p>
                @endif
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
                                            {{ ($row['repeat'] ?? false) ? 'répété dans le fichier' : ($row['duplicate'] ? 'doublon' : 'nouveau') }}
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
                    @if($previewData['errors_total'] > count($previewData['errors']))
                        &mdash; les {{ count($previewData['errors']) }} premières sur
                        {{ $previewData['errors_total'] }}.
                    @endif
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
