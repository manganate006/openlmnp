<x-filament-panels::page>
    <style>
        .aiadm-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
        .aiadm-stat { background: var(--olmnp-surface); border: 1px solid var(--olmnp-border); border-radius: 12px; padding: 16px; }
        .aiadm-stat-val { font-size: 24px; font-weight: 700; }
        .aiadm-stat-label { font-size: 12px; color: var(--olmnp-fg-muted); margin-top: 2px; }
        .aiadm-report { border-top: 1px solid var(--olmnp-border); padding: 8px 0; font-size: 13px; }
        .aiadm-report summary { cursor: pointer; }
        .aiadm-card { background: var(--olmnp-surface); border: 1px solid var(--olmnp-border); border-radius: 12px; padding: 20px; margin-top: 16px; }
        .aiadm-card h3 { font-size: 15px; font-weight: 700; margin: 0 0 12px; }
        .aiadm-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .aiadm-table th { background: var(--olmnp-surface-alt); font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; }
        .aiadm-table th, .aiadm-table td { padding: 8px 12px; border-bottom: 1px solid var(--olmnp-border); text-align: left; }
        .aiadm-table td.num, .aiadm-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .aiadm-mono { font-family: ui-monospace, monospace; font-size: 12px; }
        .aiadm-empty { font-size: 13px; color: var(--olmnp-fg-muted); }
        .aiadm-bar { height: 8px; background: var(--olmnp-success-solid); border-radius: 4px; }
        .aiadm-note { font-size: 12px; color: var(--olmnp-fg-muted); margin-top: 10px; }
    </style>

    @php($stats = $this->getStats())
    @php($config = $this->aiConfiguration())

    <div class="aiadm-grid">
        <div class="aiadm-stat">
            <div class="aiadm-stat-val">{{ $stats['documents_month'] }}</div>
            <div class="aiadm-stat-label">Documents analysés ce mois</div>
        </div>
        <div class="aiadm-stat">
            <div class="aiadm-stat-val">{{ $stats['chat_month'] }}</div>
            <div class="aiadm-stat-label">Messages d'assistant ce mois</div>
        </div>
        <div class="aiadm-stat">
            <div class="aiadm-stat-val">{{ number_format((float) $stats['cost_month'], 2, ',', "\u{202F}") }} $</div>
            <div class="aiadm-stat-label">Coût du mois (réel, fournisseur)</div>
        </div>
        <div class="aiadm-stat">
            <div class="aiadm-stat-val">
                {{ $stats['cost_per_document'] === null ? '—' : number_format((float) $stats['cost_per_document'], 4, ',', "\u{202F}") . ' $' }}
            </div>
            <div class="aiadm-stat-label">Coût moyen par document (cascade comprise)</div>
        </div>
        <div class="aiadm-stat">
            <div class="aiadm-stat-val">{{ $stats['active_users'] }}</div>
            <div class="aiadm-stat-label">Comptes actifs ce mois</div>
        </div>
        <div class="aiadm-stat">
            <div class="aiadm-stat-val">{{ $stats['errors_month'] }}</div>
            <div class="aiadm-stat-label">Erreurs ce mois</div>
        </div>
        <div class="aiadm-stat">
            <div class="aiadm-stat-val">{{ $stats['quota_month'] }}</div>
            <div class="aiadm-stat-label">Refus pour quota atteint</div>
        </div>
        <div class="aiadm-stat">
            <div class="aiadm-stat-val">{{ $stats['refused_month'] }}</div>
            <div class="aiadm-stat-label">Refus des garde-fous</div>
        </div>
        <div class="aiadm-stat">
            <div class="aiadm-stat-val">{{ $stats['avg_duration_ms'] ?? '—' }}{{ $stats['avg_duration_ms'] ? ' ms' : '' }}</div>
            <div class="aiadm-stat-label">Durée moyenne d'un appel</div>
        </div>
        <div class="aiadm-stat">
            <div class="aiadm-stat-val">{{ number_format((float) $stats['cost_total'], 2, ',', "\u{202F}") }} $</div>
            <div class="aiadm-stat-label">Coût cumulé (rétention {{ $config['retention_days'] }} j)</div>
        </div>
    </div>

    <div class="aiadm-card">
        <h3>Configuration effective</h3>
        <table class="aiadm-table">
            <tbody>
                <tr><td>Fournisseur</td><td>{{ $config['provider'] }}</td></tr>
                <tr><td>Service</td><td class="aiadm-mono">{{ $config['base_url'] }}</td></tr>
                <tr><td>Clé</td><td>{{ $config['key_source'] }}</td></tr>
                <tr><td>Modèle — documents</td><td class="aiadm-mono">{{ $config['model_documents'] }}</td></tr>
                <tr><td>Modèle — repli</td><td class="aiadm-mono">{{ $config['model_fallback'] }}</td></tr>
                <tr><td>Modèle — assistant</td><td class="aiadm-mono">{{ $config['model_chat'] }}</td></tr>
                <tr><td>Moteur PDF</td><td class="aiadm-mono">{{ $config['pdf_engine'] }}</td></tr>
                <tr><td>Bascule vers le repli sous</td><td>{{ number_format($config['fallback_confidence'] * 100, 0) }} % de confiance</td></tr>
                <tr><td>Bouton de réanalyse proposé sous</td><td>{{ number_format($config['reanalysis_threshold'] * 100, 0) }} % de confiance</td></tr>
                <tr><td>Quota documents / mois</td><td>{{ $config['docs_quota'] }}</td></tr>
                <tr><td>Quota assistant / jour</td><td>{{ $config['chat_quota'] }}</td></tr>
            </tbody>
        </table>
        <p class="aiadm-note">
            La clé n'est jamais affichée, ni ici, ni ailleurs. Le journal ne contient aucun contenu de
            document : uniquement des compteurs, des durées et des coûts.
        </p>
    </div>

    @php($assistant = $this->getAssistantStats())
    <div class="aiadm-card">
        <h3>Assistant conversationnel (mois en cours)</h3>
        <div class="aiadm-grid">
            <div class="aiadm-stat">
                <div class="aiadm-stat-val">{{ $assistant['questions'] }}</div>
                <div class="aiadm-stat-label">Questions</div>
            </div>
            <div class="aiadm-stat">
                <div class="aiadm-stat-val">{{ $assistant['refused'] }}</div>
                <div class="aiadm-stat-label">Refus des garde-fous ({{ $assistant['refused_share'] }} % des questions)</div>
            </div>
            <div class="aiadm-stat">
                <div class="aiadm-stat-val">{{ $assistant['thumbs_up'] }} / {{ $assistant['thumbs_down'] }}</div>
                <div class="aiadm-stat-label">Pouces positifs / négatifs</div>
            </div>
            <div class="aiadm-stat">
                <div class="aiadm-stat-val">{{ $assistant['tool_calls'] }}</div>
                <div class="aiadm-stat-label">Outils consultés</div>
            </div>
            <div class="aiadm-stat">
                <div class="aiadm-stat-val">{{ $assistant['quota_hits'] }}</div>
                <div class="aiadm-stat-label">Quotas atteints</div>
            </div>
            <div class="aiadm-stat">
                <div class="aiadm-stat-val">{{ $assistant['errors'] }}</div>
                <div class="aiadm-stat-label">Erreurs fournisseur</div>
            </div>
            <div class="aiadm-stat">
                <div class="aiadm-stat-val">{{ number_format((float) $assistant['cost_usd'], 4, ',', "\u{202F}") }} $</div>
                <div class="aiadm-stat-label">Coût de l'assistant (questions et outils)</div>
            </div>
        </div>
        <p class="aiadm-note">
            Une question = une unité de quota, quel que soit le nombre d'appels d'outils. Les refus
            comptent les réponses remplacées par le garde-fou (conseil fiscal, hors périmètre).
        </p>
    </div>

    @php($volumes = $this->getDailyVolumes())
    @if($volumes)
        @php($max = max(array_column($volumes, 'total')))
        <div class="aiadm-card">
            <h3>Appels par jour (30 derniers jours)</h3>
            <table class="aiadm-table">
                <tbody>
                    @foreach(array_reverse($volumes) as $jour)
                        <tr>
                            <td style="width: 110px;">{{ \Carbon\Carbon::parse($jour['jour'])->format('d/m/Y') }}</td>
                            <td class="num" style="width: 60px;">{{ $jour['total'] }}</td>
                            <td>
                                <div class="aiadm-bar" style="width: {{ $max > 0 ? round($jour['total'] / $max * 100) : 0 }}%;"></div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @php($modeles = $this->getByModel())
    <div class="aiadm-card">
        <h3>Répartition par fonction et modèle</h3>
        @if($modeles)
            <table class="aiadm-table">
                <thead>
                    <tr>
                        <th>Fonction</th>
                        <th>Modèle</th>
                        <th>Servi par</th>
                        <th class="num">Appels</th>
                        <th class="num">Durée moyenne</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($modeles as $ligne)
                        <tr>
                            <td>{{ $ligne['feature'] }}</td>
                            <td class="aiadm-mono">{{ $ligne['model'] }}</td>
                            <td class="aiadm-mono">{{ $ligne['upstream'] ?? '—' }}</td>
                            <td class="num">{{ $ligne['total'] }}</td>
                            <td class="num">{{ $ligne['avg_ms'] ? $ligne['avg_ms'] . ' ms' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="aiadm-note">
                « documents_auto » regroupe les appels de suivi automatiques (réparation d'une réponse mal
                formée, cascade vers le modèle renforcé). Ils comptent dans le coût, jamais dans le quota
                de l'utilisateur.
            </p>
        @else
            <p class="aiadm-empty">Aucun appel enregistré.</p>
        @endif
    </div>

    @php($erreurs = $this->getErrors())
    <div class="aiadm-card">
        <h3>Erreurs du mois</h3>
        @if($erreurs)
            <table class="aiadm-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th class="num">Occurrences</th>
                        <th>Dernière</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($erreurs as $erreur)
                        <tr>
                            <td class="aiadm-mono">{{ $erreur['error_code'] }}</td>
                            <td class="num">{{ $erreur['total'] }}</td>
                            <td>{{ $erreur['derniere'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="aiadm-empty">Aucune erreur ce mois-ci.</p>
        @endif
    </div>

    @php($topUsers = $this->getTopUsers())
    <div class="aiadm-card">
        <h3>Consommation par compte (mois en cours)</h3>
        @if($topUsers)
            <table class="aiadm-table">
                <thead>
                    <tr>
                        <th>Compte</th>
                        <th class="num">Appels</th>
                        <th class="num">Documents</th>
                        <th class="num">Coût</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($topUsers as $ligne)
                        <tr>
                            <td>{{ $ligne['name'] }} <span class="aiadm-empty">— {{ $ligne['email'] }}</span></td>
                            <td class="num">{{ $ligne['total'] }}</td>
                            <td class="num">{{ $ligne['documents'] }}</td>
                            <td class="num">{{ number_format((float) $ligne['cost'], 4, ',', "\u{202F}") }} $</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="aiadm-empty">Aucune consommation ce mois-ci.</p>
        @endif
    </div>

    @php($signalements = $this->getChatReports())
    <div class="aiadm-card">
        <h3>Conversations signalées</h3>
        <p class="aiadm-empty">Transmises par les utilisateurs sur un pouce vers le bas, montants, noms et adresses masqués. Purgées après {{ \App\Models\AiChatReport::RETENTION_DAYS }} jours.</p>
        @forelse($signalements as $signalement)
            <details class="aiadm-report">
                <summary>{{ $signalement['date'] }} · {{ $signalement['reason'] }} · {{ $signalement['route'] }} · <span class="aiadm-mono">{{ $signalement['model'] }}</span></summary>
                @foreach($signalement['transcript'] as $tour)
                    <p><strong>{{ $tour['role'] === 'user' ? 'Utilisateur' : 'Assistant' }} :</strong> {!! nl2br(e($tour['text'])) !!}</p>
                @endforeach
            </details>
        @empty
            <p class="aiadm-empty">Aucune conversation transmise.</p>
        @endforelse
    </div>
</x-filament-panels::page>
