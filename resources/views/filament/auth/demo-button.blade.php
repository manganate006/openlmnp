@if (config('demo.enabled'))
    <div class="mt-6">
        {{-- Séparateur « ou » --}}
        <div class="relative flex items-center py-2">
            <div class="flex-grow border-t border-gray-200 dark:border-white/10"></div>
            <span class="mx-4 flex-shrink text-sm text-gray-400 dark:text-gray-500">ou</span>
            <div class="flex-grow border-t border-gray-200 dark:border-white/10"></div>
        </div>

        {{-- Carte démo (accent émeraude, inspirée du méga-menu vitrine, sans GIF) --}}
        <a
            href="{{ route('demo.start') }}"
            aria-label="Essayer la démonstration OpenLMNP dans un sandbox sans inscription"
            class="group flex items-start gap-3 rounded-xl border border-emerald-100 bg-emerald-50 p-4 outline-none transition hover:border-emerald-300 hover:shadow-md focus-visible:ring-2 focus-visible:ring-emerald-600 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:hover:border-emerald-500/40 dark:focus-visible:ring-emerald-400"
        >
            <span class="mt-0.5 flex h-9 w-9 flex-none items-center justify-center rounded-lg bg-emerald-600 text-white dark:bg-emerald-500">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M6.3 2.84A1.5 1.5 0 0 0 4 4.11v11.78a1.5 1.5 0 0 0 2.3 1.27l9.34-5.89a1.5 1.5 0 0 0 0-2.54L6.3 2.84Z" />
                </svg>
            </span>
            <span class="min-w-0 flex-1">
                <span class="flex items-center gap-2 font-semibold text-emerald-700 dark:text-emerald-300">
                    <span class="h-2 w-2 flex-none rounded-full bg-emerald-500"></span>
                    Essayez la démo
                </span>
                <span class="mt-0.5 block text-xs text-emerald-700/70 dark:text-emerald-300/70">
                    Sandbox sans inscription, données d'exemple
                </span>
            </span>
            <span class="flex-none self-center text-emerald-600 transition group-hover:translate-x-0.5 dark:text-emerald-400" aria-hidden="true">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M7 17L17 7M9 7h8v8" />
                </svg>
            </span>
        </a>
    </div>
@endif

@if ($websiteUrl = config('services.website.url'))
    <p class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
        Pas encore de compte ?
        <a
            href="{{ $websiteUrl }}"
            target="_blank"
            rel="noopener"
            class="font-semibold text-emerald-600 outline-none hover:text-emerald-500 hover:underline focus-visible:rounded focus-visible:ring-2 focus-visible:ring-emerald-600 dark:text-emerald-400 dark:focus-visible:ring-emerald-400"
        >
            Découvrir OpenLMNP
        </a>
    </p>
@endif
