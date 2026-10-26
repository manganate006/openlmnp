{{--
    Encart affiché au-dessus du formulaire de connexion (render hook AUTH_LOGIN_FORM_BEFORE)
    quand un visiteur arrive de /register sur une instance où l'inscription est fermée et qui
    ne déclare aucune page de souscription — le cas d'une instance auto-hébergée.

    Sans lui, SignupRedirectController renverrait vers /login sans rien expliquer, et le
    visiteur croirait avoir été rejeté par erreur. Aucune mention commerciale ici : ce dépôt
    est public, et une instance auto-hébergée n'a rien à vendre.

    ⚠️ Le CSS servi par le panel ne contient AUCUN utilitaire Tailwind (pas de viteTheme) :
    toute la mise en forme passe par ce <style> scopé, et les couleurs par les jetons
    --olmnp-* du partial theme-tokens, qui se déclinent en thème sombre. Une var() inventée
    retomberait TOUJOURS sur son repli clair, donc blanc sur blanc en sombre (issue #5).
--}}
@if (request()->query('inscription') === 'fermee')
    <style>
        .olmnp-login-notice {
            display: flex;
            gap: 0.625rem;
            margin-bottom: 1.5rem;
            padding: 0.75rem 0.875rem;
            border: 1px solid var(--olmnp-info-border);
            border-radius: 0.5rem;
            background: var(--olmnp-info-bg);
            color: var(--olmnp-info-fg);
            font-size: 0.8125rem;
            line-height: 1.25rem;
        }
        .olmnp-login-notice-icon {
            flex: 0 0 auto;
            margin-top: 0.0625rem;
            color: var(--olmnp-info-accent);
        }
        .olmnp-login-notice-title { display: block; font-weight: 600; }
    </style>

    <div class="olmnp-login-notice">
        <span class="olmnp-login-notice-icon">
            {{-- width/height explicites : sans eux, un <svg> non dimensionné occupe 100% du conteneur --}}
            <svg width="18" height="18" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm1-11a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm-2 3a1 1 0 0 1 2 0v4a1 1 0 1 1-2 0v-4Z" clip-rule="evenodd" />
            </svg>
        </span>
        <span>
            <span class="olmnp-login-notice-title">La création de compte est fermée sur cette instance.</span>
            Les comptes y sont ouverts par l'administrateur. Rapprochez-vous de lui pour obtenir
            un accès, puis connectez-vous ci-dessous.
        </span>
    </div>
@endif
