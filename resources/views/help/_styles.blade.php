@once
<style>
    .ctx-help h3 { font-size: 14px; font-weight: 600; color: var(--olmnp-fg); margin-bottom: 8px; margin-top: 16px; }
    .ctx-help h3:first-child { margin-top: 0; }
    .ctx-help p { font-size: 13px; color: var(--olmnp-fg); line-height: 1.6; margin-bottom: 8px; }
    .ctx-help ul { list-style: none; padding: 0; margin: 0 0 12px 0; }
    .ctx-help ul li { font-size: 13px; color: var(--olmnp-fg); padding: 6px 0 6px 24px; position: relative; line-height: 1.5; border-bottom: 1px solid var(--olmnp-border); }
    .ctx-help ul li:last-child { border-bottom: none; }
    .ctx-help ul li::before { content: attr(data-icon); position: absolute; left: 0; top: 6px; font-size: 14px; }
    .ctx-help strong { color: var(--olmnp-fg); }
    .ctx-help .ctx-tip { background: var(--olmnp-success-bg); border: 1px solid var(--olmnp-success-border); border-radius: 8px; padding: 10px 14px; font-size: 12px; color: var(--olmnp-success-fg); margin-top: 12px; }
    .ctx-help .ctx-warning { background: var(--olmnp-warning-bg); border: 1px solid var(--olmnp-warning-border); border-radius: 8px; padding: 10px 14px; font-size: 12px; color: var(--olmnp-warning-fg); margin-top: 12px; }
    .ctx-help .ctx-step { display: flex; gap: 10px; margin-bottom: 8px; }
    .ctx-help .ctx-step-num { flex-shrink: 0; width: 22px; height: 22px; background: var(--olmnp-success-bg-strong); color: var(--olmnp-success-fg); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; }
    .ctx-help .ctx-step-text { font-size: 13px; color: var(--olmnp-fg); line-height: 1.5; }
</style>
@endonce
