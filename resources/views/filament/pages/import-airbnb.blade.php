<x-filament-panels::page>
    <form wire:submit="import">
        {{ $this->form }}

        <div class="mt-4">
            <x-filament::button type="submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="import">Importer</span>
                <span wire:loading wire:target="import">Import en cours...</span>
            </x-filament::button>
        </div>
    </form>

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

    <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl p-6 border border-blue-200 dark:border-blue-800">
        <h4 class="font-medium text-blue-800 dark:text-blue-200 mb-2">Formats supportés</h4>
        <ul class="text-sm text-blue-700 dark:text-blue-300 space-y-1">
            <li>• Export Airbnb « Historique des transactions » (CSV)</li>
            <li>• Colonnes : Date, Amount/Montant, Host fee, Confirmation code, Guest, Start date</li>
            <li>• Les doublons (même code de confirmation) sont ignorés automatiquement</li>
            <li>• Les montants négatifs (remboursements) sont ignorés</li>
        </ul>
    </div>
</x-filament-panels::page>
