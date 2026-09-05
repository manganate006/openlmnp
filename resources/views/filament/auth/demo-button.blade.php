{{--
    Blocs injectés sous le formulaire de connexion (render hook AUTH_LOGIN_FORM_AFTER).

    ⚠️ Le CSS servi par le panel Filament ne contient AUCUN utilitaire Tailwind
    (h-5, mt-6, bg-emerald-50… n'existent pas : le panel n'a pas de viteTheme, cf.
    commit c10a7c6f). Toute la mise en forme passe donc par ce <style> scopé, comme
    pour le wizard (classes wz-* dans filament/pages/fiscal-year-wizard.blade.php).
    Les accents reprennent les variables --primary-* injectées par ->colors() du panel.
--}}
<style>
    .olmnp-login-block { margin-top: 1.5rem; }

    /* Séparateur « ou » */
    .olmnp-login-divider { display: flex; align-items: center; padding: 0.5rem 0; }
    .olmnp-login-divider-line { flex: 1 1 auto; border-top: 1px solid var(--olmnp-border); }
    .olmnp-login-divider-label { flex: 0 0 auto; margin: 0 1rem; font-size: 0.875rem; color: var(--olmnp-fg-subtle); }

    /* Carte démo */
    .olmnp-login-demo {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        padding: 1rem;
        border: 1px solid var(--primary-200, var(--olmnp-success-border));
        border-radius: 0.75rem;
        background: var(--primary-50, var(--olmnp-success-bg));
        text-decoration: none;
        outline: none;
        transition: border-color 75ms ease, box-shadow 75ms ease;
    }
    .olmnp-login-demo:hover { border-color: var(--primary-400, var(--olmnp-success-border)); box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
    .olmnp-login-demo:focus-visible { box-shadow: 0 0 0 2px var(--primary-600, var(--olmnp-success-solid-hover)); }

    .olmnp-login-demo-icon {
        display: flex;
        flex: 0 0 auto;
        align-items: center;
        justify-content: center;
        width: 2.25rem;
        height: 2.25rem;
        margin-top: 0.125rem;
        border-radius: 0.5rem;
        background: var(--primary-600, var(--olmnp-success-solid-hover));
        color: var(--olmnp-on-solid);
    }
    .olmnp-login-demo-icon svg { width: 1.25rem; height: 1.25rem; }

    .olmnp-login-demo-body { flex: 1 1 auto; min-width: 0; }
    .olmnp-login-demo-title {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--primary-700, var(--olmnp-success-accent));
    }
    .olmnp-login-demo-dot {
        flex: 0 0 auto;
        width: 0.5rem;
        height: 0.5rem;
        border-radius: 9999px;
        background: var(--primary-500, var(--olmnp-success-solid));
    }
    .olmnp-login-demo-subtitle {
        display: block; /* <span> inline sinon : margin-top et line-height sans effet */
        margin-top: 0.125rem;
        font-size: 0.75rem;
        line-height: 1rem;
        color: var(--primary-700, var(--olmnp-success-accent));
        opacity: 0.7;
    }

    .olmnp-login-demo-arrow {
        display: flex;
        flex: 0 0 auto;
        align-self: center;
        color: var(--primary-600, var(--olmnp-success-accent));
        transition: transform 75ms ease;
    }
    .olmnp-login-demo-arrow svg { width: 1rem; height: 1rem; }
    .olmnp-login-demo:hover .olmnp-login-demo-arrow { transform: translateX(0.125rem); }

    /* Ligne « Pas encore de compte ? » */
    .olmnp-login-signup { margin-top: 1.5rem; text-align: center; font-size: 0.875rem; color: var(--olmnp-fg-muted); }
    .olmnp-login-signup-link {
        font-weight: 600;
        color: var(--primary-600, var(--olmnp-success-accent));
        text-decoration: none;
        outline: none;
    }
    .olmnp-login-signup-link:hover { color: var(--primary-500, var(--olmnp-success-accent)); text-decoration: underline; }
    .olmnp-login-signup-link:focus-visible { border-radius: 0.25rem; box-shadow: 0 0 0 2px var(--primary-600, var(--olmnp-success-solid-hover)); }

    /* Mode sombre (le panel bascule via la classe .dark sur <html>) */
    .dark .olmnp-login-divider-line { border-top-color: rgb(255 255 255 / 0.1); }
    .dark .olmnp-login-divider-label { color: var(--olmnp-fg-muted); }
    .dark .olmnp-login-demo { border-color: rgb(255 255 255 / 0.1); background: rgb(255 255 255 / 0.05); }
    .dark .olmnp-login-demo:hover { border-color: var(--primary-500, var(--olmnp-success-solid)); }
    .dark .olmnp-login-demo:focus-visible { box-shadow: 0 0 0 2px var(--primary-400, var(--olmnp-success-border)); }
    .dark .olmnp-login-demo-icon { background: var(--primary-500, var(--olmnp-success-solid)); }
    .dark .olmnp-login-demo-title,
    .dark .olmnp-login-demo-subtitle { color: var(--primary-300, var(--olmnp-success-fg)); }
    .dark .olmnp-login-demo-arrow { color: var(--primary-400, var(--olmnp-success-accent)); }
    .dark .olmnp-login-signup { color: var(--olmnp-fg-subtle); }
    .dark .olmnp-login-signup-link { color: var(--primary-400, var(--olmnp-success-accent)); }
    .dark .olmnp-login-signup-link:hover { color: var(--primary-300, var(--olmnp-success-fg)); }
    .dark .olmnp-login-signup-link:focus-visible { box-shadow: 0 0 0 2px var(--primary-400, var(--olmnp-success-border)); }
