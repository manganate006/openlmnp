<x-filament-panels::page>
    @if(!$previewData)
        {{-- Étape 1 : Upload --}}
        <form wire:submit="preview">
            {{ $this->form }}

            <div class="mt-4">
                <x-filament::button type="submit" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="preview">Aperçu avant import</span>
                    <span wire:loading wire:target="preview">Analyse en cours...</span>
                </x-filament::button>
            </div>
        </form>
    @else
        {{-- Étape 2 : Preview --}}
        <div class="space-y-4">
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold">Aperçu de l'import</h3>
                    <div class="flex items-center gap-3 text-sm">
                        @php
                            $importable = collect($previewData['rows'])->where('duplicate', false)->count();
                            $duplicates = collect($previewData['rows'])->where('duplicate', true)->count();
                        @endphp
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400 font-medium">
                            {{ $importable }} à importer
                        </span>
                        @if($duplicates > 0)
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 font-medium">
                                {{ $duplicates }} doublon(s)
                            </span>
                        @endif
                        @if($previewData['skipped'] > 0)
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 font-medium">
                                {{ $previewData['skipped'] }} ignorée(s)
                            </span>
                        @endif
                    </div>
                </div>

                @if(!empty($previewData['errors']))
                    <div class="mb-4 p-3 bg-red-50 dark:bg-red-900/20 rounded-lg">
                        <p class="text-sm font-medium text-red-700 dark:text-red-400 mb-1">Erreurs :</p>
                        <ul class="text-sm text-red-600 dark:text-red-400 list-disc pl-5">
                            @foreach($previewData['errors'] as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if(count($previewData['rows']) > 0)
                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-700/50">
                                    <th class="px-4 py-2.5 text-left font-medium text-gray-600 dark:text-gray-300">Date</th>
                                    <th class="px-4 py-2.5 text-left font-medium text-gray-600 dark:text-gray-300">Voyageur</th>
                                    <th class="px-4 py-2.5 text-left font-medium text-gray-600 dark:text-gray-300">Confirmation</th>
                                    <th class="px-4 py-2.5 text-left font-medium text-gray-600 dark:text-gray-300">Check-in</th>
                                    <th class="px-4 py-2.5 text-right font-medium text-gray-600 dark:text-gray-300">Montant</th>
                                    <th class="px-4 py-2.5 text-right font-medium text-gray-600 dark:text-gray-300">Commission</th>
                                    <th class="px-4 py-2.5 text-center font-medium text-gray-600 dark:text-gray-300">Statut</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                @foreach($previewData['rows'] as $row)
                                    <tr class="{{ $row['duplicate'] ? 'bg-amber-50/50 dark:bg-amber-900/10' : '' }}">
                                        <td class="px-4 py-2.5 text-gray-900 dark:text-gray-100">{{ $row['date'] }}</td>
                                        <td class="px-4 py-2.5 text-gray-900 dark:text-gray-100">{{ $row['guest'] ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400 font-mono text-xs">{{ $row['confirmation'] ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-gray-900 dark:text-gray-100">{{ $row['checkin'] ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-right font-medium text-gray-900 dark:text-gray-100">{{ number_format($row['amount'] / 100, 2, ',', ' ') }} €</td>
                                        <td class="px-4 py-2.5 text-right text-gray-500 dark:text-gray-400">{{ number_format($row['host_fee'] / 100, 2, ',', ' ') }} €</td>
                                        <td class="px-4 py-2.5 text-center">
                                            @if($row['duplicate'])
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400">
                                                    Doublon
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400">
                                                    Nouveau
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">Aucune ligne importable trouvée dans le fichier.</p>
                @endif
            </div>

            <div class="flex items-center gap-3">
                @if($importable > 0)
                    <x-filament::button wire:click="confirmImport" wire:loading.attr="disabled" color="success">
                        <span wire:loading.remove wire:target="confirmImport">Confirmer l'import ({{ $importable }} recette{{ $importable > 1 ? 's' : '' }})</span>
                        <span wire:loading wire:target="confirmImport">Import en cours...</span>
                    </x-filament::button>
                @endif
                <x-filament::button wire:click="cancelPreview" color="gray" outlined>
                    Annuler
                </x-filament::button>
            </div>
        </div>
    @endif

    @if($lastResult)
        <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-semibold mb-4">Résultat de l'import</h3>
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-emerald-50 dark:bg-emerald-900/20 rounded-lg p-4">
                    <p class="text-2xl font-bold text-emerald-700 dark:text-emerald-400">{{ $lastResult['imported'] }}</p>
                    <p class="text-sm text-emerald-600 dark:text-emerald-500">Recettes importées</p>
                </div>
                <div class="bg-amber-50 dark:bg-amber-900/20 rounded-lg p-4">
                    <p class="text-2xl font-bold text-amber-700 dark:text-amber-400">{{ $lastResult['skipped'] }}</p>
                    <p class="text-sm text-amber-600 dark:text-amber-500">Lignes ignorées</p>
                </div>
            </div>
            @if(!empty($lastResult['errors']))
                <div class="mt-4 p-3 bg-red-50 dark:bg-red-900/20 rounded-lg">
                    <p class="text-sm font-medium text-red-700 dark:text-red-400 mb-2">Erreurs :</p>
                    <ul class="text-sm text-red-600 dark:text-red-400 list-disc pl-5">
                        @foreach($lastResult['errors'] as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif

    @if(!$previewData)
        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl p-6 border border-blue-200 dark:border-blue-800">
            <h4 class="font-medium text-blue-800 dark:text-blue-200 mb-2">Formats supportés</h4>
            <ul class="text-sm text-blue-700 dark:text-blue-300 space-y-1">
                <li>• Export Airbnb « Historique des transactions » (CSV)</li>
                <li>• Colonnes : Date, Amount/Montant, Host fee, Confirmation code, Guest, Start date</li>
                <li>• Les doublons (même code de confirmation) sont détectés automatiquement</li>
                <li>• Les montants négatifs (remboursements) sont ignorés</li>
            </ul>
        </div>
    @endif
</x-filament-panels::page>
