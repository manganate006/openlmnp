<x-filament-panels::page>
    <style>
        /* Summary box */
        .wz-box { border-radius: 0.5rem; border: 1px solid #e5e7eb; background: #f9fafb; padding: 1rem; }
        .wz-box-title { font-size: 0.875rem; font-weight: 600; color: #374151; margin-bottom: 0.75rem; }
        .wz-badge { margin-left: 0.5rem; border-radius: 9999px; background: #fef3c7; padding: 0.125rem 0.5rem; font-size: 0.75rem; font-weight: 500; color: #92400e; }
        .wz-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; }
        .wz-stat { text-align: center; }
        .wz-stat-value { font-size: 1.5rem; font-weight: 700; }
        .wz-stat-label { font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem; }

        /* Colors */
        .wz-indigo { color: #4f46e5; }
        .wz-green { color: #16a34a; }
        .wz-red { color: #dc2626; }
        .wz-green-dark { color: #15803d; }
        .wz-red-dark { color: #b91c1c; }
        .wz-blue { color: #1d4ed8; }
        .wz-purple { color: #7e22ce; }
        .wz-orange { color: #c2410c; }
        .wz-muted { color: #6b7280; }

        /* Amount + detail lines */
        .wz-amount { display: flex; flex-direction: column; gap: 0.125rem; }
        .wz-amount-value { font-size: 1.25rem; font-weight: 700; }
        .wz-amount-detail { font-size: 0.75rem; color: #6b7280; }

        /* Result box */
        .wz-result { border-radius: 0.5rem; border: 1px solid; padding: 1rem; }
        .wz-result-positive { border-color: #93c5fd; background: #eff6ff; }
        .wz-result-zero { border-color: #86efac; background: #f0fdf4; }
        .wz-result-value { font-size: 1.5rem; font-weight: 700; color: #111827; }
        .wz-result-label { margin-top: 0.25rem; font-size: 0.875rem; color: #4b5563; }

        /* Comparison table */
        .wz-table { width: 100%; font-size: 0.875rem; border-collapse: collapse; }
        .wz-table th { padding: 0.5rem 0.75rem; text-align: left; font-weight: 600; color: #374151; background: #f3f4f6; }
        .wz-table td { padding: 0.5rem 0.75rem; }
        .wz-table .wz-num { text-align: right; font-family: ui-monospace, monospace; }
        .wz-table tr:nth-child(even) { background: #f9fafb; }
        .wz-table-wrap { overflow: auto; border-radius: 0.5rem; border: 1px solid #e5e7eb; }

        /* Verdict */
        .wz-verdict { border-radius: 0.5rem; border: 1px solid; padding: 0.75rem; font-size: 0.875rem; font-weight: 500; }
        .wz-verdict-good { border-color: #86efac; background: #f0fdf4; color: #166534; }
        .wz-verdict-bad { border-color: #fcd34d; background: #fffbeb; color: #92400e; }

        /* Alerts */
        .wz-alerts { display: flex; flex-direction: column; gap: 0.5rem; }
        .wz-alert { display: flex; align-items: flex-start; gap: 0.5rem; border-radius: 0.5rem; border: 1px solid; padding: 1rem; font-size: 0.875rem; }
        .wz-alert-danger { border-color: #fca5a5; background: #fef2f2; color: #991b1b; }
        .wz-alert-warning { border-color: #fcd34d; background: #fffbeb; color: #92400e; }
        .wz-alert-icon { flex-shrink: 0; margin-top: 0.125rem; }

        /* Confirmation table */
        .wz-confirm { border-radius: 0.5rem; border: 1px solid #e5e7eb; overflow: hidden; }
        .wz-confirm table { width: 100%; }
        .wz-confirm td { padding: 0.5rem; font-size: 0.875rem; }
        .wz-confirm td:first-child { color: #4b5563; font-weight: 500; padding-right: 1rem; }
        .wz-confirm td:last-child { text-align: right; font-family: ui-monospace, monospace; color: #111827; }
        .wz-confirm tr { border-bottom: 1px solid #f3f4f6; }
        .wz-confirm tr:last-child { border-bottom: none; }

        /* Dark mode */
        .dark .wz-box { border-color: #374151; background: rgba(31,41,55,0.5); }
        .dark .wz-box-title { color: #d1d5db; }
        .dark .wz-badge { background: #78350f; color: #fde68a; }
        .dark .wz-stat-label { color: #9ca3af; }
        .dark .wz-amount-detail { color: #9ca3af; }
        .dark .wz-green-dark { color: #4ade80; }
        .dark .wz-red-dark { color: #f87171; }
        .dark .wz-blue { color: #60a5fa; }
        .dark .wz-purple { color: #c084fc; }
        .dark .wz-orange { color: #fb923c; }
        .dark .wz-result-positive { border-color: #1e3a5f; background: rgba(30,58,138,0.3); }
        .dark .wz-result-zero { border-color: #14532d; background: rgba(20,83,45,0.3); }
        .dark .wz-result-value { color: #fff; }
        .dark .wz-result-label { color: #9ca3af; }
        .dark .wz-table th { background: #374151; color: #d1d5db; }
        .dark .wz-table tr:nth-child(even) { background: rgba(31,41,55,0.5); }
        .dark .wz-table-wrap { border-color: #374151; }
        .dark .wz-verdict-good { border-color: #14532d; background: rgba(20,83,45,0.3); color: #86efac; }
        .dark .wz-verdict-bad { border-color: #78350f; background: rgba(120,53,15,0.3); color: #fde68a; }
        .dark .wz-alert-danger { border-color: #7f1d1d; background: rgba(127,29,29,0.2); color: #fca5a5; }
        .dark .wz-alert-warning { border-color: #78350f; background: rgba(120,53,15,0.2); color: #fde68a; }
        .dark .wz-confirm { border-color: #374151; }
        .dark .wz-confirm td:first-child { color: #9ca3af; }
        .dark .wz-confirm td:last-child { color: #fff; }
        .dark .wz-confirm tr { border-color: #374151; }
    </style>

    <form wire:submit="create">
        {{ $this->form }}
    </form>
</x-filament-panels::page>
