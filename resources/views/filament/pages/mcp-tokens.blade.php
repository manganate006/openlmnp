<x-filament-panels::page>
    @if($this->newPlainToken)
        <div class="rounded-xl bg-warning-50 dark:bg-warning-950 border border-warning-300 dark:border-warning-700 p-6 space-y-4">
            <div class="flex items-center gap-2">
                <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-warning-600" />
                <h3 class="text-lg font-semibold text-warning-800 dark:text-warning-200">
                    Votre nouveau token
                </h3>
            </div>
            <p class="text-sm text-warning-700 dark:text-warning-300">
                Copiez ce token maintenant. Il ne sera plus affiché.
            </p>
            <div class="flex items-center gap-2">
                <code class="flex-1 bg-white dark:bg-gray-900 px-4 py-3 rounded-lg font-mono text-sm break-all select-all border">{{ $this->newPlainToken }}</code>
                <button
                    type="button"
                    onclick="navigator.clipboard.writeText('{{ $this->newPlainToken }}').then(() => { this.innerText = 'Copié !' ; setTimeout(() => this.innerText = 'Copier', 2000) })"
                    class="fi-btn fi-btn-size-md px-4 py-2 rounded-lg bg-primary-600 text-white hover:bg-primary-500 transition text-sm font-medium"
                >
                    Copier
                </button>
            </div>
            <div class="flex justify-end">
                <button
                    type="button"
                    wire:click="dismissToken"
                    class="text-sm text-gray-500 hover:text-gray-700 underline"
                >
                    J'ai copié mon token
                </button>
            </div>
        </div>
    @endif

    {{-- Tokens existants --}}
    <div class="space-y-4">
        <h3 class="text-lg font-semibold">Vos tokens API</h3>

        @php $tokens = $this->getTokens(); @endphp

        @if(count($tokens) === 0)
            <div class="text-sm text-gray-500 dark:text-gray-400 py-8 text-center">
                Aucun token API. Créez-en un pour connecter un client MCP.
            </div>
        @else
            <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300">Nom</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300">Dernière utilisation</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300">Créé le</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-600 dark:text-gray-300">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($tokens as $token)
                            <tr>
                                <td class="px-4 py-3 font-medium">{{ $token['name'] }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $token['last_used_at'] }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $token['created_at'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        type="button"
                                        wire:click="revokeToken({{ $token['id'] }})"
                                        wire:confirm="Voulez-vous vraiment révoquer ce token ?"
                                        class="text-danger-600 hover:text-danger-500 text-sm font-medium"
                                    >
                                        Révoquer
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Configuration Claude Desktop --}}
    <div class="space-y-4">
        <h3 class="text-lg font-semibold">Configuration pour Claude Desktop</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Ajoutez cette configuration dans votre fichier <code class="bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 rounded text-xs">claude_desktop_config.json</code> :
        </p>
        <div class="relative">
            <pre class="bg-gray-50 dark:bg-gray-900 border rounded-xl p-4 text-sm font-mono overflow-x-auto"><code>{{ $this->getConfigSnippet() }}</code></pre>
            <button
                type="button"
                onclick="navigator.clipboard.writeText({{ json_encode($this->getConfigSnippet()) }}).then(() => { this.innerText = 'Copié !' ; setTimeout(() => this.innerText = 'Copier', 2000) })"
                class="absolute top-3 right-3 px-3 py-1 rounded-lg bg-gray-200 dark:bg-gray-700 text-xs font-medium hover:bg-gray-300 dark:hover:bg-gray-600 transition"
            >
                Copier
            </button>
        </div>
    </div>
</x-filament-panels::page>
