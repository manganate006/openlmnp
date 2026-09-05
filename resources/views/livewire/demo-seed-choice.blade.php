{{--
    Sort réservé aux données d'exemple, au premier accès d'un compte promu.

    Chaque option ANNONCE son décompte réel plutôt qu'une formule vague : c'est ce qui
    distingue un choix éclairé d'un pari, et ça ne coûte rien — les chiffres sont en base.

    ⚠️ Aucune classe Tailwind : le panel Filament ne sert que ses propres classes `fi-*`.
    Les styles `dx-*` sont définis par `demo-expiry-prompt.blade.php`, qui n'est PAS monté
    pour un compte promu (il n'est plus une démonstration) — d'où le bloc ci-dessous.
--}}
<div>
    @if ($applies)
        <style>
            .dsc-overlay {
                position: fixed; inset: 0; z-index: 60;
                display: flex; align-items: center; justify-content: center; padding: 18px;
                background: color-mix(in oklab, var(--olmnp-code-bg) 68%, transparent);
            }
            .dsc-card {
                width: 100%; max-width: 470px; max-height: 100%; overflow: auto; padding: 22px;
                border-radius: 16px; background: var(--olmnp-surface);
                border: 1px solid var(--olmnp-border);
                box-shadow: 0 22px 48px color-mix(in oklab, var(--olmnp-code-bg) 34%, transparent);
            }
            .dsc-head { text-align: center; margin-bottom: 16px; }
            .dsc-icon {
                display: inline-flex; align-items: center; justify-content: center;
                width: 46px; height: 46px; margin-bottom: 12px; border-radius: 13px;
                background: var(--olmnp-success-bg-strong); color: var(--olmnp-success-accent);
            }
            .dsc-card h2 { margin: 0 0 6px; font-size: 17px; font-weight: 700; color: var(--olmnp-fg-strong); }
            .dsc-lead { margin: 0; font-size: 13.5px; line-height: 1.55; color: var(--olmnp-fg-muted); }
            .dsc-list { display: flex; flex-direction: column; gap: 9px; }
            .dsc-choice {
                display: flex; align-items: flex-start; gap: 11px; width: 100%; padding: 13px;
                border-radius: 12px; cursor: pointer; text-align: left; font: inherit;
                background: var(--olmnp-surface); border: 1px solid var(--olmnp-border-strong);
                transition: border-color .15s, background .15s, box-shadow .15s;
            }
            .dsc-choice:hover { border-color: var(--olmnp-success-border); background: var(--olmnp-surface-muted); }
            .dsc-choice.is-on {
                border-color: var(--olmnp-success-accent); background: var(--olmnp-success-bg);
                box-shadow: 0 0 0 1px var(--olmnp-success-accent);
            }
            .dsc-radio {
                flex: none; display: flex; align-items: center; justify-content: center;
                width: 18px; height: 18px; margin-top: 1px; border-radius: 999px;
                border: 1.5px solid var(--olmnp-border-strong); background: var(--olmnp-surface);
            }
            .dsc-choice.is-on .dsc-radio {
                border-color: var(--olmnp-success-accent); background: var(--olmnp-success-solid);
                color: var(--olmnp-on-solid);
            }
            .dsc-radio svg { opacity: 0; }
            .dsc-choice.is-on .dsc-radio svg { opacity: 1; }
            .dsc-body { flex: 1 1 auto; min-width: 0; }
            .dsc-title {
                display: flex; align-items: center; gap: 7px; flex-wrap: wrap; margin: 0 0 3px;
                font-size: 13.5px; font-weight: 600; color: var(--olmnp-fg-strong);
            }
            .dsc-badge {
                padding: 1px 7px; border-radius: 999px; font-size: 10.5px; font-weight: 700;
                letter-spacing: .03em; text-transform: uppercase;
                background: var(--olmnp-success-bg-strong); color: var(--olmnp-success-fg);
                border: 1px solid var(--olmnp-success-border);
            }
            .dsc-desc { margin: 0; font-size: 12.5px; line-height: 1.5; color: var(--olmnp-fg-muted); }
            .dsc-count { display: block; margin-top: 4px; font-size: 12px; color: var(--olmnp-fg-subtle); }
            .dsc-warn {
                display: flex; align-items: flex-start; gap: 9px; margin: 13px 0 0;
                padding: 10px 12px; border-radius: 10px;
                background: var(--olmnp-warning-bg); border: 1px solid var(--olmnp-warning-border);
            }
            .dsc-warn svg { flex: none; margin-top: 1px; color: var(--olmnp-warning-accent); }
            .dsc-warn p { margin: 0; font-size: 12px; line-height: 1.5; color: var(--olmnp-warning-fg); }
            .dsc-submit {
                display: inline-flex; align-items: center; justify-content: center; gap: 7px;
                width: 100%; margin-top: 14px; padding: 9px 15px; border-radius: 10px;
                font: inherit; font-size: 13.5px; font-weight: 600; cursor: pointer;
                background: var(--olmnp-success-solid); color: var(--olmnp-on-solid); border: none;
            }
            .dsc-submit:hover { background: var(--olmnp-success-solid-hover); }
            .dsc-fine { margin: 12px 0 0; text-align: center; font-size: 11.5px; color: var(--olmnp-fg-subtle); }
        </style>

        <div class="dsc-overlay">
            <div class="dsc-card" role="dialog" aria-modal="true" aria-labelledby="dsc-title">
                <div class="dsc-head">
                    <span class="dsc-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                        </svg>
                    </span>
                    <h2 id="dsc-title">Votre dossier est conservé</h2>
                    <p class="dsc-lead">
                        Tout ce que vous aviez pendant l'essai est là. Reste à décider du sort des
                        données d'exemple qui vous ont servi à découvrir le logiciel.
                    </p>
                </div>

                <div class="dsc-list">
                    @foreach ([
                        ['mine_only', 'Ne garder que mes saisies', 'Conseillé',
                         "Le bien d'exemple et ses exercices sont supprimés. Ce que vous avez saisi vous-même reste, et les totaux sont recalculés.",
                         $this->summaryFor('mine_only')],
                        ['keep_all', 'Tout garder', null,
                         "Le bien d'exemple reste à côté du vôtre. Vous pourrez le supprimer plus tard depuis la liste des biens.",
                         $this->summaryFor('keep_all')],
                        ['reset', 'Repartir de zéro', null,
                         "Le compte est vidé entièrement, y compris ce que vous avez saisi pendant l'essai. Vous recommencez sur une base vierge.",
                         $this->summaryFor('reset')],
                    ] as [$value, $label, $badge, $desc, $summary])
                        <button type="button" class="dsc-choice @if ($choice === $value) is-on @endif" wire:click="$set('choice', '{{ $value }}')">
                            <span class="dsc-radio">
                                <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" fill="none" viewBox="0 0 24 24" stroke-width="3.2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            </span>
                            <span class="dsc-body">
                                <span class="dsc-title">
                                    {{ $label }}
                                    @if ($badge)<span class="dsc-badge">{{ $badge }}</span>@endif
                                </span>
                                <span class="dsc-desc">{{ $desc }}</span>
                                <span class="dsc-count">{{ $summary }}</span>
                            </span>
                        </button>
                    @endforeach
                </div>

                <div class="dsc-warn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="1.9" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    <p>Ce choix ne se fait qu'une fois et rien n'est récupérable ensuite.</p>
                </div>

                <button type="button" class="dsc-submit" wire:click="apply" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="apply">Continuer</span>
                    <span wire:loading wire:target="apply">Application…</span>
                </button>

                <p class="dsc-fine">Un e-mail vient de vous être envoyé pour définir votre mot de passe.</p>
            </div>
        </div>
    @endif
</div>
