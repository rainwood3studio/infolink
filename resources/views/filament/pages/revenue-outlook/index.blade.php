<x-filament-panels::page>
    <style>
        .ro-root {
            --ro-receivables: #2a78d6; --ro-recurring: #1baf7a; --ro-low: #eda100; --ro-pipeline: #4a3aa7;
            --ro-cost: color-mix(in oklab, var(--gray-500) 40%, transparent); --ro-planned: color-mix(in oklab, var(--gray-500) 85%, transparent);
            --ro-surface: #fff; --ro-danger: var(--danger-600); --ro-warn: var(--warning-600); --ro-link: var(--primary-600);
            --ro-rule: color-mix(in oklab, var(--gray-500) 18%, transparent); --ro-zero: color-mix(in oklab, var(--gray-500) 85%, transparent);
        }
        .dark .ro-root {
            --ro-receivables: #3987e5; --ro-recurring: #199e70; --ro-low: #c98500; --ro-pipeline: #9085e9;
            --ro-surface: var(--gray-900); --ro-danger: var(--danger-400); --ro-warn: var(--warning-400); --ro-link: var(--primary-400);
        }
        .ro-root[data-loading] { opacity: 0.5; transition: opacity 150ms; }
        .ro-muted { opacity: 0.65; }
        .ro-num { font-variant-numeric: tabular-nums; }
        .ro-danger { color: var(--ro-danger); }
        .ro-warn { color: var(--ro-warn); }
        .ro-link { color: var(--ro-link); text-decoration: none; }
        .ro-link:hover { text-decoration: underline; }
        .ro-seg { display: inline-flex; border-radius: 0.5rem; box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--gray-500) 30%, transparent); overflow: hidden; }
        .ro-seg button { padding: 0.375rem 0.75rem; font-size: 0.8125rem; color: inherit; background: transparent; cursor: pointer; white-space: nowrap; }
        .ro-seg button + button { border-left: 1px solid color-mix(in oklab, var(--gray-500) 30%, transparent); }
        .ro-seg button:hover { background: color-mix(in oklab, var(--gray-500) 10%, transparent); }
        .ro-seg button:focus-visible { outline: 2px solid var(--primary-500); outline-offset: -2px; }
        .ro-seg button[aria-pressed="true"] { background: color-mix(in oklab, var(--primary-500) 16%, transparent); font-weight: 600; }
        .ro-legend { display: flex; flex-wrap: wrap; gap: 0.25rem 1rem; font-size: 0.75rem; }
        .ro-legend > span { display: inline-flex; align-items: center; gap: 0.375rem; white-space: nowrap; }
        .ro-swatch { display: inline-block; width: 0.75rem; height: 0.75rem; border-radius: 0.125rem; flex: none; }
        .ro-swatch-assumed { background: repeating-linear-gradient(45deg, var(--ro-recurring) 0 2px, color-mix(in oklab, var(--ro-recurring) 25%, transparent) 2px 5px); }
        .ro-swatch-pipeline { background: color-mix(in oklab, var(--ro-pipeline) 14%, transparent); box-shadow: inset 0 0 0 1.5px var(--ro-pipeline); }
        .ro-chart { display: block; width: 100%; min-width: 44rem; height: auto; }
        .ro-chart text { fill: currentColor; font-size: 11px; font-variant-numeric: tabular-nums; }
        .ro-chart .ro-tick { opacity: 0.65; }
        .ro-chart .ro-label { font-size: 12px; font-weight: 600; }
        .ro-chart .ro-hit { fill: transparent; }
        .ro-chart .ro-hit:hover { fill: color-mix(in oklab, var(--gray-500) 10%, transparent); }
        .ro-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .ro-table th { padding: 0.25rem 0.875rem 0.375rem 0; text-align: right; font-size: 0.75rem; font-weight: 500; opacity: 0.65; white-space: nowrap; vertical-align: bottom; }
        .ro-table td { padding: 0.375rem 0.875rem 0.375rem 0; border-top: 1px solid var(--ro-rule); text-align: right; vertical-align: baseline; white-space: nowrap; }
        .ro-table th:first-child, .ro-table td:first-child { text-align: left; }
        .ro-table th:last-child, .ro-table td:last-child { padding-right: 0; }
        .ro-table.ro-list th, .ro-table.ro-list td { text-align: left; }
        .ro-table.ro-list th.ro-end, .ro-table.ro-list td.ro-end { text-align: right; }
        .ro-table td.ro-wrap { white-space: normal; min-width: 12rem; overflow-wrap: anywhere; }
        .ro-zero-cell { opacity: 0.35; }
        .ro-badge { display: inline-flex; flex: none; min-width: max-content; }
    </style>

    <div class="ro-root" wire:loading.delay.attr="data-loading" style="display: flex; flex-direction: column; gap: 1.5rem;">
        @include('filament.pages.revenue-outlook.controls')
        @include('filament.pages.revenue-outlook.stats')
        @include('filament.pages.revenue-outlook.chart')
        @include('filament.pages.revenue-outlook.table')
        @include('filament.pages.revenue-outlook.closing')
        @include('filament.pages.revenue-outlook.deals')
    </div>
</x-filament-panels::page>
