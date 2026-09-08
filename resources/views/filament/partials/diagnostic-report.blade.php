{{--
    Le rapport de diagnostic, tel qu'il sera collé dans un ticket.

    ⚠️ Un <textarea> en lecture seule plutôt qu'un <pre> : sur mobile, sélectionner un texte
    long dans un bloc est une épreuve, alors qu'un champ se sélectionne d'un appui long ou du
    bouton ci-dessous. Le contenu reste du texte brut à colonnes alignées — collé dans une
    issue GitHub entre triples accents, il garde sa mise en page.
--}}
<div class="dr-wrap">
    <p class="dr-note">
        Ce rapport ne part nulle part tout seul : il n'y a aucun envoi automatique.
        Copiez-le, ou téléchargez-le, et joignez-le à votre message.
    </p>

    <textarea class="dr-text" readonly spellcheck="false"
        x-ref="report"
        aria-label="Rapport de diagnostic">{{ $report }}</textarea>

    <div class="dr-actions" x-data="{ copied: false }">
        <button type="button" class="dr-copy"
            x-on:click="navigator.clipboard.writeText($refs.report.value); copied = true; setTimeout(() => copied = false, 2000)">
            <span x-show="! copied">Copier le rapport</span>
            <span x-show="copied" x-cloak>Copié</span>
        </button>
    </div>
</div>

<style>
    .dr-wrap { display: flex; flex-direction: column; gap: 12px; }
    .dr-note { font-size: 12px; color: var(--olmnp-fg-muted); }
    .dr-text {
        width: 100%; height: 420px; font-family: monospace; font-size: 11px; line-height: 1.5;
        padding: 12px; border: 1px solid var(--olmnp-border); border-radius: 8px;
        background: var(--olmnp-surface-muted); color: var(--olmnp-fg-strong);
        white-space: pre; overflow: auto; resize: vertical;
    }
    .dr-actions { display: flex; justify-content: flex-end; }
    .dr-copy {
        cursor: pointer; padding: 6px 14px; font-size: 13px; font-weight: 600; border-radius: 8px;
        background: var(--olmnp-success-solid); color: var(--olmnp-on-solid); border: none;
    }
    .dr-copy:hover { background: var(--olmnp-success-solid-hover); }
</style>
