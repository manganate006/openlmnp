{{--
    Compte à rebours du bac à sable de démonstration, et relances avant effacement.

    Monté sur toutes les pages du panel (hook `panels::body.end`), mais le serveur ne décide
    PAS du moment : il fournit la date d'expiration et les paliers encore dus, et c'est le
    navigateur qui égrène les secondes. Le serveur revalide ensuite tout palier réclamé
    (DemoExpiry::isReached) — sans quoi n'importe qui pourrait faire marquer les paliers
    restants comme servis et se soustraire aux relances suivantes.

    ⚠️ Aucun utilitaire Tailwind : le panel Filament ne sert que ses propres classes `fi-*`.
    Toute la mise en forme passe par ce <style> scopé et les jetons `--olmnp-*`.
    ⚠️ La pastille est en bas DROITE, EMPILÉE au-dessus de `.ctx-help-btn` : le bas gauche
    est occupé par le sélecteur de mode de navigation (`panels::sidebar.footer`).
--}}
<div>
    @if ($applies)
    {{--
        Styles scopés. Aucune classe Tailwind, aucun littéral de couleur : PanelStylesheetTest
        échoue sur toute classe non définie, toute var() inexistante et tout code couleur.
        Les voiles et ombres sont dérivés des jetons par color-mix(in oklab, …, transparent),
        idiome déjà employé par les widgets du panel.
    --}}
    <style>
        /*
            Coin bas DROIT, empilée AU-DESSUS de l'aide contextuelle.

            Le bas gauche paraissait libre — il évitait `.ctx-help-btn` — mais il tombe sur
            le sélecteur de mode de navigation du pied de barre latérale
            (`panels::sidebar.footer`). Vérifié au rendu : la pastille le recouvrait.
            `.ctx-help-btn` fait 48 px à 24 px du bord, donc 72 px au total : 84 px laissent
            12 px de jeu.
        */
        .dx-pill {
            position: fixed; right: 24px; bottom: 84px; z-index: 30;
            display: inline-flex; align-items: center; gap: 8px;
            padding: 7px 13px 7px 10px; border-radius: 999px;
            font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer;
            background: var(--olmnp-surface); color: var(--olmnp-fg);
            border: 1px solid var(--olmnp-border-strong);
            box-shadow: 0 2px 8px color-mix(in oklab, var(--olmnp-code-bg) 14%, transparent);
            transition: border-color .15s, box-shadow .15s, transform .15s;
        }
        .dx-pill:hover { transform: translateY(-1px); }
        .dx-pill-icon { display: inline-flex; }
        .dx-pill-time { font-variant-numeric: tabular-nums; }
        .dx-pill-label { color: var(--olmnp-fg-muted); font-weight: 500; }
        .dx-pill.is-calm { border-color: var(--olmnp-success-border); color: var(--olmnp-success-fg); background: var(--olmnp-success-bg); }
        .dx-pill.is-calm .dx-pill-label { color: var(--olmnp-success-fg); }
        .dx-pill.is-warn { border-color: var(--olmnp-warning-border); color: var(--olmnp-warning-fg); background: var(--olmnp-warning-bg); }
        .dx-pill.is-warn .dx-pill-label { color: var(--olmnp-warning-fg); }
        .dx-pill.is-urgent { border-color: var(--olmnp-danger-border); color: var(--olmnp-danger-fg); background: var(--olmnp-danger-bg); }
        .dx-pill.is-urgent .dx-pill-label { color: var(--olmnp-danger-fg); }
        /* Le battement ne concerne QUE la dernière heure : une pastille qui palpite 24 h
           durant devient du bruit, et il ne reste plus aucun signal pour l'heure qui compte. */
        .dx-pill.is-urgent .dx-pill-icon { animation: dx-beat 1.6s ease-in-out infinite; }
        @keyframes dx-beat { 0%, 100% { opacity: 1; } 50% { opacity: .45; } }
        @media (prefers-reduced-motion: reduce) { .dx-pill.is-urgent .dx-pill-icon { animation: none; } }

        .dx-banner { position: fixed; inset-inline: 0; bottom: 0; z-index: 40; padding: 14px; pointer-events: none; }
        .dx-banner-card {
            pointer-events: auto;
            display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
            max-width: 720px; margin: 0 auto; padding: 13px 15px; border-radius: 12px;
            background: var(--olmnp-surface); border: 1px solid var(--olmnp-warning-border);
            box-shadow: 0 8px 24px color-mix(in oklab, var(--olmnp-code-bg) 34%, transparent);
            animation: dx-rise .45s cubic-bezier(.16, 1, .3, 1) both;
        }
        @keyframes dx-rise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
        .dx-banner-icon {
            flex: none; display: flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 9px;
            background: var(--olmnp-warning-bg-strong); color: var(--olmnp-warning-accent);
        }
        .dx-banner-body { flex: 1 1 240px; min-width: 0; }
        .dx-banner-title { margin: 0; font-size: 13.5px; font-weight: 600; color: var(--olmnp-fg-strong); }
        .dx-banner-title b { font-variant-numeric: tabular-nums; color: var(--olmnp-warning-fg); }
        .dx-banner-sub { margin: 2px 0 0; font-size: 12.5px; color: var(--olmnp-fg-muted); }
        .dx-banner-actions { flex: none; display: flex; align-items: center; gap: 8px; }

        .dx-overlay {
            position: fixed; inset: 0; z-index: 50;
            display: flex; align-items: center; justify-content: center; padding: 18px;
            background: color-mix(in oklab, var(--olmnp-code-bg) 68%, transparent);
            animation: dx-fade .3s ease both;
        }
        @keyframes dx-fade { from { opacity: 0; } to { opacity: 1; } }
        .dx-card {
            position: relative; width: 100%; max-width: 420px; max-height: 100%; overflow: auto;
            border-radius: 16px; background: var(--olmnp-surface); border: 1px solid var(--olmnp-border);
            box-shadow: 0 22px 48px color-mix(in oklab, var(--olmnp-code-bg) 34%, transparent);
            animation: dx-pop .42s cubic-bezier(.16, 1, .3, 1) both;
        }
        .dx-card.is-wide { max-width: 460px; }
        @keyframes dx-pop { from { opacity: 0; transform: translateY(16px) scale(.97); } to { opacity: 1; transform: none; } }
        .dx-card-body { padding: 22px; }
        .dx-card-head { padding: 20px 20px 14px; text-align: center; }
        .dx-card-foot { padding: 14px 20px 18px; text-align: center; border-top: 1px solid var(--olmnp-border); }
        .dx-center { text-align: center; }
        .dx-center-block { display: block; margin-inline: auto; }
        .dx-card h2 { margin: 0 0 6px; font-size: 17px; font-weight: 700; color: var(--olmnp-fg-strong); }
        .dx-lead { margin: 0 0 16px; font-size: 13.5px; line-height: 1.55; color: var(--olmnp-fg-muted); }
        .dx-card-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 46px; height: 46px; margin-bottom: 12px; border-radius: 13px;
            background: var(--olmnp-warning-bg-strong); color: var(--olmnp-warning-accent);
        }
        .dx-card-icon.is-urgent { background: var(--olmnp-danger-bg-strong); color: var(--olmnp-danger-accent); }
        .dx-dismiss {
            position: absolute; top: 10px; right: 10px; z-index: 2;
            display: flex; align-items: center; justify-content: center;
            width: 28px; height: 28px; border-radius: 8px;
            background: none; border: none; cursor: pointer; color: var(--olmnp-fg-subtle);
        }
        .dx-dismiss:hover { background: var(--olmnp-surface-alt); color: var(--olmnp-fg-strong); }

        .dx-count {
            display: block; margin: 0 0 4px;
            font-size: 40px; line-height: 1.1; font-weight: 700;
            font-variant-numeric: tabular-nums; letter-spacing: -0.02em;
            color: var(--olmnp-warning-fg);
        }
        .dx-count.is-urgent { color: var(--olmnp-danger-fg); }
        .dx-count-unit {
            display: block; margin-bottom: 14px;
            font-size: 11.5px; font-weight: 600; letter-spacing: .07em; text-transform: uppercase;
            color: var(--olmnp-fg-subtle);
        }

        .dx-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 7px;
            width: 100%; padding: 9px 15px; border-radius: 10px;
            font: inherit; font-size: 13.5px; font-weight: 600;
            cursor: pointer; text-decoration: none; border: 1px solid transparent;
            transition: background .15s, border-color .15s, color .15s;
        }
        .dx-btn-auto { width: auto; }
        .dx-btn-small { padding: 7px 12px; font-size: 12.5px; width: auto; }
        .dx-btn-primary { background: var(--olmnp-success-solid); color: var(--olmnp-on-solid); }
        .dx-btn-primary:hover { background: var(--olmnp-success-solid-hover); }
        .dx-btn-ghost { background: transparent; color: var(--olmnp-fg); border-color: var(--olmnp-border-strong); }
        .dx-btn-ghost:hover { background: var(--olmnp-surface-alt); color: var(--olmnp-fg-strong); }
        .dx-btn-link {
            display: inline-block; margin-top: 12px;
            font: inherit; font-size: 12.5px; font-weight: 500; text-decoration: underline;
            color: var(--olmnp-fg-muted); background: none; border: none; cursor: pointer;
        }
        .dx-btn-link:hover { color: var(--olmnp-fg-strong); }

        .dx-keep {
            margin: 0 0 15px; padding: 11px 13px; border-radius: 10px; text-align: left;
            background: var(--olmnp-success-bg); border: 1px solid var(--olmnp-success-border);
        }
        .dx-keep-title {
            margin: 0 0 6px; font-size: 12px; font-weight: 700;
            letter-spacing: .04em; text-transform: uppercase; color: var(--olmnp-success-fg);
        }
        .dx-keep ul { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 4px; }
        .dx-keep li { display: flex; align-items: center; gap: 7px; font-size: 12.5px; color: var(--olmnp-success-fg); }
        .dx-keep li svg { flex: none; color: var(--olmnp-success-accent); }

        .dx-frame { position: relative; margin: 0 14px; }
        .dx-frame-iframe {
            display: block; width: 100%; height: 470px; border: 0; border-radius: 12px;
            background: var(--olmnp-surface-muted);
        }
        .dx-frame-fallback {
            padding: 22px 16px; border-radius: 12px; text-align: center;
            background: var(--olmnp-surface-muted); border: 1px solid var(--olmnp-border);
        }
        .dx-frame-fallback p { margin: 0 0 12px; font-size: 13px; color: var(--olmnp-fg-muted); }
        .dx-foot-hint { margin: 9px 0 0; font-size: 11.5px; line-height: 1.5; color: var(--olmnp-fg-subtle); }

        .dx-form { margin-top: 4px; text-align: left; }
        .dx-label { display: block; margin-bottom: 5px; font-size: 12.5px; font-weight: 600; color: var(--olmnp-fg-strong); }
        .dx-input {
            width: 100%; padding: 9px 11px; border-radius: 9px;
            font: inherit; font-size: 13.5px;
            background: var(--olmnp-surface); color: var(--olmnp-fg-strong);
            border: 1px solid var(--olmnp-border-strong);
        }
        .dx-input:focus { outline: 2px solid var(--olmnp-success-accent); outline-offset: 1px; }
        .dx-error { margin: 5px 0 0; font-size: 12px; color: var(--olmnp-danger-fg); }
        .dx-consent {
            display: flex; align-items: flex-start; gap: 8px; margin: 11px 0 14px;
            font-size: 12px; line-height: 1.5; color: var(--olmnp-fg-muted);
        }
        .dx-consent input { flex: none; margin-top: 2px; accent-color: var(--olmnp-success-solid); }
        .dx-fineprint { margin: 12px 0 0; text-align: center; font-size: 11.5px; line-height: 1.5; color: var(--olmnp-fg-subtle); }

        .dx-confirm {
            display: flex; align-items: flex-start; gap: 10px;
            margin: 0 0 16px; padding: 12px 13px; border-radius: 10px;
            background: var(--olmnp-success-bg); border: 1px solid var(--olmnp-success-border);
        }
        .dx-confirm svg { flex: none; margin-top: 1px; color: var(--olmnp-success-accent); }
        .dx-confirm p { margin: 0; font-size: 12.5px; line-height: 1.55; color: var(--olmnp-success-fg); }

        .dx-copy {
            display: flex; align-items: stretch; margin: 0 0 6px;
            border-radius: 10px; overflow: hidden;
            border: 1px solid var(--olmnp-border-strong); background: var(--olmnp-surface-muted);
        }
        .dx-copy-url {
            flex: 1 1 auto; min-width: 0; padding: 9px 11px;
            font-size: 12px; color: var(--olmnp-fg);
            background: transparent; border: none;
        }
        .dx-copy-btn {
            flex: none; display: inline-flex; align-items: center; gap: 6px; padding: 0 13px;
            font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer;
            background: var(--olmnp-surface); color: var(--olmnp-fg-strong);
            border: none; border-left: 1px solid var(--olmnp-border-strong);
        }
        .dx-copy-btn:hover { background: var(--olmnp-surface-alt); }
        .dx-copy-hint { margin: 0 0 16px; font-size: 11.5px; line-height: 1.5; color: var(--olmnp-fg-subtle); }
    </style>
        <div
            x-data="{
                left: @js($remainingSeconds),
                pending: @js($pending),
                timer: null,

                init() {
                    this.timer = setInterval(() => {
                        if (this.left > 0) this.left--;
                        this.check();
                    }, 1000);
                    this.check();
                },

                check() {
                    if (! this.pending.length) return;

                    // On sert le palier le PLUS URGENT franchi : quelqu'un qui revient
                    // après une longue absence en a pu franchir plusieurs d'un coup, et
                    // c'est l'urgence réelle qu'il faut lui montrer.
                    const hours = this.left / 3600;
                    let due = null;
                    for (const p of this.pending) {
                        if (hours <= p.hours) due = p;
                    }
                    if (! due) return;

                    this.pending = [];
                    $wire.reach(due.hours);
                },

                get label() {
                    const h = Math.floor(this.left / 3600);
                    const m = Math.floor((this.left % 3600) / 60);
                    return h > 0 ? h + ' h ' + String(m).padStart(2, '0') : m + ' min';
                },

                get long() {
                    const h = Math.floor(this.left / 3600);
                    const m = Math.floor((this.left % 3600) / 60);
                    return h > 0 ? h + ' h ' + String(m).padStart(2, '0') + ' min' : m + ' min';
                },

                get clock() {
                    const p = (n) => String(n).padStart(2, '0');
                    return p(Math.floor(this.left / 3600)) + ':' + p(Math.floor((this.left % 3600) / 60)) + ':' + p(this.left % 60);
                },

                get mood() {
                    if (this.left <= 3600) return 'is-urgent';
                    if (this.left <= 6 * 3600) return 'is-warn';
                    return 'is-calm';
                },
            }"
            x-on:beforeunload.window="clearInterval(timer)"
        >
            {{-- Pastille permanente : le seul élément visible en continu. --}}
            <button
                type="button"
                class="dx-pill"
                :class="mood"
                x-show="$wire.step === 'idle'"
                wire:click="openExtend"
                title="Durée restante de la démonstration"
            >
                <span class="dx-pill-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </span>
                <span class="dx-pill-label">Démo</span>
                <span class="dx-pill-time" x-text="label">{{ floor($remainingSeconds / 3600) }} h</span>
            </button>

            {{-- Bandeau : non bloquant, le panel reste utilisable derrière. --}}
            @if ($step === 'banner')
                <div class="dx-banner">
                    <div class="dx-banner-card">
                        <span class="dx-banner-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                        </span>
                        <div class="dx-banner-body">
                            <p class="dx-banner-title">
                                Cette démonstration s'efface dans <b x-text="long"></b>
                            </p>
                            <p class="dx-banner-sub">
                                @if ($atRisk)
                                    {{ $this->atRiskSentence() }} seront supprimés. Vous pouvez les garder.
                                @else
                                    Tout ce que vous saisirez ici sera supprimé. Vous pouvez le garder.
                                @endif
                            </p>
                        </div>
                        <div class="dx-banner-actions">
                            <button type="button" class="dx-btn dx-btn-primary dx-btn-small dx-btn-auto" wire:click="keepData">Garder mes données</button>
                            <button type="button" class="dx-btn dx-btn-ghost dx-btn-small" wire:click="dismiss">Plus tard</button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Modale : paliers graves, puis offre et prolongation. --}}
            @if (in_array($step, ['modal', 'offer', 'extend', 'extended'], true))
                <div class="dx-overlay" wire:key="dx-overlay-{{ $step }}">
                    <div class="dx-card @if ($step === 'offer') is-wide @endif" role="dialog" aria-modal="true" aria-labelledby="dx-title">
                        <button type="button" class="dx-dismiss" wire:click="dismiss" aria-label="Fermer">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                        </button>

                        @if ($step === 'modal')
                            <div class="dx-card-body dx-center">
                                <span class="dx-card-icon" :class="mood === 'is-urgent' ? 'is-urgent' : ''">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                    </svg>
                                </span>

                                <strong class="dx-count" :class="mood === 'is-urgent' ? 'is-urgent' : ''" x-text="clock"></strong>
                                <span class="dx-count-unit">avant effacement</span>

                                <h2 id="dx-title">Ne perdez pas votre travail</h2>
                                <p class="dx-lead">
                                    Cette démonstration est un bac à sable temporaire. Passé le compte à
                                    rebours, tout est supprimé — définitivement, et sans sauvegarde.
                                </p>

                                @if ($atRisk)
                                    <div class="dx-keep">
                                        <p class="dx-keep-title">Ce que vous perdriez</p>
                                        <ul>
                                            @foreach ($this->atRiskLines() as $line)
                                                <li>
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                                    {{ $line }}
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif

                                <button type="button" class="dx-btn dx-btn-primary" wire:click="keepData">Garder mes données</button>
                                <button type="button" class="dx-btn-link" wire:click="dismiss">Continuer la démonstration</button>
                            </div>
                        @endif

                        @if ($step === 'offer')
                            <div class="dx-card-head">
                                <span class="dx-card-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="23" height="23" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                                    </svg>
                                </span>
                                <h2 id="dx-title">Gardez votre dossier</h2>
                                <p class="dx-lead">Vos données sont reprises telles quelles. Rien à ressaisir, rien à réimporter.</p>
                            </div>

                            {{--
                                L'offre vit sur un AUTRE domaine, dans une iframe : ce dépôt est public
                                et ne porte aucun tarif. Le bouton de refus, lui, appartient au pied de
                                cette modale — hors du cadre. C'est ce qui évite tout postMessage :
                                le refus n'a jamais à traverser la frontière d'origine.
                            --}}
                            {{--
                                ⚠️ NE PAS se fier à l'événement `load` de l'iframe.

                                Vérifié au rendu le 2026-09-05 contre une vitrine renvoyant
                                `X-Frame-Options: SAMEORIGIN` : le navigateur refuse d'afficher
                                le cadre, écrit son refus dans la console — et déclenche QUAND
                                MÊME `load` sur la page d'erreur interne. Une garde
                                `x-on:load="loaded = true"` se croit donc satisfaite, et le
                                repli ne s'affiche jamais. Le cadre reste gris et muet : la
                                protection contre ce bug était elle-même victime du bug.

                                On attend donc un signal ÉMIS PAR LA PAGE ENCADRÉE. Un cadre
                                refusé ne s'exécute pas, donc ne peut pas le poster.

                                Ce message est unidirectionnel et ne porte qu'une constante :
                                « je suis vivante ». Le refus de l'offre, lui, ne traverse
                                toujours pas la frontière — il reste un bouton du pied de
                                modale, côté app.
                            --}}
                            <div
                                class="dx-frame"
                                x-data="{
                                    alive: false,
                                    origin: @js($offerOrigin),

                                    init() {
                                        window.addEventListener('message', (e) => {
                                            if (e.origin === this.origin && e.data === 'olmnp-migrer-ready') {
                                                this.alive = true;
                                            }
                                        });

                                        setTimeout(() => { if (! this.alive) this.$refs.fallback.hidden = false; }, @js($iframeTimeoutMs));
                                    },
                                }"
                            >
                                <iframe
                                    src="{{ $offerUrl }}"
                                    title="Formules OpenLMNP Cloud"
                                    class="dx-frame-iframe"
                                    x-show="alive"
                                    referrerpolicy="strict-origin-when-cross-origin"
                                ></iframe>

                                {{--
                                    Un cadre refusé par le serveur distant (X-Frame-Options,
                                    frame-ancestors) ne lève AUCUNE erreur exploitable : il reste
                                    simplement vide. Sans ce repli, une régression d'infra
                                    deviendrait un écran muet au moment précis où quelqu'un a dit oui.
                                --}}
                                <div class="dx-frame-fallback" x-ref="fallback" hidden>
                                    <p>L'affichage des formules a échoué.</p>
                                    <a class="dx-btn dx-btn-primary" href="{{ $offerUrl }}" target="_blank" rel="noopener">Voir les formules dans un onglet</a>
                                </div>
                            </div>

                            <div class="dx-card-foot">
                                <button type="button" class="dx-btn dx-btn-ghost" wire:click="declineOffer">Non merci, continuer la démonstration</button>
                                @if ($canExtend)
                                    <p class="dx-foot-hint">Vous pourrez prolonger votre bac à sable de 7 jours juste après.</p>
                                @endif
                            </div>
                        @endif

                        @if ($step === 'extend')
                            <div class="dx-card-body">
                                <div class="dx-center">
                                    <span class="dx-card-icon">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                                        </svg>
                                    </span>
                                    <h2 id="dx-title">Gardez-les {{ config('demo.extended_ttl_days') }} jours de plus</h2>
                                    <p class="dx-lead">
                                        Laissez votre adresse : votre bac à sable est prolongé jusqu'au
                                        <strong>{{ now()->addDays((int) config('demo.extended_ttl_days'))->translatedFormat('j F') }}</strong>,
                                        et vous recevez le lien pour y revenir depuis n'importe quel appareil.
                                    </p>
                                </div>

                                <form wire:submit="extend" class="dx-form">
                                    <label class="dx-label" for="dx-email">Votre adresse e-mail</label>
                                    <input class="dx-input" id="dx-email" type="email" wire:model="email" placeholder="vous@exemple.fr" autocomplete="email" required>
                                    @error('email') <p class="dx-error">{{ $message }}</p> @enderror

                                    <label class="dx-consent">
                                        <input type="checkbox" wire:model="consent" required>
                                        <span>
                                            J'accepte de recevoir mon lien de reprise et un rappel avant
                                            effacement. Aucune autre utilisation, désinscription en un clic.
                                        </span>
                                    </label>
                                    @error('consent') <p class="dx-error">Merci de cocher cette case pour recevoir le lien.</p> @enderror

                                    <button type="submit" class="dx-btn dx-btn-primary" wire:loading.attr="disabled">
                                        <span wire:loading.remove wire:target="extend">Prolonger de {{ config('demo.extended_ttl_days') }} jours</span>
                                        <span wire:loading wire:target="extend">Envoi…</span>
                                    </button>
                                </form>

                                <button type="button" class="dx-btn-link dx-center-block" wire:click="dismiss">Non, continuer sans laisser d'adresse</button>

                                <p class="dx-fineprint">Gratuit, sans carte bancaire. Votre adresse sert uniquement à ces deux envois.</p>
                            </div>
                        @endif

                        @if ($step === 'extended')
                            <div class="dx-card-body">
                                <div class="dx-center">
                                    <span class="dx-card-icon">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                        </svg>
                                    </span>
                                    <h2 id="dx-title">C'est prolongé</h2>
                                    <p class="dx-lead">
                                        Votre bac à sable vous attend jusqu'au
                                        <strong>{{ now()->addDays((int) config('demo.extended_ttl_days'))->translatedFormat('j F \à H\hi') }}</strong>.
                                    </p>
                                </div>

                                <div class="dx-confirm">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" fill="none" viewBox="0 0 24 24" stroke-width="1.9" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                                    </svg>
                                    <p>Le lien de reprise part à l'instant vers <b>{{ $email }}</b>. Un rappel vous sera envoyé avant l'effacement, puis plus rien.</p>
                                </div>

                                {{--
                                    Le lien est AFFICHÉ en plus d'être envoyé, et ce n'est pas une
                                    redondance : une adresse mal saisie ne se rattrape pas, et le
                                    courriel peut arriver en indésirable.
                                --}}
                                <label class="dx-label" for="dx-resume">Ou gardez le lien tout de suite</label>
                                <div class="dx-copy" x-data="{ copied: false }">
                                    <input class="dx-copy-url" id="dx-resume" type="text" readonly value="{{ $resumeUrl }}" x-on:focus="$el.select()">
                                    <button type="button" class="dx-copy-btn" x-on:click="navigator.clipboard.writeText(@js($resumeUrl)); copied = true; setTimeout(() => copied = false, 2000)">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="1.9" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" />
                                        </svg>
                                        <span x-text="copied ? 'Copié' : 'Copier'">Copier</span>
                                    </button>
                                </div>
                                <p class="dx-copy-hint">
                                    Ce lien vous reconnecte à ce bac à sable, depuis n'importe quel appareil
                                    et sans mot de passe. Traitez-le comme une clé : qui l'a, y entre.
                                </p>

                                <button type="button" class="dx-btn dx-btn-primary" wire:click="dismiss">Revenir à mon dossier</button>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
