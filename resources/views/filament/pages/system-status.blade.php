<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Informations système --}}
        @php $info = $this->getSystemInfo(); @endphp

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border">
                <p class="text-xs text-gray-500">PHP</p>
                <p class="text-lg font-bold">{{ $info['php_version'] }}</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border">
                <p class="text-xs text-gray-500">Laravel</p>
                <p class="text-lg font-bold">{{ $info['laravel_version'] }}</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border">
                <p class="text-xs text-gray-500">Filament</p>
                <p class="text-lg font-bold">{{ $info['filament_version'] }}</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border">
                <p class="text-xs text-gray-500">Uptime</p>
                <p class="text-lg font-bold">{{ $info['uptime'] }}</p>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border text-center">
                <p class="text-2xl font-bold text-emerald-600">{{ $info['users_count'] }}</p>
                <p class="text-xs text-gray-500">Utilisateurs</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border text-center">
                <p class="text-2xl font-bold text-emerald-600">{{ $info['properties_count'] }}</p>
                <p class="text-xs text-gray-500">Biens</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border text-center">
                <p class="text-2xl font-bold text-emerald-600">{{ $info['incomes_count'] }}</p>
                <p class="text-xs text-gray-500">Recettes</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border text-center">
                <p class="text-2xl font-bold text-emerald-600">{{ $info['expenses_count'] }}</p>
                <p class="text-xs text-gray-500">Charges</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border text-center">
                <p class="text-2xl font-bold text-emerald-600">{{ $info['fiscal_years_count'] }}</p>
                <p class="text-xs text-gray-500">Exercices</p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border">
                <p class="text-xs text-gray-500">Base de données ({{ $info['db_driver'] }})</p>
                <p class="text-lg font-bold">{{ $info['db_size'] }}</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border">
                <p class="text-xs text-gray-500">Espace disque libre</p>
                <p class="text-lg font-bold">{{ $info['storage_free'] }}</p>
            </div>
        </div>

        {{-- Tests automatisés --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold">Tests automatisés</h3>
                <button
                    wire:click="runTests"
                    wire:loading.attr="disabled"
                    wire:target="runTests"
                    class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 disabled:opacity-50 gap-2"
                >
                    <span wire:loading.remove wire:target="runTests">
                        <x-heroicon-o-play class="w-4 h-4" /> Lancer les tests
                    </span>
                    <span wire:loading wire:target="runTests">
                        <svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        Tests en cours...
                    </span>
                </button>
            </div>

            @if($testResults)
                {{-- Résumé --}}
                <div class="mb-4 p-4 rounded-lg {{ $testResults['success'] ? 'bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200' : 'bg-red-50 dark:bg-red-900/20 border border-red-200' }}">
                    <div class="flex items-center gap-3">
                        @if($testResults['success'])
                            <x-heroicon-o-check-circle class="w-8 h-8 text-emerald-600" />
                            <div>
                                <p class="text-lg font-bold text-emerald-800 dark:text-emerald-200">Tous les tests passent</p>
                                <p class="text-sm text-emerald-600">{{ $testResults['summary']['passed'] }} tests réussis — {{ $testResults['ran_at'] }}</p>
                            </div>
                        @else
                            <x-heroicon-o-x-circle class="w-8 h-8 text-red-600" />
                            <div>
                                <p class="text-lg font-bold text-red-800 dark:text-red-200">Des tests ont échoué</p>
                                <p class="text-sm text-red-600">
                                    {{ $testResults['summary']['passed'] }} réussis,
                                    {{ $testResults['summary']['failed'] }} échoués
                                    — {{ $testResults['ran_at'] }}
                                </p>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Barres de progression --}}
                @if($testResults['summary']['total'] > 0)
                    @php
                        $total = $testResults['summary']['total'];
                        $passedPct = round($testResults['summary']['passed'] / $total * 100);
                    @endphp
                    <div class="mb-4">
                        <div class="flex justify-between text-sm mb-1">
                            <span>{{ $testResults['summary']['passed'] }} / {{ $total }} tests</span>
                            <span>{{ $passedPct }}%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-3">
                            <div class="h-3 rounded-full {{ $testResults['success'] ? 'bg-emerald-500' : 'bg-red-500' }}" style="width: {{ $passedPct }}%"></div>
                        </div>
                    </div>
                @endif

                {{-- Output détaillé --}}
                <details class="mt-4">
                    <summary class="cursor-pointer text-sm text-gray-500 hover:text-gray-700">Voir le détail des tests</summary>
                    <pre class="mt-2 p-4 bg-gray-900 text-gray-100 rounded-lg text-xs overflow-x-auto max-h-96">{{ $testResults['output'] }}</pre>
                </details>
            @else
                <p class="text-gray-500 text-sm">Cliquez sur « Lancer les tests » pour vérifier que tout fonctionne correctement.</p>
                <div class="mt-3 text-xs text-gray-400">
                    <p>Les tests vérifient :</p>
                    <ul class="list-disc pl-5 mt-1 space-y-1">
                        <li>Calculs d'amortissement par composant</li>
                        <li>Résultat fiscal et plafonnement</li>
                        <li>Tableau d'amortissement emprunt</li>
                        <li>Import CSV Airbnb (formats FR/EN)</li>
                        <li>Fichier des Écritures Comptables (FEC)</li>
                        <li>Accès à toutes les pages</li>
                        <li>Isolation des données entre utilisateurs</li>
                    </ul>
                </div>
            @endif
        </div>

        <div class="text-center text-xs text-gray-400">
            OpenLMNP v0.1 — Laravel {{ $info['laravel_version'] }} — Filament {{ $info['filament_version'] }}
        </div>
    </div>
</x-filament-panels::page>
