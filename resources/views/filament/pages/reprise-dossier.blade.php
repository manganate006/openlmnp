{{--
    Assistant « Reprendre un dossier existant » — rendu des 9 écrans de
    `_admin/docs/maquettes-reprise/wizard-reprise.html`.

    AUCUNE couleur en dur : tout passe par les jetons `--olmnp-*` de
    `filament/partials/theme-tokens.blade.php` (`PanelStylesheetTest` échoue sinon), et
    les deux thèmes sont donc couverts par construction.

    L'étape 3 inclut `filament/partials/depreciation-editor-core.blade.php` : c'est
    l'éditeur d'amortissements existant, avec ses deux modes, et non un troisième éditeur.
--}}
<x-filament-panels::page>
    {{-- Style + composant Alpine de l'éditeur : présents dès le premier rendu, sinon
         l'étape 3 apparaîtrait par un morphing Livewire, qui n'exécute pas les <script>. --}}
    @include('filament.partials.depreciation-editor-assets')

    <style>
        .rp-wizard { --rp-shadow: rgba(0, 0, 0, .10); }

        /* ---------- Cadre ---------- */
        .rp-screen {
            background: var(--olmnp-surface);
            border: 1px solid var(--olmnp-border);
            border-radius: 14px;
            box-shadow: 0 1px 2px var(--rp-shadow);
            overflow: hidden;
        }
        .rp-screen-head { padding: 16px 20px 0; }
        .rp-screen-head h2 { margin: 0 0 2px; font-size: 17px; font-weight: 600; color: var(--olmnp-fg-strong); }
        .rp-screen-head p { margin: 0; font-size: 13px; color: var(--olmnp-fg-muted); }
        .rp-body { padding: 18px 20px 20px; font-size: 14px; line-height: 1.5; color: var(--olmnp-fg); }

        /* ---------- Fil des étapes ---------- */
        .rp-steps { display: flex; gap: 4px; margin: 16px 0 0; padding: 0 20px 14px; border-bottom: 1px solid var(--olmnp-border); overflow-x: auto; }
        .rp-step { display: flex; align-items: center; gap: 7px; padding: 6px 10px; border-radius: 9px; white-space: nowrap; font-size: 12.5px; color: var(--olmnp-fg-muted); background: none; border: none; font-family: inherit; }
        .rp-step-done { cursor: pointer; }
        .rp-num {
            display: inline-flex; align-items: center; justify-content: center;
            width: 20px; height: 20px; border-radius: 999px; font-size: 11.5px; font-weight: 600;
            background: var(--olmnp-surface-alt); color: var(--olmnp-fg-muted);
            border: 1px solid var(--olmnp-border-strong);
        }
        .rp-step-done { color: var(--olmnp-fg); }
        .rp-step-done .rp-num { background: var(--olmnp-success-bg-strong); color: var(--olmnp-success-fg); border-color: var(--olmnp-success-border); }
        .rp-step-active { background: var(--olmnp-success-bg); color: var(--olmnp-success-fg); font-weight: 600; }
        .rp-step-active .rp-num { background: var(--olmnp-success-solid); color: var(--olmnp-on-solid); border-color: var(--olmnp-success-solid); }

        /* ---------- Champs ---------- */
        .rp-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr)); gap: 14px; }
        .rp-field label { display: block; font-size: 12.5px; font-weight: 600; color: var(--olmnp-fg-strong); margin-bottom: 5px; }
        .rp-hint { display: block; margin-top: 5px; font-size: 11.5px; color: var(--olmnp-fg-muted); }
        .rp-cerfa { font-weight: 600; color: var(--olmnp-info-accent); }
        .rp-error { display: block; margin-top: 5px; font-size: 11.5px; font-weight: 600; color: var(--olmnp-danger-accent); }
        /* Lecture IA de la liasse (lot 6) */
        .rp-choice-disabled { opacity: .55; cursor: not-allowed; }
        /* Le motif d'indisponibilité ne doit PAS être délavé avec la carte : c'est la seule
           chose qui reste utile quand l'option est coupée. Il remonte donc à pleine opacité,
           sur fond d'avertissement, pour se distinguer du texte descriptif éteint. */
        .rp-choice-disabled .rp-error { opacity: 1; margin-top: 10px; padding: 8px 10px; border-radius: 8px;
            background: var(--olmnp-warning-bg); color: var(--olmnp-warning-fg);
            border: 1px solid var(--olmnp-warning-border); font-size: 12.5px; }
        .rp-ai-consent { display: flex; gap: 10px; align-items: flex-start; font-size: 13px; cursor: pointer; }
        .rp-ai-consent input { margin-top: 3px; }

        /*
            Zone de dépôt de la liasse — seul dépôt « nu » du dépôt jusqu'au 2026-09-24.
            Le navigateur rendait « Choisir un fichier / Aucun fichier choisi », minuscule et
            introuvable sur un écran qui demande par ailleurs un consentement.

            ⚠️ L'`<input type="file">` reste DANS LE FLUX et focalisable : il couvre tout le
            bloc en `opacity: 0`, jamais `display: none` (qui le sortirait du parcours de
            tabulation). Trois conséquences, toutes voulues :
              - le clic n'importe où dans le cadre ouvre le sélecteur, sans une ligne de JS ;
              - le glisser-déposer est celui du navigateur — l'input reçoit le fichier même
                si Alpine ne s'est jamais chargé (le JS n'ajoute que le surlignage) ;
              - la tabulation atteint le champ, et `:focus-within` dessine l'anneau sur le
                cadre, puisque l'input lui-même est invisible.
        */
        .rp-drop {
            position: relative; display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: 7px;
            width: 100%; height: 200px; padding: 16px; text-align: center;
            border: 2px dashed var(--olmnp-border-strong); border-radius: 14px;
            background: var(--olmnp-surface-muted);
        }
        .rp-drop:hover { border-color: var(--olmnp-success-solid); background: var(--olmnp-success-bg); }
        .rp-drop:focus-within { border-color: var(--olmnp-success-solid); box-shadow: 0 0 0 3px var(--olmnp-success-bg-strong); }
        .rp-drop-over { border-color: var(--olmnp-success-solid); background: var(--olmnp-success-bg); }
        .rp-drop-input { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
        .rp-drop-icon { color: var(--olmnp-fg-subtle); line-height: 0; }
        .rp-drop:hover .rp-drop-icon, .rp-drop-over .rp-drop-icon { color: var(--olmnp-success-accent); }
        .rp-drop-title { font-size: 14.5px; font-weight: 600; color: var(--olmnp-fg-strong); }
        .rp-drop-sub { font-size: 12px; color: var(--olmnp-fg-muted); }
        .rp-drop-cta {
            margin-top: 2px; padding: 7px 15px; border-radius: 9px; font-size: 13px; font-weight: 600;
            background: var(--olmnp-success-solid); color: var(--olmnp-on-solid);
        }
        /* Nom du fichier retenu : le rendu natif du navigateur l'affichait, notre cadre le
           masque — il faut donc le réécrire nous-mêmes, sinon on perd l'information. */
        .rp-drop-file {
            display: inline-flex; align-items: center; gap: 6px; margin-top: 10px;
            padding: 5px 11px; border-radius: 999px; font-size: 12.5px; font-weight: 600;
            background: var(--olmnp-success-bg-strong); color: var(--olmnp-success-fg);
        }
        @keyframes rp-ai-rot { to { transform: rotate(360deg); } }

        /*
            Attente de la lecture — MODALE CENTRÉE.

            ⚠️ `display: none` ici, et c'est volontaire : `wire:loading.flex` pose un
            `display: flex` EN LIGNE, qui l'emporte sur cette règle. Sans elle, le voile
            clignoterait entre le premier rendu et l'amorçage de Livewire.
            `.rp-modal-gone` reprend la main avec `!important`, seule façon de battre un
            style en ligne — c'est ce qui permet de masquer la fenêtre sans la fermer.
        */
        .rp-modal-backdrop {
            position: fixed; inset: 0; z-index: 40; display: none;
            align-items: center; justify-content: center; padding: 20px;
            background: rgba(0, 0, 0, .55);
        }
        .rp-modal-gone { display: none !important; }
        .rp-modal {
            width: min(470px, 100%); max-height: 100%; overflow-y: auto;
            padding: 26px 24px; text-align: center;
            background: var(--olmnp-surface); color: var(--olmnp-fg);
            border: 1px solid var(--olmnp-border); border-radius: 16px;
            box-shadow: 0 18px 40px rgba(0, 0, 0, .3);
        }
        .rp-modal h3 { margin: 12px 0 6px; font-size: 16px; font-weight: 600; color: var(--olmnp-fg-strong); }
        .rp-modal p { margin: 0; font-size: 13px; color: var(--olmnp-fg-muted); line-height: 1.5; }
        .rp-modal-spin {
            display: inline-block; width: 26px; height: 26px; border-radius: 50%;
            border: 3px solid var(--olmnp-border-strong); border-top-color: var(--olmnp-success-solid);
            animation: rp-ai-rot .8s linear infinite;
        }
        .rp-modal-tip {
            margin-top: 18px; padding: 11px 13px; border-radius: 10px; font-size: 12.5px; text-align: left;
            background: var(--olmnp-info-bg); color: var(--olmnp-info-fg); border: 1px solid var(--olmnp-info-border);
        }
        .rp-modal-tip b { display: block; margin-bottom: 4px; font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; }
        .rp-modal-late {
            margin-top: 12px; padding: 10px 12px; border-radius: 10px; font-size: 12.5px; text-align: left;
            background: var(--olmnp-warning-bg); color: var(--olmnp-warning-fg); border: 1px solid var(--olmnp-warning-border);
        }
        .rp-modal-foot { margin-top: 18px; display: flex; justify-content: center; }
        .rp-modal-note { margin-top: 8px; font-size: 11.5px; color: var(--olmnp-fg-subtle); }

        /* Refus de ventilation : une modale QUI AGIT, pas seulement qui explique. */
        .rp-modal-open { display: flex; }
        .rp-modal-wide { width: min(620px, 100%); text-align: left; }
        .rp-modal-wide h3 { margin: 0 0 8px; }
        .rp-modal-figures { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin: 14px 0; }
        .rp-modal-figure { padding: 10px 12px; border-radius: 10px; background: var(--olmnp-surface-muted); border: 1px solid var(--olmnp-border); }
        .rp-modal-figure b { display: block; font-size: 16px; color: var(--olmnp-fg-strong); font-variant-numeric: tabular-nums; }
        .rp-modal-figure span { font-size: 11.5px; color: var(--olmnp-fg-muted); }
        .rp-modal-figure-bad b { color: var(--olmnp-danger-accent); }
        .rp-modal-figure-ok b { color: var(--olmnp-success-accent); }
        .rp-modal-action { margin-top: 14px; padding: 13px 14px; border-radius: 11px; border: 1px solid var(--olmnp-border); background: var(--olmnp-surface-muted); }
        .rp-modal-action h4 { margin: 0 0 4px; font-size: 13px; color: var(--olmnp-fg-strong); }
        .rp-modal-action p { margin: 0 0 10px; font-size: 12px; }
        .rp-modal-rows { list-style: none; margin: 0 0 10px; padding: 0; max-height: 210px; overflow-y: auto; }
        .rp-modal-row { display: flex; align-items: center; gap: 9px; padding: 5px 0; font-size: 12.5px; color: var(--olmnp-fg); border-bottom: 1px solid var(--olmnp-border); }
        .rp-modal-row:last-child { border-bottom: none; }
        .rp-modal-row-amount { margin-left: auto; font-variant-numeric: tabular-nums; color: var(--olmnp-fg-muted); }
        .rp-modal-row-off { color: var(--olmnp-fg-subtle); text-decoration: line-through; }
        /*
            Relecture : les deux tableaux LUS côte à côte en haut, l'aperçu du document sur
            toute la largeur en dessous.

            ⚠️ Jusqu'au 2026-09-24 l'aperçu occupait la colonne de gauche (5fr contre 7fr),
            pour permettre une comparaison œil à œil qui, en pratique, n'existait pas : une
            liasse affichée sur un demi-écran est ILLISIBLE. La comparaison passe donc par le
            défilement — ce qui n'a de sens que si l'aperçu devient réellement exploitable,
            d'où la barre d'outils (pagination, zoom) et une hauteur qui montre une page
            entière au lieu des 420 px d'avant.
        */
        .rp-ai-review { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin-top: 14px; }
        @media (max-width: 900px) { .rp-ai-review { grid-template-columns: 1fr; } }
        .rp-ai-doc { margin-top: 16px; }
        .rp-ai-doc-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 8px; }
        .rp-ai-doc-label { margin-right: auto; font-size: 13px; font-weight: 600; color: var(--olmnp-fg-strong); }
        .rp-ai-doc-page { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: var(--olmnp-fg-muted); }
        .rp-ai-doc-page input {
            width: 58px; padding: 4px 6px; font: inherit; font-size: 12px; text-align: center;
            background: var(--olmnp-surface); color: var(--olmnp-fg-strong);
            border: 1px solid var(--olmnp-border-strong); border-radius: 7px;
        }
        .rp-ai-doc-zoom { min-width: 46px; text-align: center; font-size: 12px; font-variant-numeric: tabular-nums; color: var(--olmnp-fg-muted); }
        /* Hauteur d'une page entière, bornée pour ne pas dévorer l'écran sur mobile. */
        .rp-ai-doc iframe {
            display: block; width: 100%; height: min(78vh, 960px); min-height: 520px;
            border: 1px solid var(--olmnp-border); border-radius: 10px; background: var(--olmnp-surface-alt);
        }
        .rp-ai-doc-scroll {
            max-height: min(78vh, 960px); overflow: auto;
            border: 1px solid var(--olmnp-border); border-radius: 10px; background: var(--olmnp-surface-alt);
        }
        .rp-ai-doc-scroll img { display: block; width: 100%; }
        @media (max-width: 900px) {
            .rp-ai-doc iframe { height: 60vh; min-height: 340px; }
            .rp-ai-doc-scroll { max-height: 60vh; }
        }
        .rp-ai-read h4 { margin: 0 0 6px; font-size: 13px; }
        .rp-ai-read h4 + .rp-ai-table { margin-bottom: 14px; }
        .rp-ai-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .rp-ai-table th, .rp-ai-table td { padding: 5px 8px; border-bottom: 1px solid var(--olmnp-border); text-align: left; }
        /* Ligne déjà portée par l'application : signalée par la LIGNE, pas par une
           pastille dans la cellule du libellé. La pastille passait à la ligne dans une
           colonne étroite et n'allongeait que les lignes qui la portaient.
           ⚠️ WCAG 1.4.1 : le repère « ↩ » et l'intitulé accessible de la ligne portent
           l'information sans la couleur. Aucun des deux ne prend de hauteur.
           ⚠️ Déclarée AVANT `.rp-ai-doubt` : une ligne peut être les deux, et c'est le
           doute qui doit rester visible. */
        .rp-ai-carried > td { background: var(--olmnp-info-bg); }
        .rp-ai-carried > td:first-child { box-shadow: inset 3px 0 0 var(--olmnp-info-accent); }
        .rp-ai-mark { color: var(--olmnp-info-accent); font-weight: 700; margin-right: 4px; }
        .rp-ai-legend { margin-top: 8px; font-size: 11.5px; color: var(--olmnp-fg-muted); }
        .rp-ai-doubt td { background: var(--olmnp-warning-bg); }
        .rp-ai-badge { font-size: 10.5px; font-weight: 700; padding: 1px 7px; border-radius: 99px; white-space: nowrap; }
        .rp-ai-badge-high { background: var(--olmnp-success-bg); color: var(--olmnp-success-fg); }
        .rp-ai-badge-medium { background: var(--olmnp-warning-bg); color: var(--olmnp-warning-fg); }
        .rp-ai-badge-low { background: var(--olmnp-danger-bg); color: var(--olmnp-danger-fg); }
        .rp-ai-empty { font-size: 12.5px; color: var(--olmnp-fg-muted); }
        .rp-input {
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            padding: 0 11px; border-radius: 9px; font-size: 13.5px;
            background: var(--olmnp-surface); color: var(--olmnp-fg-strong);
            border: 1px solid var(--olmnp-border-strong);
        }
        .rp-input input, .rp-input select {
            flex: 1 1 auto; min-width: 0; padding: 8px 0; font: inherit; font-size: 13.5px;
            background: transparent; color: var(--olmnp-fg-strong); border: none; outline: none;
        }
        .rp-input select option { background: var(--olmnp-surface); color: var(--olmnp-fg-strong); }
        .rp-input i { font-style: normal; color: var(--olmnp-fg-subtle); font-size: 12px; }
        .rp-input-bad { border-color: var(--olmnp-danger-border); }
        .rp-readonly { color: var(--olmnp-fg-muted); }

        /* ---------- Choix ---------- */
        .rp-choices { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(260px, 100%), 1fr)); gap: 12px; }
        .rp-choice {
            display: block; width: 100%; text-align: left; font-family: inherit;
            padding: 13px 14px; border-radius: 12px; cursor: pointer;
            background: var(--olmnp-surface); border: 1px solid var(--olmnp-border-strong);
        }
        .rp-choice b { display: block; font-size: 13.5px; color: var(--olmnp-fg-strong); margin-bottom: 3px; }
        .rp-choice span { font-size: 12.5px; color: var(--olmnp-fg-muted); }
        .rp-choice-on { border-color: var(--olmnp-success-solid); background: var(--olmnp-success-bg); }
        .rp-choice-on b { color: var(--olmnp-success-fg); }
        .rp-choice-on span { color: var(--olmnp-success-fg); }
        .rp-choice-meta {
            display: block; width: fit-content; margin-top: 9px; padding: 2px 9px; border-radius: 999px;
            font-size: 11px; font-weight: 600;
            background: var(--olmnp-surface-alt); color: var(--olmnp-fg-muted);
        }
        .rp-choice-on .rp-choice-meta { background: var(--olmnp-success-bg-strong); color: var(--olmnp-success-fg); }
        .rp-pros { list-style: none; margin: 11px 0 0; padding: 0; font-size: 12px; }
        .rp-pros li { position: relative; padding: 2.5px 0 2.5px 19px; color: var(--olmnp-fg); }
        .rp-pros li::before { position: absolute; left: 1px; top: 2.5px; font-weight: 700; line-height: 1.45; }
        .rp-pros li.pro::before { content: "\2713"; color: var(--olmnp-success-accent); }
        .rp-pros li.con::before { content: "\2717"; color: var(--olmnp-danger-accent); }

        /* ---------- Encarts ---------- */
        .rp-note { margin-top: 16px; padding: 12px 14px; border-radius: 11px; font-size: 12.5px; }
        .rp-note b { display: block; margin-bottom: 3px; font-size: 13px; }
        .rp-note-info { background: var(--olmnp-info-bg); border: 1px solid var(--olmnp-info-border); color: var(--olmnp-info-fg); }
        .rp-note-warn { background: var(--olmnp-warning-bg); border: 1px solid var(--olmnp-warning-border); color: var(--olmnp-warning-fg); }
        .rp-note-ok { background: var(--olmnp-success-bg); border: 1px solid var(--olmnp-success-border); color: var(--olmnp-success-fg); }
        .rp-note-bad { background: var(--olmnp-danger-bg); border: 1px solid var(--olmnp-danger-border); color: var(--olmnp-danger-fg); }
        .rp-note ul { margin: 8px 0 0; padding-left: 18px; }
        .rp-note li { margin-bottom: 4px; }
        .rp-note-lead { font-weight: 600; }

        /* ---------- Tableaux ---------- */
        .rp-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .rp-table th {
            text-align: left; font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em;
            color: var(--olmnp-fg-muted); padding: 8px 10px; border-bottom: 1px solid var(--olmnp-border);
        }
        .rp-table td { padding: 9px 10px; border-bottom: 1px solid var(--olmnp-border); color: var(--olmnp-fg); }
        .rp-table td.rp-num-cell, .rp-table th.rp-num-cell { text-align: right; font-variant-numeric: tabular-nums; }
        .rp-table tbody tr:last-child td { border-bottom: none; }
        .rp-table i { font-style: normal; color: var(--olmnp-fg-muted); font-size: 11.5px; }
        .rp-pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .rp-pill-ok { background: var(--olmnp-success-bg-strong); color: var(--olmnp-success-fg); }
        .rp-pill-warn { background: var(--olmnp-warning-bg-strong); color: var(--olmnp-warning-fg); }
        .rp-pill-bad { background: var(--olmnp-danger-bg-strong); color: var(--olmnp-danger-fg); }
        .rp-pill-muted { background: var(--olmnp-surface-alt); color: var(--olmnp-fg-muted); }
        .rp-pill-custom { background: var(--olmnp-accent-bg); color: var(--olmnp-accent-fg); border: 1px solid var(--olmnp-accent-border); }

        /* ---------- Boutons ---------- */
        .rp-actions { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--olmnp-border); }
        .rp-actions-left { display: flex; gap: 8px; flex-wrap: wrap; }
        .rp-btn { display: inline-flex; align-items: center; gap: 7px; padding: 8px 14px; border-radius: 9px; font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid transparent; font-family: inherit; }
        .rp-btn-primary { background: var(--olmnp-success-solid); color: var(--olmnp-on-solid); }
        .rp-btn-primary:hover { background: var(--olmnp-success-solid-hover); }
        .rp-btn-ghost { background: var(--olmnp-surface); color: var(--olmnp-fg); border-color: var(--olmnp-border-strong); }
        .rp-btn-ghost:hover { background: var(--olmnp-surface-alt); }
        .rp-btn-tiny { padding: 3px 9px; font-size: 11px; border-radius: 999px; }
        .rp-inline-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }

        /* ---------- Récapitulatif final ---------- */
        .rp-recap { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); gap: 12px; }
        .rp-recap-card { padding: 13px 14px; border-radius: 12px; background: var(--olmnp-surface-muted); border: 1px solid var(--olmnp-border); }
        .rp-recap-card b { display: block; font-size: 19px; color: var(--olmnp-fg-strong); font-variant-numeric: tabular-nums; }
        .rp-recap-card span { font-size: 12px; color: var(--olmnp-fg-muted); }
    </style>

    <div class="rp-wizard">
        @if($this->finished)
            {{-- ============ Fin — ce qui a été repris, et ce qui ne l'a pas été ============ --}}
            @php $recap = $this->recap(); @endphp
            <div class="rp-screen">
                <div class="rp-body">
                    <div class="rp-recap">
                        <div class="rp-recap-card">
                            <b>{{ $this->formatEuros($recap['annual_depreciation']) }}</b>
                            <span>Dotation d'amortissement {{ $this->firstYear }}</span>
                        </div>
                        <div class="rp-recap-card">
                            <b>{{ $this->formatEuros($recap['deferred']) }}</b>
                            <span>Amortissements différés reportés</span>
                        </div>
                        <div class="rp-recap-card">
                            <b>{{ $this->formatEuros($recap['deficits']) }}</b>
                            <span>
                                @if($recap['deficit_expiry'])
                                    Déficit imputable jusqu'en {{ $recap['deficit_expiry'] }}
                                @else
                                    Aucun déficit reportable
                                @endif
                            </span>
                        </div>
                        <div class="rp-recap-card">
                            <b>{{ $this->formatEuros($recap['accumulated']) }}</b>
                            <span>Cumul d'amortissements repris</span>
                        </div>
                    </div>

                    <div class="rp-note rp-note-info">
                        <b>Ce qui n'a pas été repris, volontairement.</b>
                        <ul>
                            <li>Vos <strong>recettes et charges des années passées</strong> : elles ne servent plus à rien, vos reports sont saisis. Si vous les voulez quand même pour l'historique, l'import CSV les accepte.</li>
                            <li>Vos <strong>justificatifs antérieurs</strong> : à déposer au fil de l'eau, ou en lot depuis la page Documents.</li>
                            <li>Vos <strong>liasses passées</strong> : elles restent celles de votre comptable, OpenLMNP ne les réécrit pas.</li>
                        </ul>
                    </div>

                    <div class="rp-actions">
                        <button type="button" class="rp-btn rp-btn-ghost" wire:click="reviewReprise">Revoir ma reprise</button>
                        <a class="rp-btn rp-btn-primary" href="{{ \App\Filament\Pages\FiscalYearWizard::getUrl() }}">
                            Créer mon exercice {{ $this->firstYear }}
                        </a>
                    </div>
                </div>
            </div>
        @else
            <div class="rp-screen">
                {{-- ============ Fil des étapes ============ --}}
                <nav class="rp-steps">
                    @foreach($this->steps() as $index => $stepDefinition)
                        @php $number = $index + 1; @endphp
                        <button
                            type="button"
                            @class([
                                'rp-step',
                                'rp-step-done' => $number < $this->step,
                                'rp-step-active' => $number === $this->step,
                            ])
                            @if($number < $this->step) wire:click="goToStep({{ $number }})" @endif
                        >
                            <span class="rp-num">{{ $number < $this->step ? '✓' : $number }}</span>{{ $stepDefinition['title'] }}
                        </button>
                    @endforeach
                </nav>

                <div class="rp-body">
                    {{-- ==================== Étape 1 — votre situation ==================== --}}
                    @if($this->step === 1)
                        <div class="rp-grid">
                            <div class="rp-field">
                                <label for="rp-rental-start">Depuis quand louez-vous ce bien ?</label>
                                <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['rentalStartDate'])])>
                                    {{-- Vrai sélecteur de date, comme les dates de début de l'éditeur
                                         d'amortissements : la page ne mélange plus deux conventions.
                                         `type="date"` rend et attend de l'ISO 8601 — c'est ce que
                                         `parseDate()` lit et ce que `displayDate()` écrit. --}}
                                    <input id="rp-rental-start" type="date" max="{{ date('Y-m-d') }}" wire:model="rentalStartDate">
                                </div>
                                <span class="rp-hint">C'est la date qui fait démarrer vos amortissements.</span>
                                @isset($this->stepErrors['rentalStartDate'])
                                    <span class="rp-error">{{ $this->stepErrors['rentalStartDate'] }}</span>
                                @endisset
                            </div>
                            <div class="rp-field">
                                <label for="rp-first-year">Première année à tenir dans OpenLMNP</label>
                                <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['firstYear'])])>
                                    <select id="rp-first-year" wire:model="firstYear">
                                        @foreach($this->yearOptions() as $year)
                                            <option value="{{ $year }}">{{ $year }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <span class="rp-hint">
                                    Votre comptable a établi la liasse jusqu'à {{ $this->referenceYear() }} :
                                    nous reprenons à {{ $this->firstYear }}.
                                </span>
                                @isset($this->stepErrors['firstYear'])
                                    <span class="rp-error">{{ $this->stepErrors['firstYear'] }}</span>
                                @endisset
                            </div>
                        </div>

                        <div class="rp-field" style="margin-top:14px">
                            <label>Avez-vous déjà déclaré au régime réel ?</label>
                            <div class="rp-choices">
                                @foreach([
                                    \App\Filament\Pages\RepriseDossier::REGIME_SINCE_START => 'Mes amortissements courent depuis la mise en location.',
                                    \App\Filament\Pages\RepriseDossier::REGIME_SINCE_YEAR => 'J\'étais au micro-BIC avant.',
                                    \App\Filament\Pages\RepriseDossier::REGIME_NEW => 'Aucun amortissement pratiqué jusqu\'ici.',
                                ] as $value => $description)
                                    <button
                                        type="button"
                                        @class(['rp-choice', 'rp-choice-on' => $this->regime === $value])
                                        wire:click="$set('regime', '{{ $value }}')"
                                    >
                                        <b>{{ \App\Filament\Pages\RepriseDossier::regimeLabels()[$value] }}</b>
                                        <span>{{ $description }}</span>
                                    </button>
                                @endforeach
                            </div>
                            @if($this->regime === \App\Filament\Pages\RepriseDossier::REGIME_SINCE_YEAR)
                                <div class="rp-field" style="margin-top:12px;max-width:min(380px,100%)">
                                    {{-- La largeur suit l'INTITULÉ, pas le champ : bridé à 260px, « Depuis quelle année
                                         déclarez-vous au réel ? » se cassait en deux lignes avec le « ? » seul sur la
                                         seconde. Le champ, lui, ne reçoit qu'une année : on le borne séparément. --}}
                                    <label for="rp-regime-year">Depuis quelle année déclarez-vous au réel ?</label>
                                    <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['regimeSinceYear'])]) style="max-width:150px">
                                        <input id="rp-regime-year" type="number" wire:model="regimeSinceYear" min="2000" max="{{ date('Y') }}">
                                    </div>
                                    @isset($this->stepErrors['regimeSinceYear'])
                                        <span class="rp-error">{{ $this->stepErrors['regimeSinceYear'] }}</span>
                                    @endisset
                                </div>
                            @endif
                        </div>

                        @if($this->regime === \App\Filament\Pages\RepriseDossier::REGIME_NEW)
                            <div class="rp-note rp-note-warn">
                                <b>Vous n'avez rien à reprendre.</b>
                                Sans amortissement pratiqué avant cette année, il n'y a ni cumul, ni report à saisir.
                                Vous pouvez continuer — l'étape 3 vous proposera la ventilation automatique, qui est
                                le bon choix dans ce cas — ou passer directement par « Premier lancement ».
                            </div>
                        @else
                            <div class="rp-note rp-note-info">
                                <b>Vous n'aurez pas à ressaisir vos exercices passés.</b>
                                Trois chiffres lus sur votre liasse {{ $this->referenceYear() }} suffisent. À la fin,
                                nous vérifierons avec vous que nos calculs tombent exactement sur les siens.
                            </div>
                        @endif

                        <div class="rp-actions">
                            <span></span>
                            <button type="button" class="rp-btn rp-btn-primary" wire:click="nextStep">Continuer</button>
                        </div>
                    @endif

                    {{-- ==================== Étape 2 — votre bien ==================== --}}
                    @if($this->step === 2)
                        @if($this->propertyId === null)
                            <div class="rp-note rp-note-info" style="margin-top:0">
                                <b>Ce bien n'existe pas encore dans OpenLMNP.</b>
                                Nous avons besoin de son identité pour le créer. Vous pourrez tout modifier ensuite
                                depuis « Mes biens ».
                            </div>
                            <div class="rp-grid" style="margin-top:16px">
                                <div class="rp-field">
                                    <label for="rp-name">Nom du bien</label>
                                    <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['propertyName'])])>
                                        <input id="rp-name" type="text" wire:model="propertyName" placeholder="Studio Bordeaux">
                                    </div>
                                    @isset($this->stepErrors['propertyName'])
                                        <span class="rp-error">{{ $this->stepErrors['propertyName'] }}</span>
                                    @endisset
                                </div>
                                {{-- Autocomplétion par la Base Adresse Nationale : les trois attributs
                                     suffisent, le script vit dans
                                     `filament/partials/address-autocomplete`, posé sur toutes les
                                     pages du panel. La saisie manuelle reste entière. --}}
                                <div class="rp-field">
                                    <label for="rp-address">Adresse</label>
                                    <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['propertyAddress'])])>
                                        <input id="rp-address" type="text" wire:model="propertyAddress"
                                               data-address-group="reprise" data-address-role="address">
                                    </div>
                                    <span class="rp-hint">Commencez à taper : les adresses de la Base Adresse Nationale vous sont proposées.</span>
                                    @isset($this->stepErrors['propertyAddress'])
                                        <span class="rp-error">{{ $this->stepErrors['propertyAddress'] }}</span>
                                    @endisset
                                </div>
                                <div class="rp-field">
                                    <label for="rp-city">Ville</label>
                                    <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['propertyCity'])])>
                                        <input id="rp-city" type="text" wire:model="propertyCity"
                                               data-address-group="reprise" data-address-role="city">
                                    </div>
                                    @isset($this->stepErrors['propertyCity'])
                                        <span class="rp-error">{{ $this->stepErrors['propertyCity'] }}</span>
                                    @endisset
                                </div>
                                <div class="rp-field">
                                    <label for="rp-postal">Code postal</label>
                                    <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['propertyPostalCode'])])>
                                        <input id="rp-postal" type="text" wire:model="propertyPostalCode"
                                               data-address-group="reprise" data-address-role="postal_code">
                                    </div>
                                    @isset($this->stepErrors['propertyPostalCode'])
                                        <span class="rp-error">{{ $this->stepErrors['propertyPostalCode'] }}</span>
                                    @endisset
                                </div>
                                <div class="rp-field">
                                    <label for="rp-area">Surface louée</label>
                                    <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['propertyArea'])])>
                                        <input id="rp-area" type="number" wire:model="propertyArea" min="1">
                                        <i>m²</i>
                                    </div>
                                    <span class="rp-hint">Bien loué en entier. Si vous ne louez qu'une partie, ajustez la quote-part dans « Mes biens ».</span>
                                    @isset($this->stepErrors['propertyArea'])
                                        <span class="rp-error">{{ $this->stepErrors['propertyArea'] }}</span>
                                    @endisset
                                </div>
                                <div class="rp-field">
                                    <label for="rp-acquisition-date">Date d'acquisition</label>
                                    <div class="rp-input">
                                        <input id="rp-acquisition-date" type="date" max="{{ date('Y-m-d') }}" wire:model="acquisitionDate">
                                    </div>
                                    <span class="rp-hint">Laissée vide, la date de mise en location est retenue.</span>
                                </div>
                            </div>
                        @endif

                        <div class="rp-grid" @if($this->propertyId === null) style="margin-top:14px" @endif>
                            <div class="rp-field">
                                <label for="rp-price">Prix d'acquisition</label>
                                <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['acquisitionPrice'])])>
                                    <input id="rp-price" type="text" inputmode="decimal" wire:model.live.debounce.600ms="acquisitionPrice">
                                    <i>€</i>
                                </div>
                                <span class="rp-hint">
                                    Le prix payé pour le bien lui-même, terrain compris. Les frais de notaire et
                                    d'agence, les travaux et le mobilier se saisissent à part.
                                    Sur votre balance, c'est la somme du compte 211 (terrain) et des comptes 213
                                    et suivants (constructions).
                                </span>
                                @isset($this->stepErrors['acquisitionPrice'])
                                    <span class="rp-error">{{ $this->stepErrors['acquisitionPrice'] }}</span>
                                @endisset
                            </div>
                            <div class="rp-field">
                                <label for="rp-notary">Frais de notaire</label>
                                <div class="rp-input">
                                    <input id="rp-notary" type="text" inputmode="decimal" wire:model="notaryFees">
                                    <i>€</i>
                                </div>
                            </div>
                            <div class="rp-field">
                                <label for="rp-agency">Honoraires d'agence</label>
                                <div class="rp-input">
                                    <input id="rp-agency" type="text" inputmode="decimal" wire:model="agencyFees">
                                    <i>€</i>
                                </div>
                            </div>
                            <div class="rp-field">
                                <label for="rp-land">Part du terrain</label>
                                <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['landPercentage'])])>
                                    {{-- ⚠️ `step` est OBLIGATOIRE : sans lui un input[type=number] vaut `step="1"` et le
                                         navigateur REFUSE 17,5 — une part de terrain d'acte parfaitement
                                         ordinaire. Signalé le 2026-09-09 : « votre cellule ne semble pas
                                         prendre en compte les décimales ». --}}
                                    <input id="rp-land" type="number" wire:model.live="landPercentage" min="0" max="99" step="0.01">
                                    <i>%</i>
                                </div>
                                <span class="rp-hint">Non amortissable. Reprenez la valeur retenue par votre comptable.</span>
                                @isset($this->stepErrors['landPercentage'])
                                    <span class="rp-error">{{ $this->stepErrors['landPercentage'] }}</span>
                                @endisset
                            </div>
                            <div class="rp-field">
                                <label for="rp-fees-treatment">Traitement des frais d'acquisition</label>
                                <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['acquisitionFeesTreatment'])])>
                                    <select id="rp-fees-treatment" wire:model.live="acquisitionFeesTreatment">
                                        @foreach(\App\Models\Property::acquisitionFeesTreatmentLabels() as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <span class="rp-hint">Votre comptable les a peut-être passés en charges l'année de l'achat : dans ce cas, choisissez « passés en charges ».</span>
                                @isset($this->stepErrors['acquisitionFeesTreatment'])
                                    <span class="rp-error">{{ $this->stepErrors['acquisitionFeesTreatment'] }}</span>
                                @endisset
                            </div>
                            @if($this->acquisitionFeesTreatment === \App\Models\Property::ACQUISITION_FEES_AMORTIZED)
                                <div class="rp-field">
                                    <label for="rp-fees-duration">Durée d'amortissement des frais</label>
                                    <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['acquisitionFeesDuration'])])>
                                        <input id="rp-fees-duration" type="number" wire:model="acquisitionFeesDuration" min="1" max="50">
                                        <i>ans</i>
                                    </div>
                                    @isset($this->stepErrors['acquisitionFeesDuration'])
                                        <span class="rp-error">{{ $this->stepErrors['acquisitionFeesDuration'] }}</span>
                                    @endisset
                                </div>
                            @endif
                            <div class="rp-field">
                                <label>Base amortissable calculée</label>
                                <div class="rp-input rp-readonly">
                                    <span>{{ $this->formatEuros($this->depreciableBaseCents()) }}</span>
                                </div>
                                <span class="rp-hint">
                                    (prix d'acquisition − {{ rtrim(rtrim(number_format((float) $this->landPercentage, 2, ',', ''), '0'), ',') }} % de terrain). C'est elle que vous allez ventiler.
                                </span>
                            </div>
                        </div>

                        <div class="rp-actions">
                            <button type="button" class="rp-btn rp-btn-ghost" wire:click="previousStep">Retour</button>
                            <button type="button" class="rp-btn rp-btn-primary" wire:click="nextStep">Continuer</button>
                        </div>
                    @endif

                    {{-- ==================== Étape 3 — méthode, puis la grille ==================== --}}
                    @if($this->step === 3)
                        @if($this->method === null)
                            <div class="rp-field">
                                <label>Comment voulez-vous reprendre votre plan d'amortissement ?</label>
                                <div class="rp-choices">
                                    <button type="button" class="rp-choice" wire:click="chooseMethod('{{ \App\Filament\Pages\RepriseDossier::METHOD_COPY }}')">
                                        <b>Recopier les lignes de ma liasse</b>
                                        <span>Vous saisissez la base et la durée de chaque composant, lues sur votre tableau <span class="rp-cerfa">2033-C</span>.</span>
                                        <span class="rp-choice-meta">≈ 10 minutes, liasse sous les yeux</span>
                                        <ul class="rp-pros">
                                            <li class="pro">Vos chiffres restent <strong>exactement ceux de votre comptable</strong> : le contrôle de l'étape 5 tombe juste.</li>
                                            <li class="pro">Rien ne sort de chez vous, aucun service extérieur.</li>
                                            <li class="pro">Vous gardez la main sur chaque durée, y compris les composants inhabituels.</li>
                                            <li class="con">Une dizaine de champs à saisir, votre liasse ouverte à côté.</li>
                                            <li class="con">Une faute de frappe ne se voit qu'au contrôle de l'étape 5.</li>
                                        </ul>
                                    </button>
                                    <button type="button" class="rp-choice" wire:click="chooseMethod('{{ \App\Filament\Pages\RepriseDossier::METHOD_SPREAD }}')">
                                        <b>Répartir automatiquement ma base</b>
                                        <span>Nous appliquons la ventilation habituelle (50 % gros œuvre, 10 % toiture…) sur votre base amortissable.</span>
                                        <span class="rp-choice-meta">Un clic, rien à saisir</span>
                                        <ul class="rp-pros">
                                            <li class="pro">Aucun chiffre à recopier : la ventilation et les durées sont proposées.</li>
                                            <li class="pro">Durées conformes aux usages admis en location meublée.</li>
                                            <li class="pro">Le bon choix si vous sortez du micro-BIC : il n'y a rien à reprendre.</li>
                                            <li class="con"><strong>Ne reproduit pas le plan d'amortissement de votre comptable</strong> : dotations et cumul seront différents des siens.</li>
                                            <li class="con">Si vous avez déjà amorti au réel, l'étape 5 signalera l'écart — et il faudra revenir ici.</li>
                                        </ul>
                                    </button>
                                    @if($this->aiCardVisible())
                                        @php $aiBlocked = $this->liasseUnavailableMessage(); @endphp
                                        <button type="button" class="rp-choice @if($aiBlocked) rp-choice-disabled @endif"
                                                @if(! $aiBlocked) wire:click="chooseMethod('{{ \App\Filament\Pages\RepriseDossier::METHOD_AI }}')" @endif>
                                            <b>Faire lire ma liasse par l'IA</b>
                                            <span>Vous déposez votre liasse, les lignes du <span class="rp-cerfa">2033-C</span> et vos reports sont lus, puis <strong>vous relisez tout</strong> avant qu'une seule valeur soit recopiée.</span>
                                            <span class="rp-choice-meta">≈ 2 minutes, votre liasse en PDF ou en photos</span>
                                            @if($aiBlocked)
                                                <span class="rp-error">{{ $aiBlocked }}</span>
                                            @else
                                                <ul class="rp-pros">
                                                    <li class="pro">Le tableau 2033-C et les cases de report sont recopiés pour vous, avec un <strong>indice de confiance par ligne</strong>.</li>
                                                    <li class="pro">L'étape 4 est pré-remplie avec les cases lues — une case vide sur la liasse reste vide ici.</li>
                                                    <li class="con">Votre liasse est envoyée au service d'IA configuré — rien ne part avant votre accord explicite.</li>
                                                    <li class="con">L'IA peut se tromper : chaque ligne doit être relue, et c'est votre relecture qui fait foi.</li>
                                                </ul>
                                            @endif
                                        </button>
                                    @endif
                                </div>
                                @isset($this->stepErrors['method'])
                                    <span class="rp-error">{{ $this->stepErrors['method'] }}</span>
                                @endisset
                            </div>

                            <div class="rp-note rp-note-warn">
                                <b>Si vous avez un plan d'amortissement, recopiez-le.</b>
                                La ventilation automatique donnera un plan d'amortissement valable, mais différent de celui de votre
                                comptable : votre cumul d'amortissements ne tombera pas sur le sien, et l'étape 5 vous
                                le signalera.
                            </div>

                            <div class="rp-actions">
                                <button type="button" class="rp-btn rp-btn-ghost" wire:click="previousStep">Retour</button>
                                <button type="button" class="rp-btn rp-btn-primary" wire:click="nextStep">Continuer</button>
                            </div>
                        @elseif($this->method === \App\Filament\Pages\RepriseDossier::METHOD_AI && ! $this->liasseApplied)
                            <div class="rp-inline-actions">
                                <button type="button" class="rp-btn rp-btn-ghost" wire:click="$set('method', null)">
                                    Changer de méthode
                                </button>
                                <span class="rp-pill rp-pill-custom" style="align-self:center">Lecture IA de la liasse</span>
                            </div>

                            @isset($this->stepErrors['method'])
                                <div class="rp-note rp-note-warn">{{ $this->stepErrors['method'] }}</div>
                            @endisset

                            @if($this->liasseAnalysis === null)
                                {{-- Consentement AVANT le dépôt : deux blocs, et rien qui décrive
                                     à l'indicatif ce que fait un tiers (CDC lot 6). --}}
                                <div class="rp-note rp-note-info">
                                    <b>Ce que nous maîtrisons, et que le code vérifie :</b>
                                    <ul class="rp-pros" style="margin:8px 0 0">
                                        <li class="pro">Ce qui part : votre liasse et le nom du bien. Jamais votre identité, votre e-mail ni vos autres documents.</li>
                                        <li class="pro">Une consigne de non-conservation est transmise au service — c'est une préférence que nous envoyons, pas un engagement que nous pourrions tenir à sa place.</li>
                                        <li class="pro">Le journal technique ne garde que des compteurs (durée, coût) — aucun montant lu, aucun contenu.</li>
                                        <li class="pro">Rien n'est recopié dans votre dossier sans votre relecture, ligne à ligne.</li>
                                    </ul>
                                </div>
                                <div class="rp-note rp-note-warn">
                                    <b>Vous pouvez aussi vous en passer entièrement :</b>
                                    recopier les lignes à la main (« Changer de méthode »), ou pointer votre instance vers un
                                    modèle installé chez vous (<span class="rp-cerfa">AI_BASE_URL</span>) — rien ne quitte alors votre réseau.
                                </div>

                                <div class="rp-field">
                                    <label class="rp-ai-consent">
                                        <input type="checkbox" wire:model.live="liasseConsent">
                                        <span>J'accepte que cette liasse soit transmise au service d'intelligence artificielle configuré, le temps de sa lecture.</span>
                                    </label>
                                </div>

                                <div class="rp-field">
                                    <label for="rp-liasse-file">Votre liasse (PDF, ou photos JPEG/PNG/WebP — 10 Mo max.)</label>
                                    {{-- Le nom du fichier est posé par Alpine au `change`, et repris du serveur au
                                         rendu : après un aller-retour Livewire, l'état Alpine repart de zéro alors
                                         que le fichier, lui, est toujours téléversé. --}}
                                    <div class="rp-drop"
                                         x-data="{ over: false, fileName: @js($this->liasseFileName()) }"
                                         :class="over && 'rp-drop-over'"
                                         @dragenter.prevent="over = true"
                                         @dragover.prevent="over = true"
                                         @dragleave.prevent="over = false"
                                         @drop="over = false">
                                        <input id="rp-liasse-file" type="file" class="rp-drop-input"
                                               wire:model="liasseFile"
                                               accept="application/pdf,image/jpeg,image/png,image/webp"
                                               aria-describedby="rp-liasse-file-help"
                                               @change="fileName = $event.target.files?.[0]?.name ?? ''">
                                        <span class="rp-drop-icon" aria-hidden="true">
                                            <svg width="38" height="38" viewBox="0 0 24 24" fill="none"
                                                 stroke="currentColor" stroke-width="1.6"
                                                 stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M12 16V4" /><path d="m7 9 5-5 5 5" />
                                                <path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3" />
                                            </svg>
                                        </span>
                                        <span class="rp-drop-title">Glissez votre liasse ici, ou cliquez pour la choisir</span>
                                        <span class="rp-drop-sub" id="rp-liasse-file-help">PDF, ou photos JPEG, PNG ou WebP — 10 Mo maximum</span>
                                        <span class="rp-drop-cta" aria-hidden="true">Parcourir mes fichiers</span>
                                        <span class="rp-drop-file" x-show="fileName" x-cloak>
                                            <span aria-hidden="true">&#x1F4CE;</span><span x-text="fileName"></span>
                                        </span>
                                    </div>
                                    @error('liasseFile')<span class="rp-error">{{ $message }}</span>@enderror
                                </div>

                                {{-- L'échec compte autant que le succès : le motif du refus était rendu
                                     par un `<span>` de 11,5 px sous le champ, invisible dès qu'on avait
                                     l'œil sur le bouton. Il devient un encart, avec la sortie de secours. --}}
                                @if($this->liasseError)
                                    <div class="rp-note rp-note-bad" role="alert">
                                        <b>La lecture n'a pas eu lieu.</b>
                                        {{ $this->liasseError }}
                                        Vous pouvez réessayer, ou passer par « Changer de méthode » pour
                                        recopier les lignes de votre liasse à la main.
                                    </div>
                                @endif

                                <div class="rp-actions">
                                    <button type="button" class="rp-btn rp-btn-primary"
                                            wire:click="analyzeLiasse" wire:loading.attr="disabled"
                                            x-on:click="$dispatch('liasse-wait-open')"
                                            @if(! $this->liasseConsent) disabled @endif>
                                        <span wire:loading.remove wire:target="analyzeLiasse">Lire ma liasse</span>
                                        <span wire:loading wire:target="analyzeLiasse">Lecture en cours…</span>
                                    </button>
                                </div>

                                {{--
                                    Attente de la lecture — MODALE CENTRÉE.

                                    ⚠️ AUCUNE étape d'avancement, et c'est une décision, pas un oubli.
                                    Ce bloc affichait « Document transmis au modèle », « Tableaux 2033-A
                                    à 2033-D repérés », « Lignes extraites » : trois lignes qui
                                    s'allumaient sur un `setInterval` de 4 secondes, sans aucun rapport
                                    avec le serveur (la quatrième n'avait même pas de liaison et ne
                                    s'allumait jamais). Affirmer cela au centre de l'écran, en grand,
                                    mentirait plus fort que la même liste en petit dans le flux.

                                    Un avancement RÉEL demanderait de diffuser l'état depuis le serveur
                                    (`$this->stream()` de Livewire 4) pendant que l'appel au modèle
                                    tourne — donc un traitement hors requête. Or l'instance est en
                                    `QUEUE_CONNECTION=sync`, sans worker : hors périmètre ici, mais la
                                    piste est notée pour ne pas se perdre.

                                    Ce qui reste : une attente franche, une durée indicative, et des
                                    astuces qui apprennent réellement quelque chose (`liasseWaitTips()`).
                                --}}
                                <div wire:loading.flex wire:target="analyzeLiasse"
                                     class="rp-modal-backdrop"
                                     role="dialog" aria-modal="true" aria-labelledby="rp-wait-title"
                                     x-data="{
                                        hidden: false, elapsed: 0, tip: 0, timer: null, rota: null,
                                        tips: @js($this->liasseWaitTips()),
                                        start() {
                                            this.hidden = false; this.elapsed = 0; this.tip = 0;
                                            clearInterval(this.timer); clearInterval(this.rota);
                                            this.timer = setInterval(() => { if (++this.elapsed > 600) clearInterval(this.timer); }, 1000);
                                            this.rota = setInterval(() => this.tip = (this.tip + 1) % this.tips.length, 6000);
                                        },
                                     }"
                                     :class="hidden && 'rp-modal-gone'"
                                     x-on:liasse-wait-open.window="start()"
                                     x-on:keydown.escape.window="hidden = true">
                                    <div class="rp-modal">
                                        <span class="rp-modal-spin" aria-hidden="true"></span>
                                        <h3 id="rp-wait-title">Lecture de votre liasse en cours…</h3>
                                        <p aria-live="polite">
                                            Comptez 10 à 30 secondes. Le document part en une seule fois et
                                            l'application attend la réponse : elle ne sait pas où en est le
                                            modèle, et ne vous montrera donc pas d'étapes qu'elle ne connaît pas.
                                        </p>
                                        <div class="rp-modal-tip">
                                            <b>Pendant ce temps</b>
                                            <span x-text="tips[tip]">{{ $this->liasseWaitTips()[0] }}</span>
                                        </div>
                                        <div class="rp-modal-late" x-show="elapsed >= 60" x-cloak>
                                            C'est plus long que d'habitude. Si la lecture échoue, cette
                                            fenêtre se ferme et l'écran vous dit pourquoi : vous pourrez
                                            réessayer, ou recopier les lignes à la main.
                                        </div>
                                        <div class="rp-modal-foot">
                                            <button type="button" class="rp-btn rp-btn-ghost" x-on:click="hidden = true">
                                                Masquer cette fenêtre
                                            </button>
                                        </div>
                                        <p class="rp-modal-note">
                                            La masquer n'annule pas la lecture : le résultat, ou son échec,
                                            s'affichera sur cet écran.
                                        </p>
                                    </div>
                                </div>
                            @else
                                @php
                                    $liasseResult = $this->liasseResult();
                                    $coherent = $this->liasseCoherent();
                                @endphp
                                <div class="rp-note {{ $coherent === false ? 'rp-note-warn' : 'rp-note-info' }}">
                                    @if($coherent === true)
                                        <b>Le tableau a été lu en entier :</b> la somme des dotations lues retombe sur la ligne 572 de votre liasse.
                                    @elseif($coherent === false)
                                        <b>Relisez ligne à ligne :</b> la somme des dotations lues ne retombe pas sur la ligne 572.
                                    @else
                                        <b>Relisez chaque ligne :</b> la ligne 572 n'a pas pu être lue, le contrôle de complétude n'a pas de référence.
                                    @endif
                                    Rien n'est encore écrit : c'est votre relecture qui décide.
                                </div>

                                @foreach($liasseResult->warnings as $warning)
                                    <div class="rp-note rp-note-warn">{{ $warning }}</div>
                                @endforeach

                                <div class="rp-ai-review">
                                    <div class="rp-ai-read">
                                        <h4>Lignes du 2033-C</h4>
                                        <table class="rp-ai-table">
                                            <thead><tr><th>Ligne</th><th>Base</th><th>Durée</th><th>Dotation</th><th>Cumul</th><th></th></tr></thead>
                                            <tbody>
                                            @forelse($liasseResult->rows as $row)
                                                @php
                                                    $bucket = ($row['confidence'] ?? 0) >= 0.85 ? 'high' : (($row['confidence'] ?? 0) >= 0.6 ? 'medium' : 'low');
                                                    $dejaPortee = ($row['cerfa_category'] ?? null) === \App\Models\PropertyComponent::CERFA_CATEGORY_INTANGIBLE;
                                                @endphp
                                                <tr @class(['rp-ai-doubt' => $bucket === 'low', 'rp-ai-carried' => $dejaPortee])
                                                    @if($dejaPortee) title="Déjà portée par l'application : elle amortit vos frais d'acquisition depuis l'étape 2, cette ligne ne sera pas recopiée." aria-label="Ligne déjà portée par l'application" @endif>
                                                    <td>@if($dejaPortee)<span class="rp-ai-mark" aria-hidden="true">&#x21A9;</span>@endif{{ $row['label'] }}</td>
                                                    <td>{{ isset($row['base']) ? $this->formatEuros($row['base']) : '—' }}</td>
                                                    <td>{{ ($row['duration_years'] ?? null) ? $row['duration_years'] . ' ans' : '—' }}</td>
                                                    <td>{{ isset($row['annual']) ? $this->formatEuros($row['annual']) : '—' }}</td>
                                                    <td>{{ isset($row['cumul']) ? $this->formatEuros($row['cumul']) : '—' }}</td>
                                                    <td><span class="rp-ai-badge rp-ai-badge-{{ $bucket }}">{{ $bucket === 'high' ? 'Lu' : ($bucket === 'medium' ? 'À vérifier' : 'Douteux') }}</span></td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="6">Aucune ligne lue.</td></tr>
                                            @endforelse
                                            </tbody>
                                        </table>

                                        <p class="rp-ai-legend">
                                            <span class="rp-ai-mark" aria-hidden="true">&#x21A9;</span>
                                            Les lignes ainsi marquées sont <strong>déjà portées par l'application</strong> :
                                            elles ne seront pas recopiées.
                                        </p>

                                    </div>
                                    <div class="rp-ai-read">
                                        <h4>Cases de report</h4>
                                        <table class="rp-ai-table">
                                            <tbody>
                                            @php $v = $liasseResult->values; @endphp
                                            <tr><td>Exercice de la liasse</td><td>{{ $v['fiscal_year'] ?? '—' }}</td></tr>
                                            <tr><td>Amortissements différés <span class="rp-cerfa">870</span></td><td>{{ isset($v['deferred_depreciation']) ? $this->formatEuros($v['deferred_depreciation']) : '— (rien à reprendre)' }}</td></tr>
                                            <tr><td>Cumul d'amortissements <span class="rp-cerfa">030</span></td><td>{{ isset($v['accumulated_depreciation']) ? $this->formatEuros($v['accumulated_depreciation']) : '—' }}</td></tr>
                                            <tr><td>Immobilisations corporelles brutes <span class="rp-cerfa">028</span></td><td>{{ isset($v['gross_assets']) ? $this->formatEuros($v['gross_assets']) : '—' }}</td></tr>
                                            <tr><td>Incorporelles <span class="rp-cerfa">014</span></td><td>{{ isset($v['intangible_assets']) ? $this->formatEuros($v['intangible_assets']) : '—' }}</td></tr>
                                            <tr><td>Total des immobilisations brutes <span class="rp-cerfa">044</span></td><td>{{ isset($v['total_gross_assets']) ? $this->formatEuros($v['total_gross_assets']) : '—' }}</td></tr>
                                            <tr><td>Déficits reportables <span class="rp-cerfa">980-984</span></td>
                                                <td>
                                                    @forelse(($v['deficits'] ?? []) as $deficit)
                                                        {{ $deficit['origin_year'] }} : {{ $this->formatEuros($deficit['amount']) }}@if(! $loop->last) · @endif
                                                    @empty
                                                        — (aucun suivi)
                                                    @endforelse
                                                </td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                {{--
                                    Aperçu de la liasse — TOUTE LA LARGEUR, sous les tableaux lus.

                                    ⚠️ La comparaison se fait désormais par le défilement, pas à l'œil :
                                    il faut donc que cet aperçu soit exploitable. D'où la pagination et
                                    le zoom, et une hauteur qui montre une page entière.

                                    La navigation passe par les `open parameters` du PDF (`#page=`,
                                    `#zoom=`), honorés par la visionneuse de Chrome comme par celle de
                                    Firefox. C'est ce qui évite d'embarquer pdf.js pour tourner sept
                                    pages. Conséquence à connaître : changer de page RECHARGE le cadre.
                                    Le `src` est donc posé impérativement au clic (`$refs`), jamais par
                                    un `x-bind` — une liaison le réécrirait dès l'amorçage d'Alpine,
                                    soit un rechargement pour rien à chaque affichage.

                                    Le nombre de pages n'est pas affiché : personne ici ne le connaît
                                    (rien ne parse le PDF côté serveur). La visionneuse du navigateur
                                    l'affiche dans sa propre barre, et « Ouvrir dans un onglet » donne
                                    le document en pleine page.
                                --}}
                                @php
                                    $previewUrl = $this->liassePreviewUrl();
                                    $previewIsPdf = str_ends_with(
                                        mb_strtolower((string) ($this->liasseAnalysis['file_name'] ?? '')),
                                        '.pdf',
                                    );
                                @endphp
                                <div class="rp-ai-doc"
                                     x-data="{
                                        page: 1, zoom: 100, base: @js($previewUrl ?? ''),
                                        go() {
                                            if (this.page < 1 || ! Number.isFinite(this.page)) this.page = 1;
                                            if (this.$refs.doc) this.$refs.doc.src = this.base + '#page=' + this.page + '&zoom=' + this.zoom;
                                        },
                                        setZoom(next) { this.zoom = Math.min(400, Math.max(50, next)); this.go(); },
                                     }">
                                    @if($previewUrl)
                                        <div class="rp-ai-doc-bar">
                                            <span class="rp-ai-doc-label">Votre liasse</span>
                                            @if($previewIsPdf)
                                                <button type="button" class="rp-btn rp-btn-ghost rp-btn-tiny"
                                                        x-on:click="page = Math.max(1, page - 1); go()"
                                                        :disabled="page <= 1">Page précédente</button>
                                                <label class="rp-ai-doc-page">
                                                    Page
                                                    <input type="number" min="1" step="1" x-model.number="page" x-on:change="go()">
                                                </label>
                                                <button type="button" class="rp-btn rp-btn-ghost rp-btn-tiny"
                                                        x-on:click="page = page + 1; go()">Page suivante</button>
                                            @endif
                                            <button type="button" class="rp-btn rp-btn-ghost rp-btn-tiny"
                                                    x-on:click="setZoom(zoom - 25)" aria-label="Réduire l'aperçu">&minus;</button>
                                            <span class="rp-ai-doc-zoom" x-text="zoom + ' %'">100 %</span>
                                            <button type="button" class="rp-btn rp-btn-ghost rp-btn-tiny"
                                                    x-on:click="setZoom(zoom + 25)" aria-label="Agrandir l'aperçu">+</button>
                                            <a class="rp-btn rp-btn-ghost rp-btn-tiny" href="{{ $previewUrl }}"
                                               target="_blank" rel="noopener">Ouvrir dans un onglet</a>
                                        </div>

                                        @if($previewIsPdf)
                                            <iframe x-ref="doc" src="{{ $previewUrl }}#page=1&amp;zoom=100" title="Votre liasse"></iframe>
                                        @else
                                            {{-- Une photo n'a pas de pages : seul le zoom a un sens, et il
                                                 s'applique à la largeur de l'image dans son cadre défilant. --}}
                                            <div class="rp-ai-doc-scroll">
                                                <img src="{{ $previewUrl }}" alt="Votre liasse" :style="'width:' + zoom + '%'">
                                            </div>
                                        @endif
                                    @else
                                        <p class="rp-ai-empty">Aperçu indisponible.</p>
                                    @endif
                                </div>

                                @if($this->liasseError)
                                    <div class="rp-note rp-note-warn">{{ $this->liasseError }}</div>
                                @endif

                                <div class="rp-actions">
                                    <button type="button" class="rp-btn rp-btn-ghost" wire:click="discardLiasse">Ignorer cette lecture</button>
                                    <button type="button" class="rp-btn rp-btn-primary" wire:click="applyLiasse">Utiliser ces lignes</button>
                                </div>

                                {{--
                                    Refus de recopie : la ventilation dépasse la base amortissable.

                                    ⚠️ Le refus est JUSTE — rien n'est écrit, et ce n'est pas un faux
                                    positif. Ce qui manquait, c'est de pouvoir agir : le message nommait
                                    trois remèdes (la valeur retenue, la part du terrain, écarter les
                                    lignes hors bâti) et n'offrait aucun bouton pour les appliquer. Les
                                    trois sont ici, plus la sortie « revenir à l'étape 2 » pour reprendre
                                    la fiche du bien en entier.
                                --}}
                                @if($this->liasseOverflow)
                                    @php
                                        $overflow = $this->liasseOverflow;
                                        $candidates = $this->liasseCandidateRows();
                                        $selected = $this->liasseSelectedTotal();
                                        $excess = $this->liasseExcessCents();
                                        $available = $selected - $excess;
                                        // Moins d'un euro par ligne : l'arrondi de la liasse, absorbé à la recopie.
                                        $absorbable = $this->liasseExcessAbsorbable();
                                    @endphp
                                    <div class="rp-modal-backdrop rp-modal-open" role="dialog" aria-modal="true"
                                         aria-labelledby="rp-overflow-title">
                                        <div class="rp-modal rp-modal-wide">
                                            <h3 id="rp-overflow-title">La ventilation dépasse la base amortissable</h3>
                                            <p>
                                                {{ $overflow['message'] }}
                                                Rien n'a été écrit dans votre dossier : corrigez ici, puis réessayez.
                                            </p>

                                            <div class="rp-modal-figures">
                                                <div class="rp-modal-figure">
                                                    <b>{{ $this->formatCents($selected) }}</b>
                                                    <span>Lignes retenues</span>
                                                </div>
                                                <div class="rp-modal-figure">
                                                    <b>{{ $this->formatCents($available) }}</b>
                                                    <span>Base amortissable</span>
                                                </div>
                                                <div @class(['rp-modal-figure', 'rp-modal-figure-bad' => ! $absorbable, 'rp-modal-figure-ok' => $absorbable])>
                                                    <b>{{ $this->formatCents(max(0, $excess)) }}</b>
                                                    <span>{{ $absorbable ? 'Ça tient désormais' : 'Excédent à résorber' }}</span>
                                                </div>
                                            </div>

                                            <div class="rp-modal-action">
                                                <h4>Corriger la valeur retenue ou la part du terrain</h4>
                                                <p>
                                                    La base amortissable vaut le prix d'acquisition moins la part du
                                                    terrain. Si votre comptable a retenu une autre répartition, c'est ici.
                                                </p>
                                                <div class="rp-grid">
                                                    <div class="rp-field">
                                                        <label for="rp-overflow-price">Prix d'acquisition</label>
                                                        <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['acquisitionPrice'])])>
                                                            <input id="rp-overflow-price" type="text" inputmode="decimal"
                                                                   wire:model.live.debounce.600ms="acquisitionPrice">
                                                            <i>€</i>
                                                        </div>
                                                        @isset($this->stepErrors['acquisitionPrice'])
                                                            <span class="rp-error">{{ $this->stepErrors['acquisitionPrice'] }}</span>
                                                        @endisset
                                                    </div>
                                                    <div class="rp-field">
                                                        <label for="rp-overflow-land">Part du terrain</label>
                                                        <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['landPercentage'])])>
                                                            <input id="rp-overflow-land" type="number" min="0" max="99" step="0.01"
                                                                   wire:model.live="landPercentage">
                                                            <i>%</i>
                                                        </div>
                                                        @isset($this->stepErrors['landPercentage'])
                                                            <span class="rp-error">{{ $this->stepErrors['landPercentage'] }}</span>
                                                        @endisset
                                                    </div>
                                                </div>
                                                <div class="rp-inline-actions" style="margin:12px 0 0">
                                                    <button type="button" class="rp-btn rp-btn-primary" wire:click="fixPropertyFromOverflow">
                                                        Enregistrer et réessayer
                                                    </button>
                                                </div>
                                            </div>

                                            <div class="rp-modal-action">
                                                <h4>Écarter les lignes qui ne portent pas sur le bâti</h4>
                                                <p>
                                                    Décochez une ligne pour la laisser de côté : elle ne deviendra pas un
                                                    composant. Le total se met à jour à chaque décochage.
                                                </p>
                                                <ul class="rp-modal-rows">
                                                    @foreach($candidates as $candidate)
                                                        <li class="rp-modal-row">
                                                            <input type="checkbox" id="rp-overflow-row-{{ $candidate['index'] }}"
                                                                   @checked(! $candidate['excluded'])
                                                                   wire:click="toggleLiasseRow({{ $candidate['index'] }})">
                                                            <label for="rp-overflow-row-{{ $candidate['index'] }}"
                                                                   @class(['rp-modal-row-off' => $candidate['excluded']])>{{ $candidate['label'] }}</label>
                                                            <span @class(['rp-modal-row-amount', 'rp-modal-row-off' => $candidate['excluded']])>{{ $this->formatEuros($candidate['base']) }}</span>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                                <div class="rp-inline-actions" style="margin:0">
                                                    <button type="button" class="rp-btn rp-btn-primary" wire:click="applyLiasse">
                                                        Réessayer avec ces lignes
                                                    </button>
                                                </div>
                                            </div>

                                            <div class="rp-actions">
                                                <div class="rp-actions-left">
                                                    <button type="button" class="rp-btn rp-btn-ghost" wire:click="dismissLiasseOverflow">
                                                        Fermer
                                                    </button>
                                                </div>
                                                <button type="button" class="rp-btn rp-btn-ghost" wire:click="backToPropertyStep">
                                                    Revenir à l'étape 2
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endif
                        @else
                            <div class="rp-inline-actions">
                                <button type="button" class="rp-btn rp-btn-ghost" wire:click="$set('method', null)">
                                    Changer de méthode
                                </button>
                                <span class="rp-pill rp-pill-custom" style="align-self:center">
                                    @if($this->method === \App\Filament\Pages\RepriseDossier::METHOD_COPY)
                                        Recopie de la liasse (tableau 2033-C)
                                    @elseif($this->method === \App\Filament\Pages\RepriseDossier::METHOD_AI)
                                        Lecture IA — lignes recopiées après relecture
                                    @else
                                        Ventilation automatique
                                    @endif
                                </span>
                            </div>

                            {{-- ⚠️ `wire:key` OBLIGATOIRE. L'éditeur porte un `wire:ignore` : Livewire
                                 ne touche pas à son contenu, et lors d'un morphing il CONSERVE le
                                 nœud au lieu de le retirer. Sans clé variable, cliquer sur
                                 « Changer de méthode » affichait les deux cartes de choix ET
                                 l'ancien éditeur, l'un sous l'autre. --}}
                            <div wire:key="reprise-editor-{{ $this->method }}-{{ $this->propertyId }}">
                                @include('filament.partials.depreciation-editor-core', [
                                    'data' => array_merge($this->editorData, ['initialMode' => $this->editorInitialMode()]),
                                    'properties' => [],
                                    'propertyId' => $this->propertyId,
                                    'showReset' => false,
                                    'saveLabel' => 'Enregistrer mon plan',
                                ])
                            </div>

                            <div class="rp-note rp-note-info">
                                <b>Enregistrez votre plan d'amortissement avant de continuer.</b>
                                Les colonnes « Départ » et « Cumul au 31/12/{{ $this->referenceYear() }} » sont celles
                                qui permettront au contrôle de l'étape 5 de retomber sur les chiffres de votre comptable.
                            </div>

                            {{--
                                Travaux et mobilier ne passent PAS par cet éditeur : il ne porte que les
                                composants du bien (gros œuvre, toiture…). Sans ce renvoi, un cabinet qui a déjà
                                amorti des travaux ou du mobilier laissait ces lignes hors de la reprise — et
                                l'étape 5 affichait un écart sur « Total des immobilisations brutes » que rien
                                n'expliquait (constaté en rejouant un dossier de test le 2026-10-15).
                                Les deux écrans visés ont déjà leur propre section « Reprise d'une comptabilité
                                existante » (base, dotation recopiée, cumul déjà pratiqué) : pas de troisième
                                éditeur à maintenir, un simple renvoi.
                            --}}
                            @if($this->propertyId !== null)
                                <div class="rp-note rp-note-warn">
                                    <b>Des travaux ou du mobilier déjà amortis par votre cabinet ?</b>
                                    Cet éditeur ne porte que les composants du bien : les travaux et le mobilier
                                    déjà immobilisés se saisissent sur leurs propres écrans, qui ont chacun leur
                                    section « Reprise d'une comptabilité existante ». Ouvrez-les dans un nouvel
                                    onglet, puis revenez ici continuer — rien n'est perdu.
                                    <div class="rp-inline-actions" style="margin-top:10px">
                                        <a class="rp-btn rp-btn-ghost rp-btn-tiny" target="_blank" rel="noopener"
                                           href="{{ \App\Filament\Resources\PropertyWorks\PropertyWorkResource::getUrl('property', ['propertyId' => $this->propertyId]) }}">
                                            Saisir mes travaux
                                        </a>
                                        <a class="rp-btn rp-btn-ghost rp-btn-tiny" target="_blank" rel="noopener"
                                           href="{{ \App\Filament\Resources\Furniture\FurnitureResource::getUrl('property', ['propertyId' => $this->propertyId]) }}">
                                            Saisir mon mobilier
                                        </a>
                                    </div>
                                </div>
                            @endif

                            <div class="rp-actions">
                                <button type="button" class="rp-btn rp-btn-ghost" wire:click="previousStep">Retour</button>
                                <button type="button" class="rp-btn rp-btn-primary" wire:click="nextStep">Continuer</button>
                            </div>
                        @endif
                    @endif

                    {{-- ==================== Étape 4 — vos reports ==================== --}}
                    @if($this->step === 4)
                        <div class="rp-note rp-note-info" style="margin-top:0">
                            <b>Différé n'est pas déficit.</b>
                            L'<strong>amortissement différé</strong> est la part d'amortissement que vous n'avez pas pu
                            déduire parce qu'elle aurait créé un déficit : elle se reporte sans limite de durée. Le
                            <strong>déficit</strong>, lui, vient de vos charges, et ne s'impute que sur vos bénéfices de
                            location meublée des dix années suivantes.
                        </div>

                        <div class="rp-grid" style="margin-top:16px">
                            <div class="rp-field">
                                <label for="rp-deferred">Amortissements différés au 31/12/{{ $this->referenceYear() }}</label>
                                <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['openingDeferred'])])>
                                    <input id="rp-deferred" type="text" inputmode="decimal" wire:model="openingDeferred">
                                    <i>€</i>
                                </div>
                                <span class="rp-hint">
                                    Liasse <span class="rp-cerfa">2033-D, case 870</span>. Si votre comptable ne remplit
                                    pas le 2033-D : <span class="rp-cerfa">2033-B, case 318</span> cumulée.
                                </span>
                                @isset($this->stepErrors['openingDeferred'])
                                    <span class="rp-error">{{ $this->stepErrors['openingDeferred'] }}</span>
                                @endisset
                            </div>
                            <div class="rp-field">
                                <label for="rp-accumulated">Cumul d'amortissements pratiqués</label>
                                <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['openingAccumulated'])])>
                                    <input id="rp-accumulated" type="text" inputmode="decimal" wire:model="openingAccumulated">
                                    <i>€</i>
                                </div>
                                <span class="rp-hint">
                                    Liasse <span class="rp-cerfa">2033-A, case 030</span>. Sert uniquement au contrôle
                                    de l'étape suivante.
                                </span>
                                @isset($this->stepErrors['openingAccumulated'])
                                    <span class="rp-error">{{ $this->stepErrors['openingAccumulated'] }}</span>
                                @endisset
                            </div>
                            <div class="rp-field">
                                <label for="rp-gross">Total des immobilisations brutes</label>
                                <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['declaredGrossAssets'])])>
                                    <input id="rp-gross" type="text" inputmode="decimal" wire:model="declaredGrossAssets">
                                    <i>€</i>
                                </div>
                                <span class="rp-hint">
                                    Liasse <span class="rp-cerfa">2033-A, case 044</span> : le <strong>total</strong>
                                    des immobilisations brutes, incorporelles comprises. Le total plutôt que la seule
                                    case 028, parce que les frais de notaire se présentent d'un cabinet à l'autre sur
                                    l'une ou l'autre ligne, sans que le total change. Facultatif : laissé vide, cette
                                    ligne n'est pas comparée à l'étape 5.
                                </span>
                                @isset($this->stepErrors['declaredGrossAssets'])
                                    <span class="rp-error">{{ $this->stepErrors['declaredGrossAssets'] }}</span>
                                @endisset
                            </div>
                        </div>

                        <div class="rp-field" style="margin-top:16px">
                            <label>Déficits reportables, par année d'origine</label>
                            @if($this->deficits !== [])
                                <table class="rp-table">
                                    <thead>
                                        <tr>
                                            <th>Année d'origine</th>
                                            <th class="rp-num-cell">Montant restant</th>
                                            <th class="rp-num-cell">Imputable jusqu'à</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($this->deficits as $index => $deficit)
                                            <tr>
                                                <td>
                                                    <div class="rp-input" style="max-width:130px">
                                                        <input type="number" wire:model.live="deficits.{{ $index }}.origin_year" min="2000" max="{{ date('Y') }}">
                                                    </div>
                                                </td>
                                                <td class="rp-num-cell">
                                                    <div class="rp-input" style="max-width:150px;margin-left:auto">
                                                        <input type="text" inputmode="decimal" wire:model="deficits.{{ $index }}.amount">
                                                        <i>€</i>
                                                    </div>
                                                </td>
                                                <td class="rp-num-cell">{{ $this->deficitExpiryYear((int) ($deficit['origin_year'] ?? 0)) }}</td>
                                                <td class="rp-num-cell">
                                                    <button type="button" class="rp-btn rp-btn-ghost rp-btn-tiny" wire:click="removeDeficit({{ $index }})">retirer</button>
                                                </td>
                                            </tr>
                                            @isset($this->stepErrors['deficits.' . $index])
                                                <tr>
                                                    <td colspan="4"><span class="rp-error">{{ $this->stepErrors['deficits.' . $index] }}</span></td>
                                                </tr>
                                            @endisset
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                            <span class="rp-hint">
                                Liasse <span class="rp-cerfa">2033-D, cases 980 à 984</span>. Laissez vide si vous n'en
                                avez pas — c'est le cas le plus fréquent.
                            </span>
                        </div>

                        <div class="rp-inline-actions" style="margin-top:12px">
                            <button type="button" class="rp-btn rp-btn-ghost" wire:click="addDeficit">+ Ajouter une année</button>
                        </div>

                        <div class="rp-actions">
                            <button type="button" class="rp-btn rp-btn-ghost" wire:click="previousStep">Retour</button>
                            <button type="button" class="rp-btn rp-btn-primary" wire:click="nextStep">Vérifier ma reprise</button>
                        </div>
                    @endif

                    {{-- ==================== Étape 5 — contrôle ==================== --}}
                    @if($this->step === 5)
                        @php $report = $this->report; @endphp
                        <table class="rp-table">
                            <thead>
                                <tr>
                                    <th>Ce que dit votre liasse {{ $this->referenceYear() }}</th>
                                    <th class="rp-num-cell">Votre liasse</th>
                                    <th class="rp-num-cell">OpenLMNP</th>
                                    <th class="rp-num-cell">Écart</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($report['lines'] ?? [] as $line)
                                    @php
                                        $transcription = in_array($line['key'], [
                                            \App\Services\ReprisesCheckService::LINE_DEFERRED_DEPRECIATION,
                                            \App\Services\ReprisesCheckService::LINE_DEFICIT_CARRYFORWARD,
                                        ], true);
                                        $pill = match ($line['verdict']) {
                                            \App\Services\ReprisesCheckService::VERDICT_MATCH => ['rp-pill-ok', $transcription ? 'repris' : 'identique'],
                                            \App\Services\ReprisesCheckService::VERDICT_EXPLAINED => ['rp-pill-ok', 'expliqué'],
                                            \App\Services\ReprisesCheckService::VERDICT_CLOSE => ['rp-pill-warn', 'proche'],
                                            \App\Services\ReprisesCheckService::VERDICT_MISMATCH => ['rp-pill-bad', 'à vérifier'],
                                            default => ['rp-pill-muted', 'non renseigné'],
                                        };
                                    @endphp
                                    <tr>
                                        <td>{{ $line['label'] }} <i>· {{ $line['cerfa'] }}</i></td>
                                        <td class="rp-num-cell">{{ $this->formatEuros($line['declared']) }}</td>
                                        <td class="rp-num-cell">{{ $this->formatEuros($line['computed']) }}</td>
                                        <td class="rp-num-cell">{{ $this->formatSignedEuros($line['difference']) }}</td>
                                        <td><span class="rp-pill {{ $pill[0] }}">{{ $pill[1] }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        @if($report['warning'] ?? null)
                            <div class="rp-note rp-note-warn">
                                <b>Un exercice {{ $this->referenceYear() }} vide contredit vos soldes d'ouverture.</b>
                                {{ $report['warning'] }}
                            </div>
                        @endif

                        @php
                            $verdict = $report['verdict'] ?? \App\Services\ReprisesCheckService::VERDICT_UNCHECKED;
                            $worstLine = null;
                            foreach ($report['lines'] ?? [] as $line) {
                                if ($line['verdict'] === \App\Services\ReprisesCheckService::VERDICT_MISMATCH) {
                                    $worstLine = $line;
                                    break;
                                }
                            }
                        @endphp

                        @if($verdict === \App\Services\ReprisesCheckService::VERDICT_MISMATCH && $worstLine)
                            <div class="rp-note rp-note-bad">
                                <b>
                                    Un écart de {{ $this->formatEuros(abs((int) $worstLine['difference'])) }}
                                    sur « {{ $worstLine['label'] }} ».
                                </b>
                                Les causes possibles, en commençant par celles que vos chiffres appuient :
                                <ul>
                                    @foreach($worstLine['diagnostics'] as $diagnostic)
                                        <li>
                                            @if($diagnostic['corroborated'])
                                                <strong>{{ $diagnostic['label'] }}</strong>
                                            @else
                                                {{ $diagnostic['label'] }}
                                            @endif
                                            : {{ $diagnostic['hint'] }}
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @elseif($verdict === \App\Services\ReprisesCheckService::VERDICT_CLOSE)
                            @php
                                $closeLine = null;
                                foreach ($report['lines'] ?? [] as $line) {
                                    if ($line['verdict'] === \App\Services\ReprisesCheckService::VERDICT_CLOSE
                                        && ($line['diagnostics'] ?? []) !== []) {
                                        $closeLine = $line;
                                        break;
                                    }
                                }
                            @endphp
                            <div class="rp-note rp-note-warn">
                                <b>Votre reprise est presque exacte.</b>
                                @if($closeLine)
                                    {{-- ⚠️ Ne PAS afficher un texte générique quand une cause précise est
                                         identifiée : un écart entièrement expliqué par le rangement des
                                         frais d'acquisition (030 chez nous, 016 chez le cabinet) n'est pas
                                         une « convention d'arrondi », et le dire a égaré une relecture sur
                                         un dossier de test le 2026-10-15. --}}
                                    Sur « {{ $closeLine['label'] }} », écart de
                                    {{ $this->formatSignedEuros($closeLine['difference']) }} :
                                    <ul>
                                        @foreach($closeLine['diagnostics'] as $diagnostic)
                                            <li>
                                                @if($diagnostic['corroborated'])
                                                    <strong>{{ $diagnostic['label'] }}</strong>
                                                @else
                                                    {{ $diagnostic['label'] }}
                                                @endif
                                                : {{ $diagnostic['hint'] }}
                                            </li>
                                        @endforeach
                                    </ul>
                                @else
                                    L'écart reste sous 1 % : il s'explique le plus souvent par une convention
                                    d'arrondi ou de prorata différente de celle de votre comptable.
                                @endif
                                Vous pouvez terminer la reprise.
                            </div>
                        @elseif($verdict === \App\Services\ReprisesCheckService::VERDICT_UNCHECKED)
                            <div class="rp-note rp-note-info">
                                <b>Rien à comparer.</b>
                                Vous n'avez renseigné aucun montant de votre liasse à l'étape 4. La reprise reste
                                possible, mais rien ne garantit qu'elle tombe sur les chiffres de votre comptable.
                            </div>
                        @else
                            @php
                                $explainedLine = null;
                                foreach ($report['lines'] ?? [] as $line) {
                                    if ($line['verdict'] === \App\Services\ReprisesCheckService::VERDICT_EXPLAINED) {
                                        $explainedLine = $line;
                                        break;
                                    }
                                }
                            @endphp
                            <div class="rp-note rp-note-ok">
                                <b>Votre reprise est fidèle à votre liasse.</b>
                                OpenLMNP repart exactement des chiffres de votre comptable. Votre exercice
                                {{ $this->firstYear }} peut être créé.
                                @if($explainedLine)
                                    <br>Sur « {{ $explainedLine['label'] }} », l'écart de
                                    {{ $this->formatSignedEuros($explainedLine['difference']) }} est expliqué :
                                    @foreach($explainedLine['diagnostics'] as $diagnostic)
                                        <strong>{{ $diagnostic['label'] }}.</strong> {{ $diagnostic['hint'] }}
                                    @endforeach
                                    Rien à corriger.
                                @endif
                            </div>
                        @endif

                        <div class="rp-actions">
                            <div class="rp-actions-left">
                                <button type="button" class="rp-btn rp-btn-ghost" wire:click="previousStep">Retour</button>
                                @if($this->acquisitionFeesExpensedByFirm())
                                    <button type="button" class="rp-btn rp-btn-primary" wire:click="expenseAcquisitionFees">
                                        Passer mes frais en charges, comme mon cabinet
                                    </button>
                                @endif
                            </div>
                            @if($verdict === \App\Services\ReprisesCheckService::VERDICT_MISMATCH)
                                <button type="button" class="rp-btn rp-btn-ghost" wire:click="finish">Terminer quand même</button>
                            @else
                                <button type="button" class="rp-btn rp-btn-primary" wire:click="finish">Terminer la reprise</button>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
