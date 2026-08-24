<x-filament-panels::page>
    <style>
        /* Summary box */
        .wz-box { border-radius: 0.5rem; border: 1px solid var(--olmnp-border); background: var(--olmnp-surface-muted); padding: 1rem; }
        .wz-box-title { font-size: 0.875rem; font-weight: 600; color: var(--olmnp-fg); margin-bottom: 0.75rem; }
        .wz-badge { margin-left: 0.5rem; border-radius: 9999px; background: var(--olmnp-warning-bg-strong); padding: 0.125rem 0.5rem; font-size: 0.75rem; font-weight: 500; color: var(--olmnp-warning-fg); }
        .wz-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; }
        .wz-stat { text-align: center; }
        .wz-stat-value { font-size: 1.5rem; font-weight: 700; }
        .wz-stat-label { font-size: 0.75rem; color: var(--olmnp-fg-muted); margin-top: 0.25rem; }

        /* Colors */
        .wz-indigo { color: var(--olmnp-accent-fg); }
        .wz-green { color: var(--olmnp-success-accent); }
        .wz-red { color: var(--olmnp-danger-accent); }
        .wz-green-dark { color: var(--olmnp-success-fg); }
        .wz-red-dark { color: var(--olmnp-danger-fg); }
        .wz-blue { color: var(--olmnp-info-fg); }
        .wz-purple { color: var(--olmnp-accent-fg); }
        .wz-orange { color: var(--olmnp-warning-fg); }
        .wz-muted { color: var(--olmnp-fg-muted); }

        /* Amount + detail lines */
        .wz-amount { display: flex; flex-direction: column; gap: 0.125rem; }
        .wz-amount-value { font-size: 1.25rem; font-weight: 700; }
        .wz-amount-detail { font-size: 0.75rem; color: var(--olmnp-fg-muted); }

        /* Result box */
        .wz-result { border-radius: 0.5rem; border: 1px solid; padding: 1rem; }
        .wz-result-positive { border-color: var(--olmnp-info-border); background: var(--olmnp-info-bg); }
        .wz-result-zero { border-color: var(--olmnp-success-border); background: var(--olmnp-success-bg); }
        .wz-result-value { font-size: 1.5rem; font-weight: 700; color: var(--olmnp-fg-strong); }
        .wz-result-label { margin-top: 0.25rem; font-size: 0.875rem; color: var(--olmnp-fg); }

        /* Comparison table */
        .wz-table { width: 100%; font-size: 0.875rem; border-collapse: collapse; }
        .wz-table th { padding: 0.5rem 0.75rem; text-align: left; font-weight: 600; color: var(--olmnp-fg); background: var(--olmnp-surface-alt); }
        .wz-table td { padding: 0.5rem 0.75rem; }
        .wz-table .wz-num { text-align: right; font-family: ui-monospace, monospace; }
        .wz-table tr:nth-child(even) { background: var(--olmnp-surface-muted); }
        .wz-table-wrap { overflow: auto; border-radius: 0.5rem; border: 1px solid var(--olmnp-border); }

        /* Verdict */
        .wz-verdict { border-radius: 0.5rem; border: 1px solid; padding: 0.75rem; font-size: 0.875rem; font-weight: 500; }
        .wz-verdict-good { border-color: var(--olmnp-success-border); background: var(--olmnp-success-bg); color: var(--olmnp-success-fg); }
        .wz-verdict-bad { border-color: var(--olmnp-warning-border); background: var(--olmnp-warning-bg); color: var(--olmnp-warning-fg); }

        /* Alerts */
        .wz-alerts { display: flex; flex-direction: column; gap: 0.5rem; }
        .wz-alert { display: flex; align-items: flex-start; gap: 0.5rem; border-radius: 0.5rem; border: 1px solid; padding: 1rem; font-size: 0.875rem; }
        .wz-alert-danger { border-color: var(--olmnp-danger-border); background: var(--olmnp-danger-bg); color: var(--olmnp-danger-fg); }
        .wz-alert-warning { border-color: var(--olmnp-warning-border); background: var(--olmnp-warning-bg); color: var(--olmnp-warning-fg); }
        .wz-alert-icon { flex-shrink: 0; margin-top: 0.125rem; }

        /* Confirmation table */
        .wz-confirm { border-radius: 0.5rem; border: 1px solid var(--olmnp-border); overflow: hidden; }
        .wz-confirm table { width: 100%; }
        .wz-confirm td { padding: 0.5rem; font-size: 0.875rem; }
        .wz-confirm td:first-child { color: var(--olmnp-fg); font-weight: 500; padding-right: 1rem; }
        .wz-confirm td:last-child { text-align: right; font-family: ui-monospace, monospace; color: var(--olmnp-fg-strong); }
        .wz-confirm tr { border-bottom: 1px solid var(--olmnp-surface-alt); }
        .wz-confirm tr:last-child { border-bottom: none; }

        /* Status info */
        .wz-status-info { border-radius: 0.5rem; border: 1px solid var(--olmnp-info-border); background: var(--olmnp-info-bg); color: var(--olmnp-info-fg); padding: 1rem; font-size: 0.875rem; }

    </style>

    <form wire:submit="create">
        {{ $this->form }}
    </form>
</x-filament-panels::page>
