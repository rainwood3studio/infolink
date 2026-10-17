@php
    $summary = $billing['summary'];
    $hasProjects = $billing['projects'] !== [];
@endphp

<x-filament-panels::page>
    <style>
        .bp-root {
            --bp-received: #1baf7a; --bp-invoiced: #2a78d6; --bp-planned: #eda100; --bp-unscheduled: color-mix(in oklab, var(--gray-500) 22%, transparent);
            --bp-over: #dc2626; --bp-danger: var(--danger-600); --bp-warn: var(--warning-600); --bp-ok: var(--success-600); --bp-link: var(--primary-600);
            --bp-rule: color-mix(in oklab, var(--gray-500) 18%, transparent); --bp-track: color-mix(in oklab, var(--gray-500) 12%, transparent);
        }
        .dark .bp-root {
            --bp-received: #199e70; --bp-invoiced: #3987e5; --bp-planned: #c98500; --bp-over: #ef4444;
            --bp-danger: var(--danger-400); --bp-warn: var(--warning-400); --bp-ok: var(--success-400); --bp-link: var(--primary-400);
        }
        .bp-root[data-loading] { opacity: 0.5; transition: opacity 150ms; }
        .bp-muted { opacity: 0.65; }
        .bp-num { font-variant-numeric: tabular-nums; }
        .bp-danger { color: var(--bp-danger); }
        .bp-warn { color: var(--bp-warn); }
        .bp-ok { color: var(--bp-ok); }
        .bp-link { color: inherit; text-decoration: none; }
        .bp-link:hover { color: var(--bp-link); text-decoration: underline; }
        .bp-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 0.25rem 1rem; }
        .bp-figures { display: flex; flex-wrap: wrap; gap: 0.25rem 1.25rem; font-size: 0.8125rem; }
        .bp-bar { display: flex; width: 100%; height: 0.875rem; border-radius: 0.375rem; overflow: hidden; background: var(--bp-track); }
        .bp-bar > span { display: block; height: 100%; min-width: 0; }
        .bp-bar > span:hover { filter: brightness(1.12); }
        .bp-seg-received { background: var(--bp-received); }
        .bp-seg-invoiced { background: var(--bp-invoiced); }
        .bp-seg-planned { background: var(--bp-planned); }
        .bp-seg-unscheduled { background: repeating-linear-gradient(135deg, var(--bp-unscheduled) 0 4px, transparent 4px 8px); }
        .bp-seg-over { background: repeating-linear-gradient(135deg, var(--bp-over) 0 4px, transparent 4px 8px); }
        .bp-legend { display: flex; flex-wrap: wrap; gap: 0.25rem 1rem; font-size: 0.75rem; }
        .bp-legend > span { display: inline-flex; align-items: center; gap: 0.375rem; white-space: nowrap; }
        .bp-swatch { display: inline-block; width: 0.75rem; height: 0.75rem; border-radius: 0.125rem; flex: none; }
        .bp-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .bp-table th { padding: 0.25rem 0.75rem 0.375rem 0; text-align: left; font-size: 0.75rem; font-weight: 500; opacity: 0.65; white-space: nowrap; }
        .bp-table td { padding: 0.5rem 0.75rem 0.5rem 0; border-top: 1px solid var(--bp-rule); white-space: nowrap; vertical-align: top; }
        .bp-table th.bp-end, .bp-table td.bp-end { text-align: right; }
        .bp-table th:last-child, .bp-table td:last-child { padding-right: 0; }
        .bp-table td.bp-wrap { white-space: normal; }
        .bp-badge { display: inline-flex; flex: none; min-width: max-content; }
        .bp-details > summary { cursor: pointer; list-style: none; display: inline-flex; align-items: center; gap: 0.375rem; font-size: 0.8125rem; opacity: 0.75; user-select: none; }
        .bp-details > summary::-webkit-details-marker { display: none; }
        .bp-details > summary::before { content: '▸'; font-size: 0.75rem; }
        .bp-details[open] > summary::before { content: '▾'; }
        .bp-details > summary:hover { opacity: 1; }
        .bp-actions { display: inline-flex; gap: 0.375rem; flex-wrap: wrap; }
        .bp-btn { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.125rem 0.5rem; border-radius: 0.375rem; font-size: 0.75rem; line-height: 1.25rem; font-weight: 500; border: 1px solid var(--bp-rule); background: transparent; cursor: pointer; white-space: nowrap; }
        .bp-btn:hover { background: var(--bp-track); }
        .bp-btn-primary { border-color: var(--bp-received); color: var(--bp-received); }
        .bp-periods { display: flex; flex-wrap: wrap; gap: 0.375rem; }
        .bp-period { display: inline-flex; flex-direction: column; gap: 0.0625rem; min-width: 4.5rem; padding: 0.25rem 0.5rem; border-radius: 0.375rem; font-size: 0.6875rem; line-height: 1rem; border-left: 3px solid var(--bp-unscheduled); background: var(--bp-track); }
        .bp-period-received { border-left-color: var(--bp-received); }
        .bp-period-invoiced { border-left-color: var(--bp-invoiced); }
        .bp-period-planned { border-left-color: var(--bp-planned); background: color-mix(in oklab, var(--bp-planned) 14%, transparent); }
        .bp-period-missing { border-left-color: var(--bp-over); background: color-mix(in oklab, var(--bp-over) 12%, transparent); }
        .bp-period-future { border-left-style: dashed; opacity: 0.8; }
        .bp-period-current { outline: 1px solid var(--bp-rule); }
    </style>

    <div class="bp-root" wire:loading.delay.attr="data-loading" style="display: flex; flex-direction: column; gap: 1.5rem;">
        @include('filament.pages.billing.stats', ['summary' => $summary, 'deposits' => $deposits])

        <div class="bp-legend">
            <span><span class="bp-swatch bp-seg-received"></span>已收</span>
            <span><span class="bp-swatch bp-seg-invoiced"></span>已開票未收</span>
            <span><span class="bp-swatch bp-seg-planned"></span>已排未開票</span>
            <span><span class="bp-swatch bp-seg-unscheduled"></span>尚未排入應收</span>
            <span><span class="bp-swatch bp-seg-over"></span>超過合約金額</span>
        </div>

        @if (! $hasProjects)
            <x-filament::section>
                <x-filament::empty-state
                    heading="還沒有專案"
                    description="到「客戶／專案」建立專案並填合約金額，再把每一期款項建成應收，這裡就會顯示每個專案從合約到入帳的進度。"
                    :icon="\Filament\Support\Icons\Heroicon::OutlinedDocumentCurrencyDollar"
                />
            </x-filament::section>
        @else
            <div style="display: flex; flex-direction: column; gap: 1rem;">
                <h2 style="font-size: 1rem; font-weight: 600;">各專案請款進度</h2>
                @foreach ($billing['projects'] as $project)
                    @include('filament.pages.billing.project', ['project' => $project, 'today' => $billing['today']])
                @endforeach
            </div>
        @endif

        @includeWhen($billing['without_project'] !== [], 'filament.pages.billing.without-project', ['groups' => $billing['without_project']])

        @include('filament.pages.billing.deposits', ['deposits' => $deposits])

        @include('filament.pages.billing.recurring', ['recurring' => $billing['recurring']])
    </div>
</x-filament-panels::page>
