<x-filament-panels::page>
    <style>
        .td-card { background: var(--fi-body-bg, white); border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border: 1px solid var(--fi-border-color, #e5e7eb); margin-bottom: 16px; }
        .td-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .td-table th, .td-table td { padding: 8px 12px; border-bottom: 1px solid var(--fi-border-color, #e5e7eb); }
        .td-table th { background: var(--fi-bg-muted, #f9fafb); text-align: left; font-weight: 600; font-size: 12px; }
        .td-table .line-cell { font-weight: 700; color: #065f46; white-space: nowrap; }
        .td-table .value-cell { text-align: right; font-family: monospace; font-weight: 600; }
        .td-table .copy-btn { cursor: pointer; background: #ecfdf5; color: #065f46; border: 1px solid #86efac; border-radius: 4px; padding: 2px 8px; font-size: 11px; margin-left: 8px; }
        .td-table .copy-btn:hover { background: #d1fae5; }
        .td-select { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
        .td-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 8px; }
        .td-export { padding: 8px 16px; background: #10b981; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; }
        .td-export:hover { background: #059669; }
        .td-guide { font-size: 13px; color: var(--fi-fg, #374151); line-height: 1.8; }
        .td-guide ol { padding-left: 20px; }
        .td-guide li { margin-bottom: 8px; }
        .td-guide strong { color: #065f46; }
        .td-step { display: inline-block; background: #d1fae5; color: #065f46; border-radius: 50%; width: 24px; height: 24px; text-align: center; line-height: 24px; font-weight: 700; font-size: 12px; margin-right: 8px; }

        .td-section { margin-bottom: 16px; }
        .td-section summary {
            cursor: pointer; padding: 14px 20px; font-size: 15px; font-weight: 600;
            background: var(--fi-body-bg, white); border: 1px solid var(--fi-border-color, #e5e7eb);
            border-radius: 12px; list-style: none; display: flex; align-items: center; gap: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06); transition: background .15s;
        }
        .td-section summary:hover { background: var(--fi-bg-muted, #f9fafb); }
        .td-section summary::-webkit-details-marker { display: none; }
        .td-section summary::before { content: '\25B6'; font-size: 10px; color: #9ca3af; transition: transform .2s; }
        .td-section[open] summary::before { transform: rotate(90deg); }
        .td-section[open] summary { border-radius: 12px 12px 0 0; border-bottom: none; }
        .td-section .td-section-body {
            border: 1px solid var(--fi-border-color, #e5e7eb); border-top: none;
            border-radius: 0 0 12px 12px; padding: 16px; background: var(--fi-body-bg, white);
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
        }
        .td-cerfa { font-size: 11px; color: #6b7280; font-weight: 400; margin-left: 8px; }
        .td-pdf-link { color: #6366f1; text-decoration: none; margin-left: auto; font-size: 12px; display: flex; align-items: center; gap: 4px; }
        .td-pdf-link:hover { text-decoration: underline; color: #4f46e5; }
        .td-check { font-size: 12px; padding: 4px 10px; border-radius: 6px; margin-top: 8px; display: inline-block; }
        .td-check-ok { background: #ecfdf5; color: #065f46; }
        .td-check-ko { background: #fef2f2; color: #991b1b; }
    </style>

    <script>
        function copyValue(text) {
            navigator.clipboard.writeText(text).then(() => {
                const btn = event.target;
                const original = btn.textContent;
                btn.textContent = '\u2713';
                setTimeout(() => btn.textContent = original, 1500);
            });
        }
    </script>

    <div>
        @php
            $data = $this->declarationData;

            $cerfaPages = [
                '2031' => 'https://www.impots.gouv.fr/formulaire/2031-sd/impot-sur-le-revenu',
                '2033-A' => 'https://www.impots.gouv.fr/formulaire/2033-sd/liasse-bicsi-regime-rsi-tableaux-ndeg-2033-sd-2033-g-sd',
                '2033-B' => 'https://www.impots.gouv.fr/formulaire/2033-sd/liasse-bicsi-regime-rsi-tableaux-ndeg-2033-sd-2033-g-sd',
                '2033-C' => 'https://www.impots.gouv.fr/formulaire/2033-sd/liasse-bicsi-regime-rsi-tableaux-ndeg-2033-sd-2033-g-sd',
                '2033-D' => 'https://www.impots.gouv.fr/formulaire/2033-sd/liasse-bicsi-regime-rsi-tableaux-ndeg-2033-sd-2033-g-sd',
                '2042-C-PRO' => 'https://www.impots.gouv.fr/formulaire/2042/declaration-des-revenus',
            ];
            $cerfaPdfs = [
                '2031' => 'https://www.impots.gouv.fr/sites/default/files/formulaires/2031-sd/2026/2031-sd_5396.pdf',
                '2033-A' => 'https://www.impots.gouv.fr/sites/default/files/formulaires/2033-sd/2026/2033-sd_5394.pdf',
                '2033-B' => 'https://www.impots.gouv.fr/sites/default/files/formulaires/2033-sd/2026/2033-sd_5394.pdf',
                '2033-C' => 'https://www.impots.gouv.fr/sites/default/files/formulaires/2033-sd/2026/2033-sd_5394.pdf',
                '2033-D' => 'https://www.impots.gouv.fr/sites/default/files/formulaires/2033-sd/2026/2033-sd_5394.pdf',
                '2042-C-PRO' => 'https://www.impots.gouv.fr/sites/default/files/formulaires/2042/2026/2042_5474.pdf',
            ];
        @endphp

        @if(!$data)
            <div class="td-card" style="text-align:center;padding:48px;">
                <p style="font-size:18px;color:#6b7280;">Ajoutez un bien et des recettes pour préparer la télédéclaration.</p>
            </div>
        @else
            <div class="td-header">
                <div>
                    <label style="font-size:14px;color:#374151;">Exercice :</label>
                    <select wire:model.live="year" class="td-select">
                        @for($y = date('Y') + 1; $y >= date('Y') - 3; $y--)
                            <option value="{{ $y }}">{{ $y }}</option>
                        @endfor
                    </select>
                    <span style="font-size:12px;color:#6b7280;margin-left:12px;">SIREN : {{ $data['siren'] }}</span>
                </div>
                <button wire:click="exportCsv" class="td-export">Exporter CSV</button>
            </div>

            <p style="font-size:12px;color:#6b7280;margin-bottom:16px;">Cliquez sur « Copier » pour copier une valeur dans le presse-papier, puis collez-la dans le formulaire en ligne.</p>

            {{-- Sections par formulaire --}}
            @foreach($data['forms'] as $formKey => $form)
                <details class="td-section" @if($form['open']) open @endif>
                    <summary>
                        {{ $form['title'] }}
                        <span class="td-cerfa">{{ $form['cerfa'] }}</span>
                        @if(isset($cerfaPdfs[$formKey]))
                            <a href="{{ $cerfaPdfs[$formKey] }}" target="_blank" class="td-pdf-link" onclick="event.stopPropagation();">
                                <x-heroicon-o-document-arrow-down style="width:16px;height:16px;" />
                                PDF officiel
                            </a>
                        @endif
                    </summary>
                    <div class="td-section-body">
                        @if($formKey === '2033-C')
                            {{-- Tableau spécial 2033-C avec colonnes brut + dotation --}}
                            <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
                            <table class="td-table">
                                <thead>
                                    <tr>
                                        <th>Lignes</th>
                                        <th>Catégorie</th>
                                        <th style="text-align:right;">Valeur brute</th>
                                        <th></th>
                                        <th style="text-align:right;">Dotation exercice</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($form['lines'] as $line)
                                        <tr @if($line['line'] === '490' || $line['line'] === '572') style="font-weight:700;border-top:2px solid var(--fi-border-color, #d1d5db);" @endif>
                                            <td class="line-cell">{{ $line['line'] }}</td>
                                            <td>{{ $line['desc'] }}</td>
                                            <td class="value-cell">{{ $line['value'] }} &euro;</td>
                                            <td><button class="copy-btn" onclick="copyValue('{{ number_format($line['raw'] / 100, 2, '.', '') }}')">Copier</button></td>
                                            @if(isset($line['dotation']))
                                                <td class="value-cell">{{ $line['dotation'] }} &euro;</td>
                                                <td><button class="copy-btn" onclick="copyValue('{{ number_format($line['dotation_raw'] / 100, 2, '.', '') }}')">Copier</button></td>
                                            @else
                                                <td class="value-cell">—</td>
                                                <td></td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            </div>
                            @if(isset($form['check_572_254']))
                                @if($form['check_572_254'])
                                    <span class="td-check td-check-ok">Ligne 572 = ligne 254 du 2033-B</span>
                                @else
                                    <span class="td-check td-check-ko">Ligne 572 &ne; ligne 254 du 2033-B — vérifiez les amortissements</span>
                                @endif
                            @endif
                        @else
                            {{-- Tableau standard --}}
                            <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
                            <table class="td-table">
                                <thead>
                                    <tr>
                                        <th>Ligne</th>
                                        <th>Description</th>
                                        <th style="text-align:right;">Montant</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($form['lines'] as $line)
                                        <tr>
                                            <td class="line-cell">{{ $line['line'] }}</td>
                                            <td>{{ $line['desc'] }}</td>
                                            <td class="value-cell">{{ $line['value'] }} &euro;</td>
                                            <td><button class="copy-btn" onclick="copyValue('{{ number_format($line['raw'] / 100, 2, '.', '') }}')">Copier</button></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            </div>
                        @endif
                    </div>
                </details>
            @endforeach

            {{-- Guide EFI --}}
            <div class="td-card" style="margin-top:24px;">
                <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;">Option 1 : Saisie en ligne sur impots.gouv.fr (gratuit)</h3>
                <div class="td-guide">
                    <ol>
                        <li><span class="td-step">1</span>Connectez-vous sur <strong>impots.gouv.fr</strong> &rarr; Espace professionnel</li>
                        <li><span class="td-step">2</span>Si c'est votre 1ère déclaration, créez votre espace pro avec votre <strong>SIREN</strong></li>
                        <li><span class="td-step">3</span>Menu <strong>&laquo; Déclarer &raquo;</strong> &rarr; <strong>&laquo; Résultat &raquo;</strong> (BIC réel simplifié)</li>
                        <li><span class="td-step">4</span>Remplissez les formulaires <strong>2033-B</strong>, <strong>2033-A</strong>, <strong>2033-C</strong> et <strong>2033-D</strong> en reportant les valeurs ci-dessus ligne par ligne</li>
                        <li><span class="td-step">5</span>Validez et transmettez</li>
                        <li><span class="td-step">6</span>Sur votre <strong>déclaration de revenus personnelle</strong> (2042), allez dans <strong>2042-C-PRO</strong> et reportez le résultat en case <strong>{{ $data['forms']['2042-C-PRO']['lines'][0]['line'] }}</strong></li>
                    </ol>
                    <p style="font-size:12px;color:#6b7280;margin-top:8px;">Date limite : 2ème jour ouvré après le 1er mai (environ 18-20 mai selon les années).</p>
                </div>
            </div>

            {{-- Guide Teledec --}}
            <div class="td-card">
                <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;">Option 2 : Via Teledec.fr (à partir de 99 €/an)</h3>
                <div class="td-guide">
                    <ol>
                        <li><span class="td-step">1</span>Créez un compte sur <strong>teledec.fr</strong></li>
                        <li><span class="td-step">2</span>Choisissez <strong>&laquo; Liasse fiscale BIC-RSI &raquo;</strong></li>
                        <li><span class="td-step">3</span>Saisissez votre SIREN et les valeurs des formulaires ci-dessus</li>
                        <li><span class="td-step">4</span>Teledec transmet directement à la DGFiP via EDI-TDFC</li>
                        <li><span class="td-step">5</span>Vous recevez un accusé de réception</li>
                    </ol>
                    <p style="font-size:12px;color:#6b7280;margin-top:8px;">Avantage : transmission officielle EDI-TDFC avec accusé de réception.</p>
                </div>
            </div>

            {{-- Rappel 2042-C-PRO --}}
            <div class="td-card" style="background:#ecfdf5;border-color:#86efac;">
                <h3 style="font-size:16px;font-weight:600;color:#065f46;margin-bottom:8px;">Ne pas oublier : déclaration de revenus personnelle</h3>
                <p style="font-size:14px;color:#047857;">
                    Après avoir transmis la liasse fiscale (2031 + 2033), reportez le résultat fiscal sur votre
                    <strong>déclaration de revenus personnelle 2042</strong>, rubrique <strong>2042-C-PRO</strong>,
                    case <strong>{{ $data['forms']['2042-C-PRO']['lines'][0]['line'] }}</strong> :
                    <strong>{{ $data['forms']['2042-C-PRO']['lines'][0]['value'] }} &euro;</strong>
                </p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
