<x-filament-panels::page>
    <div class="space-y-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border">
            <h3 class="text-lg font-semibold mb-4">Import CSV Airbnb</h3>
            <p class="text-sm text-gray-500 mb-6">
                Exportez vos revenus depuis Airbnb (Historique des transactions → Exporter en CSV)
                puis importez le fichier ici. Les doublons sont détectés automatiquement via le code de confirmation.
            </p>

            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Bien concerné</label>
                    <select wire:model="property_id" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800">
                        <option value="">Sélectionner un bien...</option>
                        @foreach(\App\Models\Property::all() as $property)
                            <option value="{{ $property->id }}">{{ $property->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Fichier CSV</label>
                    <input type="file" wire:model="csv_file" accept=".csv,.txt" class="w-full rounded-lg border-gray-300">
                </div>

                <button wire:click="import" wire:loading.attr="disabled"
                    class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="import">Importer</span>
                    <span wire:loading wire:target="import">Import en cours...</span>
                </button>
            </div>
        </div>

        @if($lastResult)
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border">
                <h3 class="text-lg font-semibold mb-4">Résultat de l'import</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-emerald-50 dark:bg-emerald-900/20 rounded-lg p-4">
                        <p class="text-2xl font-bold text-emerald-700">{{ $lastResult['imported'] }}</p>
                        <p class="text-sm text-emerald-600">Recettes importées</p>
                    </div>
                    <div class="bg-amber-50 dark:bg-amber-900/20 rounded-lg p-4">
                        <p class="text-2xl font-bold text-amber-700">{{ $lastResult['skipped'] }}</p>
                        <p class="text-sm text-amber-600">Lignes ignorées</p>
                    </div>
                </div>
                @if(!empty($lastResult['errors']))
                    <div class="mt-4 p-3 bg-red-50 dark:bg-red-900/20 rounded-lg">
                        <p class="text-sm font-medium text-red-700 mb-2">Erreurs :</p>
                        <ul class="text-sm text-red-600 list-disc pl-5">
                            @foreach($lastResult['errors'] as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif

        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl p-6 border border-blue-200">
            <h4 class="font-medium text-blue-800 dark:text-blue-200 mb-2">Formats supportés</h4>
            <ul class="text-sm text-blue-700 dark:text-blue-300 space-y-1">
                <li>• Export Airbnb « Historique des transactions » (CSV)</li>
                <li>• Colonnes : Date, Amount/Montant, Host fee, Confirmation code, Guest, Start date</li>
                <li>• Les doublons (même code de confirmation) sont ignorés automatiquement</li>
                <li>• Les montants négatifs (remboursements) sont ignorés</li>
            </ul>
        </div>
    </div>
</x-filament-panels::page>