</style>

@if (config('demo.enabled'))
    <div class="olmnp-login-block">
        {{-- Séparateur « ou » --}}
        <div class="olmnp-login-divider">
            <div class="olmnp-login-divider-line"></div>
            <span class="olmnp-login-divider-label">ou</span>
            <div class="olmnp-login-divider-line"></div>
        </div>

        {{-- Carte démo (accent repris de la couleur primaire du panel) --}}
        <a
            href="{{ route('demo.start') }}"
            aria-label="Essayer la démonstration OpenLMNP dans un sandbox sans inscription"
            class="olmnp-login-demo"
        >
            <span class="olmnp-login-demo-icon">
                {{-- width/height explicites : sans eux, un <svg> non dimensionné occupe 100% du conteneur --}}
                <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M6.3 2.84A1.5 1.5 0 0 0 4 4.11v11.78a1.5 1.5 0 0 0 2.3 1.27l9.34-5.89a1.5 1.5 0 0 0 0-2.54L6.3 2.84Z" />
                </svg>
            </span>
            <span class="olmnp-login-demo-body">
                <span class="olmnp-login-demo-title">
                    <span class="olmnp-login-demo-dot"></span>
                    Essayez la démo
                </span>
                <span class="olmnp-login-demo-subtitle">
                    {{-- La durée est annoncée ICI, avant d'entrer : c'est le premier des
                         quatre endroits où le visiteur peut l'apprendre, et le seul qu'il
                         voie forcément. --}}
                    Sans inscription · données d'exemple · effacé après {{ config('demo.ttl_hours') }} h
                </span>
            </span>
            <span class="olmnp-login-demo-arrow" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M7 17L17 7M9 7h8v8" />
                </svg>
            </span>
        </a>
    </div>
@endif

@if ($websiteUrl = config('services.website.url'))
    <p class="olmnp-login-signup">
        Pas encore de compte ?
        <a
            href="{{ $websiteUrl }}"
            target="_blank"
            rel="noopener"
            class="olmnp-login-signup-link"
        >
            Découvrir OpenLMNP
        </a>
    </p>
@endif
