<x-filament-panels::page>
    <style>
        .td-card { background: var(--olmnp-surface); border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border: 1px solid var(--olmnp-border); margin-bottom: 16px; }
        .td-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .td-table th, .td-table td { padding: 8px 12px; border-bottom: 1px solid var(--olmnp-border); }
        .td-table th { background: var(--olmnp-surface-muted); text-align: left; font-weight: 600; font-size: 12px; }
        .td-table .line-cell { font-weight: 700; color: var(--olmnp-success-fg); white-space: nowrap; }
        .td-table .value-cell { text-align: right; font-family: monospace; font-weight: 600; color: var(--olmnp-fg-strong); }
        .td-table .copy-btn { cursor: pointer; background: var(--olmnp-success-bg); color: var(--olmnp-success-fg); border: 1px solid var(--olmnp-success-border); border-radius: 4px; padding: 2px 8px; font-size: 11px; margin-left: 8px; }
        .td-table .copy-btn:hover { background: var(--olmnp-success-bg-strong); }
        .td-select { padding: 6px 10px; border: 1px solid var(--olmnp-border-strong); border-radius: 8px; font-size: 14px; }
        .td-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 8px; }
        .td-export { padding: 8px 16px; background: var(--olmnp-success-solid); color: var(--olmnp-on-solid); border: none; border-radius: 8px; cursor: pointer; font-size: 14px; }
        .td-export:hover { background: var(--olmnp-success-solid-hover); }
        .td-guide { font-size: 13px; color: var(--olmnp-fg); line-height: 1.8; }
        .td-guide ol { padding-left: 20px; }
        .td-guide li { margin-bottom: 8px; }
        .td-guide strong { color: var(--olmnp-success-fg); }
        .td-step { display: inline-block; background: var(--olmnp-success-bg-strong); color: var(--olmnp-success-fg); border-radius: 50%; width: 24px; height: 24px; text-align: center; line-height: 24px; font-weight: 700; font-size: 12px; margin-right: 8px; }

        .td-section { margin-bottom: 16px; }
        .td-section summary {
            cursor: pointer; padding: 14px 20px; font-size: 15px; font-weight: 600;
            background: var(--olmnp-surface); border: 1px solid var(--olmnp-border);
            border-radius: 12px; list-style: none; display: flex; align-items: center; gap: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06); transition: background .15s;
        }
        .td-section summary:hover { background: var(--olmnp-surface-muted); }
        .td-section summary::-webkit-details-marker { display: none; }
        .td-section summary::before { content: '\25B6'; font-size: 10px; color: var(--olmnp-fg-subtle); transition: transform .2s; }
        .td-section[open] summary::before { transform: rotate(90deg); }
        .td-section[open] summary { border-radius: 12px 12px 0 0; border-bottom: none; }
        .td-section .td-section-body {
            border: 1px solid var(--olmnp-border); border-top: none;
            border-radius: 0 0 12px 12px; padding: 16px; background: var(--olmnp-surface);
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
        }
        .td-cerfa { font-size: 11px; color: var(--olmnp-fg-muted); font-weight: 400; margin-left: 8px; }
        .td-pdf-link { color: var(--olmnp-accent-accent); text-decoration: none; margin-left: auto; font-size: 12px; display: flex; align-items: center; gap: 4px; }
        .td-pdf-link:hover { text-decoration: underline; color: var(--olmnp-accent-fg); }
        .td-check { font-size: 12px; padding: 4px 10px; border-radius: 6px; margin-top: 8px; display: inline-block; }
        .td-check-ok { background: var(--olmnp-success-bg); color: var(--olmnp-success-fg); }
        .td-check-ko { background: var(--olmnp-danger-bg); color: var(--olmnp-danger-fg); }
        .td-check-warn { background: var(--olmnp-warning-bg); color: var(--olmnp-warning-fg); }
        .td-breakdown-note { font-size: 12px; color: var(--olmnp-fg-muted); margin-bottom: 12px; }

        /* Cadre E (2031 bis-SD) et cartes 2042-C-PRO par indivisaire — maquette écran 3, variante B. */
        .td-table tfoot td { font-weight: 700; border-top: 2px solid var(--olmnp-border-strong); border-bottom: none; }
        .td-identity-sub { display: block; font-size: 11px; color: var(--olmnp-fg-muted); margin-top: 2px; }
        .td-inline-check { display: flex; align-items: flex-start; gap: 8px; margin-top: 12px; padding: 10px 12px; border-radius: 8px; font-size: 13px; }
        .td-inline-check.td-check-ok { border: 1px solid var(--olmnp-success-border); }
        .td-inline-check.td-check-warn { border: 1px solid var(--olmnp-warning-border); }
        .td-inline-check.td-check-ko { border: 1px solid var(--olmnp-danger-border); }
        .td-person-cards { display: flex; flex-direction: column; gap: 10px; }
        .td-person-card { display: flex; align-items: center; gap: 14px; padding: 12px 14px; border-radius: 10px; background: var(--olmnp-surface-muted); border: 1px solid var(--olmnp-border); flex-wrap: wrap; }
        .td-person-avatar { flex: none; width: 34px; height: 34px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; background: var(--olmnp-accent-bg); color: var(--olmnp-accent-fg); border: 1px solid var(--olmnp-accent-border); }
        .td-person-name { font-weight: 600; color: var(--olmnp-fg-strong); }
        .td-person-share { font-size: 12px; color: var(--olmnp-fg-muted); }
        .td-person-case { flex: none; display: flex; flex-direction: column; align-items: center; justify-content: center; width: 52px; height: 42px; border-radius: 8px; background: var(--olmnp-surface); border: 1px solid var(--olmnp-border-strong); font-weight: 700; font-size: 13px; color: var(--olmnp-success-fg); }
        .td-person-case small { font-size: 9px; font-weight: 500; color: var(--olmnp-fg-muted); text-transform: uppercase; }
        .td-person-amount-label { font-size: 11px; color: var(--olmnp-fg-muted); }
        .td-person-amount { font-weight: 700; font-family: monospace; color: var(--olmnp-fg-strong); }
        .td-person-copy { margin-left: auto; }
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
                <p style="font-size:18px;color:var(--olmnp-fg-muted);">Ajoutez un bien et des recettes pour préparer la télédéclaration.</p>
            </div>
        @else
            <div class="td-header">
                <div>
                    <label style="font-size:14px;color:var(--olmnp-fg);">Exercice :</label>
                    <select wire:model.live="year" class="td-select">
                        @for($y = date('Y') + 1; $y >= date('Y') - 3; $y--)
                            <option value="{{ $y }}">{{ $y }}</option>
                        @endfor
                    </select>
                    <span style="font-size:12px;color:var(--olmnp-fg-muted);margin-left:12px;">SIREN : {{ $data['siren'] }}</span>
                </div>
                <button wire:click="exportCsv" class="td-export">Exporter CSV</button>
            </div>

            <p style="font-size:12px;color:var(--olmnp-fg-muted);margin-bottom:16px;">Cliquez sur « Copier » pour copier une valeur dans le presse-papier, puis collez-la dans le formulaire en ligne.</p>

            {{-- Dépôt enregistré : la seule marque qui distingue une liasse déposée d'une liasse
                 imprimée. Saisie par l'action « Dépôt » de l'en-tête, jamais déduite. --}}
            @if($this->fiscalYearForYear?->transmitted_at)
                <div class="td-card" style="margin-bottom:16px;background:var(--olmnp-success-bg);border-color:var(--olmnp-success-border);">
                    <p style="font-size:14px;color:var(--olmnp-success-accent);">
                        <strong>{{ $this->fiscalYearForYear->transmissionSummary() }}</strong>
                        Les montants ci-dessous sont recalculés à chaque affichage : s'ils ont changé depuis,
                        la liasse déposée ne les porte pas.
                    </p>
                </div>
            @endif

            @if($this->showsDeficitCorrectionNotice)
                <div class="td-card" style="margin-bottom:16px;background:var(--olmnp-warning-bg);border-color:var(--olmnp-warning-border);">
                    <h3 style="font-size:15px;font-weight:600;color:var(--olmnp-warning-fg);margin-bottom:8px;">Le tableau 2033-D a changé de règle</h3>
                    <p style="font-size:14px;color:var(--olmnp-warning-fg);margin-bottom:8px;">
                        Jusqu'à la version 1.3.2, les cases <strong>982</strong>, <strong>983</strong> et <strong>984</strong>
                        — qui suivent vos <strong>déficits reportables</strong> — recevaient en réalité le montant de vos
                        <strong>amortissements réputés différés</strong>. Ce sont deux choses différentes : l'amortissement
                        différé se reporte sans limite de durée, le déficit s'impute sur les dix années suivantes.
                    </p>
                    <p style="font-size:14px;color:var(--olmnp-warning-fg);">
                        Les liasses que vous avez déjà téléchargées ou transmises portent l'ancienne valeur : elles affichent
                        des déficits que vous n'aviez pas. Depuis la 2.0, ce cadre est laissé vide, comme le prévoit la
                        notice pour une location meublée non professionnelle. Si une
                        déclaration déjà déposée est concernée, votre suivi de déficits peut être reconstitué
                        (<code>php artisan openlmnp:repair-deficits</code>), puis la liasse régénérée.
                    </p>
                </div>
            @endif

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
                                        <tr @if($line['line'] === '490' || $line['line'] === '572') style="font-weight:700;border-top:2px solid var(--olmnp-border);" @endif>
                                            <td class="line-cell">{{ $line['line'] }}</td>
                                            <td>{{ $line['desc'] }}</td>
                                            <td class="value-cell">{{ $line['value'] }} &euro;</td>
                                            <td><button class="copy-btn" onclick="copyValue('{{ number_format($line['raw'] / 100, 0, '.', '') }}')">Copier</button></td>
                                            @if(isset($line['dotation']))
                                                <td class="value-cell">{{ $line['dotation'] }} &euro;</td>
                                                <td><button class="copy-btn" onclick="copyValue('{{ number_format($line['dotation_raw'] / 100, 0, '.', '') }}')">Copier</button></td>
                                            @else
                                                <td class="value-cell">—</td>
                                                <td></td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            </div>
                        @elseif($formKey === '2031-bis')
                            {{-- Cadre E tel qu'imprimé (maquette écran 3, variante B) : les colonnes du
                                 Cerfa (indivisaire, qualité, BIC, quote-part, bénéfice, déficit) plutôt
                                 qu'une description à plat, et une ligne de total qui prouve que la somme
                                 des parts retombe sur le résultat de la 2031-SD. --}}
                            <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
                            <table class="td-table">
                                <thead>
                                    <tr>
                                        <th>Indivisaire</th>
                                        <th>Qualité</th>
                                        <th>BIC</th>
                                        <th style="text-align:right;">Quote-part</th>
                                        <th style="text-align:right;">Bénéfice</th>
                                        <th style="text-align:right;">Déficit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($form['rows'] as $row)
                                        <tr>
                                            <td>{{ $row['name'] }}<span class="td-identity-sub">{{ $row['identity'] }}</span></td>
                                            <td>{{ $row['quality'] }}</td>
                                            <td>{{ $row['bic_type'] }}</td>
                                            <td class="value-cell">{{ $row['share_pct'] }}</td>
                                            <td class="value-cell">{{ $row['benefice'] !== '—' ? $row['benefice'] . ' €' : '—' }}</td>
                                            <td class="value-cell">{{ $row['deficit'] !== '—' ? $row['deficit'] . ' €' : '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="3">Total (= 2031-SD, case CB/CC)</td>
                                        <td class="value-cell">{{ $form['total']['share_pct'] }}</td>
                                        <td class="value-cell">{{ $form['total']['benefice'] }} &euro;</td>
                                        <td class="value-cell">{{ $form['total']['deficit'] }} &euro;</td>
                                    </tr>
                                </tfoot>
                            </table>
                            </div>
                            {{-- Contrôle de la répartition, à côté du tableau qu'il vérifie — le même
                                 contrôle (venu de `checks()`) figure aussi plus bas, dans les « Contrôles
                                 de cohérence » : pas recalculé ici, seulement répété. --}}
                            @foreach($data['checks'] as $check)
                                @continue($check['id'] !== 'indivision')
                                <div class="td-inline-check
                                    @if($check['status'] === \App\Services\TaxReturnService::CHECK_OK) td-check-ok
                                    @elseif($check['status'] === \App\Services\TaxReturnService::CHECK_WARNING) td-check-warn
                                    @else td-check-ko
                                    @endif
                                ">{{ $check['message'] }}</div>
                            @endforeach
                        @elseif($formKey === '2042-C-PRO' && !empty($form['persons']))
                            {{-- Une carte par indivisaire (maquette écran 3, variante B) : SA case, SON
                                 montant, sur SA propre déclaration de revenus — jamais une ligne de
                                 tableau qui pourrait laisser croire à un report commun. --}}
                            <div class="td-person-cards">
                                @foreach($form['persons'] as $person)
                                    <div class="td-person-card">
                                        <span class="td-person-avatar" aria-hidden="true">{{ $person['initials'] }}</span>
                                        <div>
                                            <div class="td-person-name">{{ $person['name'] }}</div>
                                            <div class="td-person-share">Quote-part {{ $person['share_pct'] }} · sur sa propre déclaration de revenus</div>
                                        </div>
                                        <span class="td-person-case"><small>case</small>{{ $person['case'] }}</span>
                                        <div>
                                            <div class="td-person-amount-label">{{ $person['label'] }}</div>
                                            <div class="td-person-amount">{{ $person['value'] }} &euro;</div>
                                        </div>
                                        <button class="copy-btn td-person-copy" onclick="copyValue('{{ number_format($person['raw'] / 100, 0, '.', '') }}')">Copier</button>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            {{-- Tableau standard --}}
                            @if(! empty($form['note']))
                                <p style="font-size:13px;color:var(--olmnp-fg-muted);margin-bottom:8px;">{{ $form['note'] }}</p>
                            @endif
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
                                            <td><button class="copy-btn" onclick="copyValue('{{ number_format($line['raw'] / 100, 0, '.', '') }}')">Copier</button></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            </div>
                        @endif
                    </div>
                </details>
            @endforeach

            {{-- Contrôles de cohérence entre formulaires.
                 Rendus depuis TaxReturnService::checks(), la même source que le PDF : aucune
                 comparaison n'est refaite ici, sinon les deux écrans finiraient par diverger. --}}
            <div class="td-card" style="margin-top:24px;">
                <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;">Contrôles de cohérence</h3>
                {{-- Classes écrites en toutes lettres, jamais interpolées : `PanelStylesheetTest`
                     ne valide que les classes statiques, une classe construite lui échapperait. --}}
                @foreach($data['checks'] as $check)
                    <div>
                        @if($check['status'] === \App\Services\TaxReturnService::CHECK_OK)
                            <span class="td-check td-check-ok">{{ $check['message'] }}</span>
                        @elseif($check['status'] === \App\Services\TaxReturnService::CHECK_WARNING)
                            <span class="td-check td-check-warn">{{ $check['message'] }}</span>
                        @else
                            <span class="td-check td-check-ko">{{ $check['message'] }}</span>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Détail des immobilisations : d'où vient chaque ligne du cadre I du 2033-C.
                 Rendu depuis TaxReturnService::assetBreakdown(), la même source que les
                 tableaux ci-dessus et que le PDF. Né de l'issue #11 : une ligne agrégée ne
                 dit pas ce qu'elle contient, et un montant qu'on ne peut pas remonter à sa
                 source est un montant qu'on ne peut pas vérifier. --}}
            <details class="td-section" style="margin-top:24px;">
                <summary>Détail des immobilisations <span class="td-cerfa">d'où vient chaque ligne du 2033-C</span></summary>
                <div class="td-section-body">
                    <p class="td-breakdown-note">
                        Rien à recopier ici : ce tableau explique les lignes du 2033-C ci-dessus.
                        Les composants ventilent la valeur du bien, ils ne s'y ajoutent pas — la
                        colonne « Valeur brute » ne se totalise donc pas.
                    </p>
                    <table class="td-table">
                        <thead>
                            <tr>
                                <th>Immobilisation</th>
                                <th>Origine</th>
                                <th>Ligne 2033-C</th>
                                <th style="text-align:right;">Valeur brute</th>
                                <th style="text-align:right;">Dotation</th>
                                <th style="text-align:right;">Amort. cumulé</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($data['breakdown'] as $row)
                                <tr>
                                    <td>{{ $row['name'] }}</td>
                                    <td>{{ $row['origin'] }}</td>
                                    <td class="line-cell">{{ $row['cerfa'] }}</td>
                                    <td class="value-cell">{{ number_format($row['base'] / 100, 2, ',', ' ') }} €</td>
                                    <td class="value-cell">{{ number_format($row['annual'] / 100, 2, ',', ' ') }} €</td>
                                    <td class="value-cell">{{ number_format($row['cumul'] / 100, 2, ',', ' ') }} €</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>

            {{-- Guide EFI --}}
            <div class="td-card" style="margin-top:24px;">
                <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;">Option 1 : Saisie en ligne sur impots.gouv.fr (gratuit)</h3>
                <div class="td-guide">
                    <ol>
                        <li><span class="td-step">1</span>Connectez-vous sur <strong>impots.gouv.fr</strong> &rarr; Espace professionnel</li>
                        <li><span class="td-step">2</span>Si c'est votre 1ère déclaration, créez votre espace pro avec votre <strong>SIREN</strong></li>
                        <li><span class="td-step">3</span>Menu <strong>&laquo; Déclarer &raquo;</strong> &rarr; <strong>&laquo; Résultat &raquo;</strong> (BIC réel simplifié)</li>
                        <li><span class="td-step">4</span>Remplissez les formulaires <strong>2033-B</strong>, <strong>2033-A</strong>, <strong>2033-C</strong> et <strong>2033-D</strong> en reportant les valeurs ci-dessus ligne par ligne{!! $data['form2031bis'] ? ', puis l\'annexe <strong>2031 bis</strong> (cadre E : un indivisaire par ligne, avec sa quote-part)' : '' !!}</li>
                        <li><span class="td-step">5</span>Validez et transmettez</li>
                        @if($data['form2031bis'])
                        <li><span class="td-step">6</span>Chaque indivisaire, sur sa <strong>déclaration de revenus personnelle</strong> (2042), reporte <strong>sa quote-part</strong> en <strong>2042-C-PRO</strong> (montants ci-dessous)</li>
                        @else
                        <li><span class="td-step">6</span>Sur votre <strong>déclaration de revenus personnelle</strong> (2042), allez dans <strong>2042-C-PRO</strong> et reportez le résultat en case <strong>{{ $data['forms']['2042-C-PRO']['lines'][0]['line'] }}</strong></li>
                        @endif
                        <li><span class="td-step">7</span>De retour ici, cliquez sur <strong>&laquo; Dépôt &raquo;</strong> en haut de l'écran et notez la date et le numéro du <strong>certificat de dépôt</strong> : sans lui, rien ne distingue cette liasse d'un brouillon</li>
                    </ol>
                    <p style="font-size:12px;color:var(--olmnp-fg-muted);margin-top:8px;">Date limite : 2ème jour ouvré après le 1er mai (environ 18-20 mai selon les années).</p>
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
                        <li><span class="td-step">6</span>Notez l'accusé ici, bouton <strong>&laquo; Dépôt &raquo;</strong> en haut de l'écran</li>
                    </ol>
                    <p style="font-size:12px;color:var(--olmnp-fg-muted);margin-top:8px;">Avantage : transmission officielle EDI-TDFC avec accusé de réception.</p>
                </div>
            </div>

            {{-- Rappel 2042-C-PRO --}}
            <div class="td-card" style="background:var(--olmnp-success-bg);border-color:var(--olmnp-success-border);">
                <h3 style="font-size:16px;font-weight:600;color:var(--olmnp-success-fg);margin-bottom:8px;">Ne pas oublier : déclaration de revenus personnelle</h3>
                @if($data['form2031bis'])
                    {{-- Indivision : chacun reporte SA quote-part (2031 bis-SD), pas le résultat entier. --}}
                    <p style="font-size:14px;color:var(--olmnp-success-accent);">
                        Après avoir transmis la liasse de l'indivision (2031, 2031 bis et 2033), chaque indivisaire
                        reporte <strong>sa quote-part</strong> sur sa propre <strong>déclaration de revenus 2042</strong>,
                        rubrique <strong>2042-C-PRO</strong> :
                    </p>
                    @if($data['form2031bis']['complete'])
                        <ul style="font-size:14px;color:var(--olmnp-success-accent);margin-top:6px;padding-left:18px;list-style:disc;">
                            @foreach($data['forms']['2042-C-PRO']['lines'] as $line)
                                <li>{{ $line['desc'] }} : case <strong>{{ $line['line'] }}</strong>, <strong>{{ $line['value'] }} &euro;</strong></li>
                            @endforeach
                        </ul>
                    @else
                        <p style="font-size:14px;color:var(--olmnp-warning-fg);margin-top:6px;">
                            La répartition entre indivisaires est incomplète : complétez-la dans votre profil, rubrique « Indivision ».
                        </p>
                    @endif
                @else
                <p style="font-size:14px;color:var(--olmnp-success-accent);">
                    Après avoir transmis la liasse fiscale (2031 + 2033), reportez le résultat fiscal sur votre
                    <strong>déclaration de revenus personnelle 2042</strong>, rubrique <strong>2042-C-PRO</strong>,
                    case <strong>{{ $data['forms']['2042-C-PRO']['lines'][0]['line'] }}</strong> :
                    <strong>{{ $data['forms']['2042-C-PRO']['lines'][0]['value'] }} &euro;</strong>
                </p>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
