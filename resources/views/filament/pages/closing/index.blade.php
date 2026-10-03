<x-filament-panels::page>
    <style>
        .cp-root { --cp-unassigned: #eda100; --cp-in_progress: #2a78d6; --cp-off_flow: #eb6834; --cp-awaiting_acceptance: #1baf7a; --cp-danger: var(--danger-600); --cp-success: var(--success-600); --cp-line: var(--primary-600); --cp-rule: color-mix(in oklab, var(--gray-500) 18%, transparent); }
        .dark .cp-root { --cp-unassigned: #c98500; --cp-in_progress: #3987e5; --cp-off_flow: #d95926; --cp-awaiting_acceptance: #199e70; --cp-danger: var(--danger-400); --cp-success: var(--success-400); --cp-line: var(--primary-400); }
        .cp-root[data-loading] { opacity: 0.5; transition: opacity 150ms; }
        .cp-card { cursor: pointer; border-radius: 0.75rem; transition: box-shadow 150ms; }
        .cp-card:hover { box-shadow: 0 0 0 1px color-mix(in oklab, var(--primary-500) 50%, transparent); }
        .cp-card:focus-visible { outline: 2px solid var(--primary-500); outline-offset: 2px; }
        .cp-card[aria-pressed="true"] { box-shadow: 0 0 0 2px var(--primary-500); }
        .cp-muted { opacity: 0.65; }
        .cp-num { font-variant-numeric: tabular-nums; }
        .cp-danger { color: var(--cp-danger); }
        .cp-success { color: var(--cp-success); }
        .cp-link { color: inherit; text-decoration: none; }
        .cp-link:hover { text-decoration: underline; }
        .cp-rule { border-top: 1px solid var(--cp-rule); padding-top: 0.75rem; }
        .cp-swatch { display: inline-block; width: 0.625rem; height: 0.625rem; border-radius: 0.125rem; flex: none; }
        .cp-bar { display: flex; gap: 2px; height: 0.625rem; }
        .cp-bar > span { min-width: 3px; border-radius: 0.125rem; }
        .cp-bar > span:hover { filter: brightness(1.15); }
        .cp-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .cp-table th { padding: 0.25rem 0.75rem 0.375rem 0; text-align: left; font-size: 0.75rem; font-weight: 500; opacity: 0.65; white-space: nowrap; }
        .cp-table td { padding: 0.375rem 0.75rem 0.375rem 0; border-top: 1px solid var(--cp-rule); vertical-align: baseline; white-space: nowrap; }
        .cp-table td.cp-subject { width: 100%; min-width: 16rem; white-space: normal; overflow-wrap: anywhere; }
        .cp-table th:last-child, .cp-table td:last-child { padding-right: 0; text-align: right; }
    </style>

    @if ($projects->isEmpty())
        <x-filament::section>
            <x-filament::empty-state
                heading="目前沒有結案中的專案"
                description="把專案狀態改成「結案中」並設定目標結案日與 Redmine 專案，就會出現在這裡。"
                :icon="\Filament\Support\Icons\Heroicon::OutlinedFlag"
            />
        </x-filament::section>
    @else
        <div class="cp-root" wire:loading.delay.attr="data-loading" style="display: flex; flex-direction: column; gap: 1.5rem;">
            @include('filament.pages.closing.summary')
            @include('filament.pages.closing.cards')
            @include('filament.pages.closing.issues')
        </div>
    @endif
</x-filament-panels::page>
