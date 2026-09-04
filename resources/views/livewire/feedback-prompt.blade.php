{{--
    Invitation à donner son avis — trois mises en forme en lice (test A/B/C).

    Le composant est monté sur toutes les pages du panel (hook `panels::body.end`) mais
    reste INERTE : c'est le navigateur qui décide du moment, parce que le serveur ne sait
    pas combien de temps une page est restée ouverte.

    ⚠️ Aucun utilitaire Tailwind ici : le panel Filament ne sert que les classes `fi-*`.
    Toute la mise en forme passe par ce `<style>` scopé et les jetons `--olmnp-*`.
--}}
<div>
    @if ($eligible || $step !== 'idle')
        <div
            @if ($step === 'idle')
                x-data="{
                    seconds: 0,
                    actions: 0,
                    opened: false,
                    timer: null,
                    tracked: @js($trackedActions),
                    minSeconds: @js($minSeconds),
                    minActions: @js($minActions),

                    init() {
                        // Le compteur vit dans sessionStorage, pas dans la page : le panel
                        // recharge à chaque navigation, et un compteur remis à zéro à chaque
                        // écran n'atteindrait jamais le seuil pour qui explore le logiciel.
                        this.seconds = this.restore('olmnp_fb_seconds');
                        this.actions = this.restore('olmnp_fb_actions');

                        // Même pont d'événements que le relais GTM : les pages du panel
                        // émettent déjà `dispatch('analytics', …)`, et cet écouteur-ci ne
                        // dépend d'aucune configuration analytique — il marche en self-hosted.
                        window.addEventListener('analytics', (event) => {
                            let payload = event.detail || {};
                            if (Array.isArray(payload)) { payload = payload[0] || {}; }

                            if (payload.event && this.tracked.includes(payload.event)) {
                                this.actions++;
                                this.persist('olmnp_fb_actions', this.actions);
                                this.maybeOpen();
                            }
                        });

                        this.timer = setInterval(() => {
                            this.seconds++;
                            this.persist('olmnp_fb_seconds', this.seconds);
                            this.maybeOpen();
                        }, 1000);

                        this.maybeOpen();
                    },

                    maybeOpen() {
                        if (this.opened) return;
                        if (this.seconds < this.minSeconds) return;
                        if (this.actions < this.minActions) return;

                        this.opened = true;
                        clearInterval(this.timer);
                        $wire.open();
                    },

                    // Un navigateur en navigation privée peut refuser sessionStorage :
                    // on retombe alors sur un compteur par page plutôt que de casser l'écran.
                    restore(key) {
                        try { return parseInt(window.sessionStorage.getItem(key) || '0', 10) || 0; }
                        catch (e) { return 0; }
                    },
                    persist(key, value) {
                        try { window.sessionStorage.setItem(key, String(value)); } catch (e) {}
                    },
                }"
            @endif
        >
            @if ($step !== 'idle')
                @if ($variant === 'a')
                    {{-- Variante A — modale centrée, sur voile : interruption assumée. --}}
                    <div class="fb-overlay" wire:key="fb-a">
                        <div class="fb-card fb-card-modal" role="dialog" aria-modal="true"
                             @keydown.escape.window="$wire.dismiss()">
                            @include('livewire.partials.feedback-content')
                        </div>
                    </div>
                @elseif ($variant === 'b')
                    {{-- Variante B — bandeau bas, sans voile : le panel reste utilisable. --}}
                    <div class="fb-banner" wire:key="fb-b">
                        {{-- `fb-banner-ask` : à l'état 1, le bandeau tient sur UNE ligne (question
                             à gauche, réponses à droite). C'est tout son intérêt face à la modale —
                             sans cette classe il s'empile et occupe le triple de hauteur. --}}
                        <div class="fb-card fb-card-banner @if ($step === 'ask') fb-banner-ask @endif" role="dialog"
                             @keydown.escape.window="$wire.dismiss()">
                            @include('livewire.partials.feedback-content')
                        </div>
                    </div>
                @else
                    {{-- Variante C — carte flottante, coin bas droit. --}}
                    <div class="fb-floating" wire:key="fb-c">
                        <div class="fb-card fb-card-float" role="dialog"
                             @keydown.escape.window="$wire.dismiss()">
                            @include('livewire.partials.feedback-content')
                        </div>
                    </div>
                @endif
            @endif
        </div>
    @endif

    <style>
        /*
            Habillages — un par variante du test.
            Les voiles et ombres dérivent de `--olmnp-code-bg`, seul jeton foncé dans les
            DEUX thèmes : une ombre écrite en `rgba(0,0,0,…)` deviendrait un halo clair en
            thème sombre, et un littéral de couleur ferait échouer `PanelStylesheetTest`.
        */
        .fb-overlay {
            position: fixed;
            inset: 0;
            z-index: 60;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: color-mix(in oklab, var(--olmnp-code-bg) 55%, transparent);
        }

        .fb-banner {
            position: fixed;
            inset-inline: 0;
            bottom: 0;
            z-index: 60;
            display: flex;
            justify-content: center;
            padding: 0.75rem;
            pointer-events: none;
        }

        /*
            ⚠️ Le coin bas droit est DÉJÀ occupé par le bouton d'aide contextuelle
            (`.ctx-help-btn`, 48 px à 24 px des bords). Posée à `bottom: 1rem`, la carte le
            recouvrait entièrement — vérifié à l'écran, pas supposé. Elle est donc remontée
            juste au-dessus, et alignée sur le même axe droit : les deux cohabitent au lieu
            que l'un fasse disparaître l'autre.
        */
        .fb-floating {
            position: fixed;
            right: 1.5rem;
            bottom: 5.5rem;
            z-index: 60;
            max-width: calc(100vw - 3rem);
        }

        .fb-card {
            background: var(--olmnp-surface);
            border: 1px solid var(--olmnp-border);
            border-radius: 1rem;
            padding: 1.25rem;
            box-shadow: 0 10px 30px color-mix(in oklab, var(--olmnp-code-bg) 25%, transparent);
            pointer-events: auto;
            animation: fb-in 0.25s ease-out;
        }

        .fb-card-modal {
            width: 100%;
            max-width: 27.5rem;
            max-height: calc(100vh - 2rem);
            overflow-y: auto;
        }

        .fb-card-banner {
            position: relative;
            width: 100%;
            max-width: 62rem;
            max-height: calc(100vh - 3rem);
            overflow-y: auto;
            padding-right: 3rem;
            border-top: 2px solid var(--olmnp-success-accent);
        }

        /*
            Dans le bandeau, la croix est ancrée au coin de la carte plutôt qu'au bout du
            titre : en disposition sur une ligne, elle se retrouverait sinon au milieu.
        */
        .fb-card-banner .fb-close {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
        }

        .fb-card-float {
            width: 22.5rem;
            max-width: 100%;
            max-height: calc(100vh - 2rem);
            overflow-y: auto;
        }

        @keyframes fb-in {
            from { opacity: 0; transform: translateY(0.75rem); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (prefers-reduced-motion: reduce) {
            .fb-card { animation: none; }
        }

        /* En-tête */
        .fb-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .fb-title {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
            color: var(--olmnp-fg-strong);
            text-wrap: balance;
        }

        .fb-subtitle {
            margin: 0.25rem 0 0;
            font-size: 0.8125rem;
            line-height: 1.45;
            color: var(--olmnp-fg-muted);
        }

        .fb-close {
            flex: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.75rem;
            height: 1.75rem;
            border: 0;
            border-radius: 0.5rem;
            background: transparent;
            color: var(--olmnp-fg-subtle);
            cursor: pointer;
        }

        .fb-close:hover { background: var(--olmnp-surface-alt); color: var(--olmnp-fg); }

        /* Les deux réponses */
        .fb-choices {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .fb-choice {
            flex: 1 1 9rem;
            padding: 0.625rem 0.875rem;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--olmnp-fg-strong);
            background: var(--olmnp-surface);
            border: 1px solid var(--olmnp-border-strong);
            border-radius: 0.625rem;
            cursor: pointer;
        }

        .fb-choice:hover {
            background: var(--olmnp-success-bg);
            border-color: var(--olmnp-success-border);
        }

        .fb-later-row { margin-top: 0.75rem; text-align: center; }

        .fb-later {
            border: 0;
            background: transparent;
            padding: 0.25rem;
            font-size: 0.75rem;
            color: var(--olmnp-fg-muted);
            text-decoration: underline;
            cursor: pointer;
        }

        .fb-later:hover { color: var(--olmnp-fg); }

        /* Corps : les gestes de soutien, puis le mot libre */
        .fb-body { margin-top: 0.875rem; }

        /*
            Offre hébergée — visible uniquement en démonstration, et seulement si une URL est
            configurée. C'est le seul élément de la carte à porter un aplat plein : il doit
            se distinguer des trois liens de soutien, qui sont l'alternative et non le
            chemin principal pour un visiteur qui découvre le produit.

            `success-solid-hover` AU REPOS et non `solid` : avec `--olmnp-on-solid` (blanc),
            l'émeraude claire ne donne que ~2,5:1 de contraste contre ~4,0:1 pour la foncée.
            Même parti pris que `.fb-submit` et que `.ctx-help-btn` du panneau d'aide.
        */
        .fb-cta-pro {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 0.875rem;
            margin-bottom: 0.875rem;
            border-radius: 0.625rem;
            background: var(--olmnp-success-solid-hover);
            color: var(--olmnp-on-solid);
            text-decoration: none;
        }

        .fb-cta-pro:hover { background: var(--olmnp-success-solid); }

        .fb-cta-pro svg { flex: none; }

        .fb-cta-pro-text { display: block; flex: 1; }

        .fb-cta-pro-title {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .fb-cta-pro-sub {
            display: block;
            margin-top: 0.125rem;
            font-size: 0.75rem;
            line-height: 1.4;
            opacity: 0.9;
        }

        .fb-links { display: flex; flex-direction: column; gap: 0.5rem; }

        .fb-link {
            display: flex;
            align-items: flex-start;
            gap: 0.625rem;
            padding: 0.625rem 0.75rem;
            border: 1px solid var(--olmnp-border);
            border-radius: 0.625rem;
            background: var(--olmnp-surface-muted);
            text-decoration: none;
        }

        .fb-link:hover { border-color: var(--olmnp-success-border); }

        .fb-link-icon {
            flex: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.75rem;
            height: 1.75rem;
            border-radius: 0.5rem;
            background: var(--olmnp-warning-bg);
            color: var(--olmnp-warning-accent);
        }

        .fb-link-icon-pink { background: var(--olmnp-pink-bg); color: var(--olmnp-pink-accent); }
        .fb-link-icon-info { background: var(--olmnp-info-bg); color: var(--olmnp-info-accent); }

        .fb-link-text { display: block; }

        .fb-link-title {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--olmnp-fg-strong);
        }

        .fb-link-sub {
            display: block;
            margin-top: 0.125rem;
            font-size: 0.75rem;
            line-height: 1.4;
            color: var(--olmnp-fg-muted);
        }

        /* Formulaire */
        .fb-form { margin-top: 0.875rem; }

        .fb-separator {
            margin: 0 0 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--olmnp-fg-muted);
            text-align: center;
        }

        .fb-textarea,
        .fb-input {
            width: 100%;
            padding: 0.5rem 0.625rem;
            font-size: 0.8125rem;
            font-family: inherit;
            color: var(--olmnp-fg-strong);
            background: var(--olmnp-surface);
            border: 1px solid var(--olmnp-border-strong);
            border-radius: 0.5rem;
        }

        .fb-textarea { resize: vertical; }

        .fb-textarea:focus,
        .fb-input:focus {
            outline: 2px solid var(--olmnp-success-accent);
            outline-offset: 1px;
        }

        .fb-fields {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .fb-field { flex: 1 1 10rem; display: block; }

        .fb-label {
            display: block;
            margin-bottom: 0.25rem;
            font-size: 0.75rem;
            color: var(--olmnp-fg-muted);
        }

        .fb-consent {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            margin-top: 0.75rem;
            font-size: 0.75rem;
            line-height: 1.4;
            color: var(--olmnp-fg);
        }

        .fb-checkbox { margin-top: 0.125rem; flex: none; }

        .fb-error {
            margin: 0.375rem 0 0;
            font-size: 0.75rem;
            color: var(--olmnp-danger-accent);
        }

        /*
            Bouton principal : `solid-hover` AU REPOS, pas `solid`. Avec `--olmnp-on-solid`
            (blanc), l'émeraude claire ne donne que ~2,5:1 alors que la foncée atteint ~4,0:1.
            Même parti pris que `.ctx-help-btn` du panneau d'aide.
        */
        .fb-submit {
            display: block;
            width: 100%;
            margin-top: 0.875rem;
            padding: 0.625rem 1rem;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--olmnp-on-solid);
            background: var(--olmnp-success-solid-hover);
            border: 0;
            border-radius: 0.625rem;
            cursor: pointer;
        }

        .fb-submit:hover { background: var(--olmnp-success-solid); }

        .fb-secondary {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            margin-top: 0.5rem;
            padding: 0.5625rem 1rem;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--olmnp-fg-strong);
            background: var(--olmnp-surface);
            border: 1px solid var(--olmnp-border-strong);
            border-radius: 0.625rem;
            text-decoration: none;
        }

        .fb-secondary:hover { background: var(--olmnp-surface-alt); }

        /* Confirmation */
        .fb-done { text-align: center; padding: 0.5rem 0; }

        .fb-done-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            margin-bottom: 0.625rem;
            border-radius: 999px;
            background: var(--olmnp-success-bg);
            color: var(--olmnp-success-accent);
        }

        /*
            Le bandeau exploite sa largeur quand il en a : les gestes de soutien à gauche,
            le mot libre à droite. En dessous, il redevient une colonne — sinon deux demi-
            colonnes de 15 rem se retrouvent illisibles.
        */
        /*
            État 1 sur une seule ligne — la raison d'être du bandeau. Mesuré ~70 px de haut,
            contre ~180 px empilé : c'est la différence entre « je peux continuer à
            travailler » et « on m'a mis une modale en bas de l'écran ».
            Sous 46 rem, retour à l'empilement : trois blocs côte à côte y seraient illisibles.
        */
        @media (min-width: 46rem) {
            .fb-banner-ask {
                display: flex;
                align-items: center;
                gap: 1rem;
                flex-wrap: wrap;
            }

            .fb-banner-ask .fb-head { flex: 1 1 18rem; }
            .fb-banner-ask .fb-choices { margin-top: 0; flex: 0 0 auto; }
            .fb-banner-ask .fb-choice { flex: 0 0 auto; }
            .fb-banner-ask .fb-later-row { margin-top: 0; }
        }

        @media (min-width: 52rem) {
            .fb-card-banner .fb-body {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 1rem;
                align-items: start;
            }

            .fb-card-banner .fb-form { margin-top: 0; }
        }

        /*
            Sur petit écran, la carte flottante prendrait toute la largeur et deviendrait
            une modale qui s'ignore : autant l'assumer et la coller en bas, comme le bandeau.
        */
        @media (max-width: 30rem) {
            .fb-floating { right: 0.5rem; left: 0.5rem; bottom: 5rem; }
            .fb-card-float { width: 100%; }
        }
    </style>
</div>
