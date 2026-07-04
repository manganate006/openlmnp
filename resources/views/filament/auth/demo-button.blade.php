@if (config('demo.enabled'))
    <div class="mt-4">
        {{-- Séparateur « ou » --}}
        <div class="relative flex items-center py-2">
            <div class="flex-grow border-t border-gray-200 dark:border-white/10"></div>
            <span class="mx-4 flex-shrink text-sm text-gray-400 dark:text-gray-500">ou</span>
            <div class="flex-grow border-t border-gray-200 dark:border-white/10"></div>
        </div>

        {{-- Bouton « Découvrir la démo » (Emerald, pleine largeur) --}}
        <a
            href="{{ route('demo.start') }}"
            class="fi-btn fi-btn-size-md relative flex w-full items-center justify-center gap-1.5 rounded-lg px-4 py-2 text-sm font-semibold text-emerald-600 outline-none ring-1 ring-emerald-600/30 transition duration-75 hover:bg-emerald-50 focus-visible:ring-2 focus-visible:ring-emerald-600 dark:text-emerald-400 dark:ring-emerald-400/30 dark:hover:bg-emerald-400/10"
        >
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M6.3 2.84A1.5 1.5 0 0 0 4 4.11v11.78a1.5 1.5 0 0 0 2.3 1.27l9.34-5.89a1.5 1.5 0 0 0 0-2.54L6.3 2.84Z" />
            </svg>
            Découvrir la démo
        </a>
    </div>
@endif
