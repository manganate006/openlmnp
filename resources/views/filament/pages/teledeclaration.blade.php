<x-filament-panels::page>
    <style>
        .td-card { background: var(--fi-body-bg, white); border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border: 1px solid var(--fi-border-color, #e5e7eb); margin-bottom: 16px; }
        .td-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .td-table th, .td-table td { padding: 8px 12px; border-bottom: 1px solid var(--fi-border-color, #e5e7eb); }
        .td-table th { background: var(--fi-bg-muted, #f9fafb); text-align: left; font-weight: 600; font-size: 12px; }
        .td-table .form-cell { color: var(--fi-fg-muted, #6b7280); font-size: 11px; white-space: nowrap; }
        .td-table .form-cell a { color: #6366f1; text-decoration: none; font-weight: 600; }
        .td-table .form-cell a:hover { text-decoration: underline; color: #4f46e5; }
        .td-table .line-cell { font-weight: 700; color: #065f46; white-space: nowrap; }
        .td-table .value-cell { text-align: right; font-family: monospace; font-weight: 600; }
        .td-table .copy-btn { cursor: pointer; background: #ecfdf5; color: #065f46; border: 1px solid #86efac; border-radius: 4px; padding: 2px 8px; font-size: 11px; margin-left: 8px; }
        .td-table .copy-btn:hover { background: #d1fae5; }
        .td-select { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
        .td-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .td-export { padding: 8px 16px; background: #10b981; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; }
        .td-export:hover { background: #059669; }
        .td-guide { font-size: 13px; color: var(--fi-fg, #374151); line-height: 1.8; }
        .td-guide ol { padding-left: 20px; }
        .td-guide li { margin-bottom: 8px; }
        .td-guide strong { color: #065f46; }
        .td-step { display: inline-block; background: #d1fae5; color: #065f46; border-radius: 50%; width: 24px; height: 24px; text-align: center; line-height: 24px; font-weight: 700; font-size: 12px; margin-right: 8px; }
    </style>

    <script>
        function copyValue(text) {
            navigator.clipboard.writeText(text).then(() => {
                const btn = event.target;
                const original = btn.textContent;
                btn.textContent = '✓';
                setTimeout(() => btn.textContent = original, 1500);
            });
        }
    </script>

    <div>
        @php $data = $this->declarationData; @endphp

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

            {{-- Tableau récapitulatif --}}
            <div class="td-card">
                <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;">Valeurs à déclarer — Exercice {{ $year }}</h3>
                <p style="font-size:12px;color:#6b7280;margin-bottom:16px;">Cliquez sur « Copier » pour copier une valeur dans le presse-papier, puis collez-la dans le formulaire en ligne.</p>

                <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
                <table class="td-table">
                    <thead>
                        <tr>
                            <th>Formulaire</th>
                            <th>Ligne</th>
                            <th>Description</th>
                            <th style="text-align:right;">Montant</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($data['lines'] as $line)
                            <tr>
                                <td class="form-cell">
                                    @php
                                        $cerfaUrls = [
                                            '2031' => 'https://www.impots.gouv.fr/sites/default/files/formulaires/2031-sd/2026/2031-sd_5396.pdf',
                                            '2033-A' => 'https://www.impots.gouv.fr/sites/default/files/formulaires/2033-sd/2026/2033-sd_5394.pdf',
                                            '2033-B' => 'https://www.impots.gouv.fr/sites/default/files/formulaires/2033-sd/2026/2033-sd_5394.pdf',
                                            '2033-C' => 'https://www.impots.gouv.fr/sites/default/files/formulaires/2033-sd/2026/2033-sd_5394.pdf',
                                            '2042-C-PRO' => 'https://www.impots.gouv.fr/sites/default/files/formulaires/2042/2026/2042_5474.pdf',
                                        ];
                                        $url = $cerfaUrls[$line['form']] ?? null;
                                    @endphp
                                    @if($url)
                                        <a href="{{ $url }}" target="_blank" title="Voir le formulaire {{ $line['form'] }} sur impots.gouv.fr">{{ $line['form'] }}</a>
                                    @else
                                        {{ $line['form'] }}
                                    @endif
                                </td>
                                <td class="line-cell">{{ $line['line'] }}</td>
                                <td>{{ $line['desc'] }}</td>
                                <td class="value-cell">{{ $line['value'] }} €</td>
                                <td><button class="copy-btn" onclick="copyValue('{{ number_format($line['raw'] / 100, 2, '.', '') }}')">Copier</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>

            {{-- Guide EFI --}}
            <div class="td-card">
                <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;">Option 1 : Saisie en ligne sur impots.gouv.fr (gratuit)</h3>
                <div class="td-guide">
                    <ol>
                        <li><span class="td-step">1</span>Connectez-vous sur <strong>impots.gouv.fr</strong> → Espace professionnel</li>
                        <li><span class="td-step">2</span>Si c'est votre 1ère déclaration, créez votre espace pro avec votre <strong>SIREN</strong></li>
                        <li><span class="td-step">3</span>Menu <strong>« Déclarer »</strong> → <strong>« Résultat »</strong> (BIC réel simplifié)</li>
                        <li><span class="td-step">4</span>Remplissez le formulaire <strong>2033-B</strong> (compte de résultat) en reportant les valeurs ci-dessus ligne par ligne</li>
                        <li><span class="td-step">5</span>Remplissez le formulaire <strong>2033-A</strong> (bilan) et <strong>2033-C</strong> (immobilisations)</li>
                        <li><span class="td-step">6</span>Validez et transmettez</li>
                        <li><span class="td-step">7</span>Sur votre <strong>déclaration de revenus personnelle</strong> (2042), allez dans <strong>2042-C-PRO</strong> et reportez le résultat en case <strong>{{ $data['lines'][count($data['lines'])-1]['line'] }}</strong></li>
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
                        <li><span class="td-step">2</span>Choisissez <strong>« Liasse fiscale BIC-RSI »</strong></li>
                        <li><span class="td-step">3</span>Saisissez votre SIREN et les valeurs du tableau ci-dessus</li>
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
                    case <strong>{{ $data['lines'][count($data['lines'])-1]['line'] }}</strong> :
                    <strong>{{ $data['lines'][count($data['lines'])-1]['value'] }} €</strong>
                </p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
