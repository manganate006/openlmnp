@php
    $consent = \App\Support\ConsentState::fromRequest(request());
@endphp
{{--
    Bandeau de consentement aux traceurs.

    ⚠️ NE S'AFFICHE QUE SI UN CONTENEUR GTM EST CONFIGURÉ. C'est le hook du panel qui pose
    cette condition, la même que pour `partials.gtm-head` : une instance auto-hébergée ne
    charge aucun traceur, donc n'a aucune question à poser. Demander son consentement à
    quelqu'un que l'on ne mesure pas serait au mieux absurde, au pire inquiétant.

    ⚠️ REFUSER DOIT ÊTRE AUSSI SIMPLE QU'ACCEPTER : deux boutons, un clic chacun, même poids
    visuel. C'est le motif que la CNIL sanctionne quand il est déséquilibré.

    ⚠️ AUCUN UTILITAIRE TAILWIND ICI. Le CSS servi par le panel ne contient que les classes
    composants `fi-*` : `fixed`, `bg-white`, `flex` n'existent pas et ne produiraient RIEN,
    en silence. D'où ce `<style>` scopé à classes préfixées `olmnp-cb-*`, et les couleurs
    prises dans les jetons `--olmnp-*` — `PanelStylesheetTest` échoue sur toute classe non
    définie et sur tout littéral de couleur écrit hors de `theme-tokens.blade.php`.
--}}
@unless ($consent->decided)
    <style>
        .olmnp-cb { position: fixed; left: 0; right: 0; bottom: 0; z-index: 50; background: var(--olmnp-surface); border-top: 1px solid var(--olmnp-border); box-shadow: 0 -4px 16px rgba(0,0,0,0.12); }
        .olmnp-cb-inner { max-width: 64rem; margin: 0 auto; padding: 1rem 1.25rem; display: flex; flex-direction: column; gap: 1rem; }
        .olmnp-cb-title { font-weight: 600; color: var(--olmnp-fg-strong); margin: 0 0 0.25rem; }
        .olmnp-cb-text { font-size: 0.875rem; color: var(--olmnp-fg-muted); margin: 0; line-height: 1.5; }
        .olmnp-cb-link { color: var(--olmnp-success-accent); text-decoration: underline; text-underline-offset: 2px; }
        .olmnp-cb-actions { display: flex; gap: 0.75rem; flex-shrink: 0; }
        .olmnp-cb-btn { flex: 1; padding: 0.625rem 1.25rem; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 600; cursor: pointer; border: 1px solid var(--olmnp-border-strong); background: var(--olmnp-surface); color: var(--olmnp-fg); }
        .olmnp-cb-btn:hover { background: var(--olmnp-surface-muted); }
        .olmnp-cb-btn-accept { border-color: var(--olmnp-success-solid); background: var(--olmnp-success-solid); color: var(--olmnp-on-solid); }
        .olmnp-cb-btn-accept:hover { background: var(--olmnp-success-solid-hover); }
        @media (min-width: 768px) {
            .olmnp-cb-inner { flex-direction: row; align-items: center; justify-content: space-between; }
            .olmnp-cb-btn { flex: none; }
        }
    </style>
    <div id="olmnp-consent-banner" class="olmnp-cb" role="dialog" aria-labelledby="olmnp-cb-title">
        <div class="olmnp-cb-inner">
            <div>
                <p id="olmnp-cb-title" class="olmnp-cb-title">Mesure d'audience</p>
                <p class="olmnp-cb-text">
                    Nous mesurons la fréquentation pour savoir ce qui vous est utile. Rien n'est
                    déposé sans votre accord, et l'application fonctionne à l'identique si vous
                    refusez.
                    <a href="{{ route('legal.confidentialite') }}" class="olmnp-cb-link">En savoir plus</a>
                </p>
            </div>
            <div class="olmnp-cb-actions">
                <button type="button" data-consent="refuse" class="olmnp-cb-btn">Refuser</button>
                <button type="button" data-consent="accept" class="olmnp-cb-btn olmnp-cb-btn-accept">Accepter</button>
            </div>
        </div>
    </div>
    <script>
        (function () {
            var banner = document.getElementById('olmnp-consent-banner');
            if (!banner) { return; }

            function remember(accepted) {
                // Format lu par App\Support\ConsentState : « v1.a<0|1>p<0|1> ». Scalaire et non
                // JSON : une erreur d'encodage se lirait sinon comme un refus, en silence.
                var value = 'v1.a' + (accepted ? '1' : '0') + 'p' + (accepted ? '1' : '0');
                var attrs = '; path=/; max-age={{ \App\Support\ConsentState::LIFETIME_DAYS * 24 * 60 * 60 }}; SameSite=Lax';
                if (location.protocol === 'https:') { attrs += '; Secure'; }
                document.cookie = '{{ \App\Support\ConsentState::COOKIE }}=' + value + attrs;

                // Les tags attendent ce signal : le donner tout de suite évite un rechargement.
                gtag('consent', 'update', {
                    ad_storage: accepted ? 'granted' : 'denied',
                    analytics_storage: accepted ? 'granted' : 'denied',
                    ad_user_data: accepted ? 'granted' : 'denied',
                    ad_personalization: accepted ? 'granted' : 'denied',
                });

                banner.remove();
            }

            banner.querySelectorAll('[data-consent]').forEach(function (button) {
                button.addEventListener('click', function () {
                    remember(button.dataset.consent === 'accept');
                });
            });
        })();
    </script>
@endunless
