<div>
    {{-- Bouton flottant aide --}}
    <button
        wire:click="toggle"
        class="fixed bottom-6 right-6 z-40 flex h-12 w-12 items-center justify-center rounded-full bg-emerald-600 text-white shadow-lg transition-all hover:scale-110 hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
        aria-label="Aide contextuelle"
        title="Aide contextuelle"
    >
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-6 w-6">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
        </svg>
    </button>

    {{-- Backdrop --}}
    @if($open)
        <div
            wire:click="toggle"
            class="fixed inset-0 z-40 bg-black/30 transition-opacity"
        ></div>
    @endif

    {{-- Panneau latéral --}}
    <div
        @keydown.escape.window="if ($wire.open) $wire.toggle()"
        class="fixed right-0 top-0 z-50 flex h-full w-full flex-col bg-white shadow-2xl transition-transform duration-300 ease-in-out dark:bg-gray-900 sm:w-96 {{ $open ? 'translate-x-0' : 'translate-x-full' }}"
    >
        {{-- Header --}}
        <div class="flex flex-shrink-0 items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-700">
            <div class="flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5 text-emerald-600">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
                </svg>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $pageTitle }}</h2>
            </div>
            <button
                wire:click="toggle"
                class="rounded-lg p-1 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                aria-label="Fermer l'aide"
            >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        {{-- Contenu --}}
        <div class="flex-1 overflow-y-auto px-5 py-5">
            @include('help._styles')
            @include($helpView)
        </div>

        {{-- Footer --}}
        <div class="flex-shrink-0 border-t border-gray-200 px-5 py-3 text-center dark:border-gray-700">
            <a
                href="{{ \App\Filament\Pages\HelpPage::getUrl() }}"
                wire:navigate
                class="text-sm text-emerald-600 hover:text-emerald-700 hover:underline dark:text-emerald-400 dark:hover:text-emerald-300"
            >
                Voir le guide complet
            </a>
        </div>
    </div>
</div>
