<x-filament-panels::page>
    <style>
        .ss-grid { display: grid; gap: 12px; }
        .ss-grid-4 { grid-template-columns: repeat(4, 1fr); }
        .ss-grid-5 { grid-template-columns: repeat(5, 1fr); }
        .ss-grid-2 { grid-template-columns: repeat(2, 1fr); }
        .ss-card { background: white; border-radius: 12px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border: 1px solid #e5e7eb; }
        .ss-card-label { font-size: 11px; color: #6b7280; }
        .ss-card-value { font-size: 18px; font-weight: 700; }
        .ss-card-center { text-align: center; }
        .ss-card-center .ss-card-value { font-size: 24px; color: #10b981; }
        .ss-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: #10b981; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 600; }
        .ss-btn:hover { background: #059669; }
        .ss-btn:disabled { opacity: 0.5; cursor: wait; }
        .ss-result-ok { background: #ecfdf5; border: 1px solid #86efac; border-radius: 12px; padding: 16px; display: flex; align-items: center; gap: 12px; }
        .ss-result-fail { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 12px; padding: 16px; display: flex; align-items: center; gap: 12px; }
        .ss-bar { width: 100%; background: #e5e7eb; border-radius: 6px; height: 12px; margin: 8px 0; }
        .ss-bar-fill { height: 12px; border-radius: 6px; }
        .ss-pre { margin-top: 12px; padding: 16px; background: #1f2937; color: #e5e7eb; border-radius: 8px; font-size: 11px; overflow-x: auto; max-height: 400px; font-family: monospace; white-space: pre-wrap; }
        .ss-list { font-size: 12px; color: #6b7280; list-style: disc; padding-left: 20px; line-height: 2; }
        @media (max-width: 768px) { .ss-grid-4, .ss-grid-5 { grid-template-columns: repeat(2, 1fr); } }
    </style>

    @php $info = $this->getSystemInfo(); @endphp

    <div>
        <div class="ss-grid ss-grid-4" style="margin-bottom: 16px;">
            <div class="ss-card"><div class="ss-card-label">PHP</div><div class="ss-card-value">{{ $info['php_version'] }}</div></div>
            <div class="ss-card"><div class="ss-card-label">Laravel</div><div class="ss-card-value">{{ $info['laravel_version'] }}</div></div>
            <div class="ss-card"><div class="ss-card-label">Filament</div><div class="ss-card-value">{{ $info['filament_version'] }}</div></div>
            <div class="ss-card"><div class="ss-card-label">Uptime</div><div class="ss-card-value">{{ $info['uptime'] }}</div></div>
        </div>

        <div class="ss-grid ss-grid-5" style="margin-bottom: 16px;">
            <div class="ss-card ss-card-center"><div class="ss-card-value">{{ $info['users_count'] }}</div><div class="ss-card-label">Utilisateurs</div></div>
            <div class="ss-card ss-card-center"><div class="ss-card-value">{{ $info['properties_count'] }}</div><div class="ss-card-label">Biens</div></div>
            <div class="ss-card ss-card-center"><div class="ss-card-value">{{ $info['incomes_count'] }}</div><div class="ss-card-label">Recettes</div></div>
            <div class="ss-card ss-card-center"><div class="ss-card-value">{{ $info['expenses_count'] }}</div><div class="ss-card-label">Charges</div></div>
            <div class="ss-card ss-card-center"><div class="ss-card-value">{{ $info['fiscal_years_count'] }}</div><div class="ss-card-label">Exercices</div></div>
        </div>

        <div class="ss-grid ss-grid-2" style="margin-bottom: 24px;">
            <div class="ss-card"><div class="ss-card-label">Base de données ({{ $info['db_driver'] }})</div><div class="ss-card-value">{{ $info['db_size'] }}</div></div>
            <div class="ss-card"><div class="ss-card-label">Espace disque libre</div><div class="ss-card-value">{{ $info['storage_free'] }}</div></div>
        </div>

        <div class="ss-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <h3 style="font-size:16px;font-weight:600;">Tests automatisés</h3>
                <button wire:click="runTests" wire:loading.attr="disabled" wire:target="runTests" class="ss-btn">
                    <span wire:loading.remove wire:target="runTests">Lancer les tests</span>
                    <span wire:loading wire:target="runTests">Tests en cours...</span>
                </button>
            </div>

            @if($testResults)
                @if($testResults['success'])
                    <div class="ss-result-ok">
                        <svg xmlns="http://www.w3.org/2000/svg" style="width:32px;height:32px;color:#10b981;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        <div>
                            <div style="font-size:16px;font-weight:700;color:#065f46;">Tous les tests passent</div>
                            <div style="font-size:13px;color:#047857;">{{ $testResults['summary']['passed'] }} tests réussis — {{ $testResults['ran_at'] }}</div>
                        </div>
                    </div>
                @else
                    <div class="ss-result-fail">
                        <svg xmlns="http://www.w3.org/2000/svg" style="width:32px;height:32px;color:#dc2626;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        <div>
                            <div style="font-size:16px;font-weight:700;color:#991b1b;">Des tests ont échoué</div>
                            <div style="font-size:13px;color:#dc2626;">{{ $testResults['summary']['passed'] }} réussis, {{ $testResults['summary']['failed'] }} échoués — {{ $testResults['ran_at'] }}</div>
                        </div>
                    </div>
                @endif

                @if($testResults['summary']['total'] > 0)
                    @php $pct = round($testResults['summary']['passed'] / $testResults['summary']['total'] * 100); @endphp
                    <div style="display:flex;justify-content:space-between;font-size:13px;margin-top:12px;">
                        <span>{{ $testResults['summary']['passed'] }} / {{ $testResults['summary']['total'] }} tests</span>
                        <span>{{ $pct }}%</span>
                    </div>
                    <div class="ss-bar"><div class="ss-bar-fill" style="width:{{ $pct }}%;background:{{ $testResults['success'] ? '#10b981' : '#ef4444' }};"></div></div>
                @endif

                <details style="margin-top:12px;">
                    <summary style="cursor:pointer;font-size:13px;color:#6b7280;">Voir le détail</summary>
                    <pre class="ss-pre">{{ $testResults['output'] }}</pre>
                </details>
            @else
                <p style="font-size:13px;color:#6b7280;">Cliquez sur « Lancer les tests » pour vérifier que tout fonctionne.</p>
                <ul class="ss-list">
                    <li>Calculs d'amortissement par composant</li>
                    <li>Résultat fiscal et plafonnement</li>
                    <li>Tableau d'amortissement emprunt</li>
                    <li>Import CSV Airbnb (FR/EN)</li>
                    <li>Fichier des Écritures Comptables (FEC)</li>
                    <li>Accès à toutes les pages</li>
                    <li>Isolation des données entre utilisateurs</li>
                </ul>
            @endif
        </div>

        <div style="text-align:center;font-size:11px;color:#9ca3af;padding:16px;">
            OpenLMNP v0.1 — Laravel {{ $info['laravel_version'] }} — Filament {{ $info['filament_version'] }}
        </div>
    </div>
</x-filament-panels::page>
