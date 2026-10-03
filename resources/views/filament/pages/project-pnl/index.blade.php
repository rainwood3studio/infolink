@php
    use App\Filament\Pages\ClosingProjects;
@endphp

<x-filament-panels::page>
    <style>
        .pp-root {
            --pp-effort: #2a78d6; --pp-revenue: #1baf7a;
            --pp-danger: var(--danger-600); --pp-warn: var(--warning-600); --pp-link: var(--primary-600);
            --pp-rule: color-mix(in oklab, var(--gray-500) 18%, transparent); --pp-track: color-mix(in oklab, var(--gray-500) 12%, transparent);
        }
        .dark .pp-root {
            --pp-effort: #3987e5; --pp-revenue: #199e70;
            --pp-danger: var(--danger-400); --pp-warn: var(--warning-400); --pp-link: var(--primary-400);
        }
        .pp-root[data-loading] { opacity: 0.5; transition: opacity 150ms; }
        .pp-muted { opacity: 0.65; }
        .pp-num { font-variant-numeric: tabular-nums; }
        .pp-danger { color: var(--pp-danger); }
        .pp-warn { color: var(--pp-warn); }
        .pp-link { color: var(--pp-link); text-decoration: none; }
        .pp-link:hover { text-decoration: underline; }
        .pp-legend { display: flex; flex-wrap: wrap; gap: 0.25rem 1rem; font-size: 0.75rem; }
        .pp-legend > span { display: inline-flex; align-items: center; gap: 0.375rem; white-space: nowrap; }
        .pp-swatch { display: inline-block; width: 0.75rem; height: 0.75rem; border-radius: 0.125rem; flex: none; }
        .pp-track { flex: 1 1 auto; min-width: 3rem; height: 0.625rem; border-radius: 0 4px 4px 0; background: var(--pp-track); }
        .pp-fill { height: 100%; min-width: 2px; border-radius: 0 4px 4px 0; }
        .pp-fill:hover { filter: brightness(1.12); }
        .pp-fill-effort { background: var(--pp-effort); }
        .pp-fill-revenue { background: var(--pp-revenue); }
        .pp-customer { display: grid; grid-template-columns: minmax(6rem, 11rem) minmax(0, 1fr); gap: 0.25rem 1rem; align-items: center; padding: 0.5rem 0; border-top: 1px solid var(--pp-rule); }
        .pp-customer:first-child { border-top: 0; padding-top: 0; }
        .pp-barline { display: flex; align-items: center; gap: 0.625rem; font-size: 0.8125rem; }
        .pp-barline > .pp-value { flex: 0 0 13.5rem; white-space: nowrap; }
        .pp-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .pp-table th { padding: 0.25rem 0.75rem 0.375rem 0; text-align: left; font-size: 0.75rem; font-weight: 500; opacity: 0.65; white-space: nowrap; vertical-align: bottom; }
        .pp-table td { padding: 0.5rem 0.75rem 0.5rem 0; border-top: 1px solid var(--pp-rule); text-align: left; vertical-align: top; white-space: nowrap; }
        .pp-table th.pp-end, .pp-table td.pp-end { text-align: right; }
        .pp-table th:last-child, .pp-table td:last-child { padding-right: 0; }
        .pp-table td.pp-wrap { white-space: normal; min-width: 8rem; overflow-wrap: anywhere; }
        .pp-sub { font-size: 0.75rem; opacity: 0.65; }
        .pp-badge { display: inline-flex; flex: none; min-width: max-content; }
        .pp-mini { width: 5rem; height: 0.375rem; margin-top: 0.3125rem; border-radius: 0 4px 4px 0; background: var(--pp-track); }
        @media (max-width: 40rem) {
            .pp-customer { grid-template-columns: minmax(0, 1fr); }
            .pp-barline > .pp-value { flex-basis: 9.5rem; white-space: normal; }
        }
    </style>

    <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem 1rem;">
        <x-filament::tabs contained label="期間">
            @foreach ($periods as $key => $label)
                <x-filament::tabs.item :active="$this->period === $key" wire:click="setPeriod('{{ $key }}')" wire:key="period-{{ $key }}">
                    {{ $label }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        <span style="font-size: 0.875rem; opacity: 0.7; font-variant-numeric: tabular-nums;">
            {{ ClosingProjects::shortDate($pnl['period']['from']) }} – {{ ClosingProjects::shortDate($pnl['period']['to']) }}（{{ $pnl['period']['days'] }} 天）
        </span>
    </div>

    @if ($pnl['total_commits'] === 0)
        <x-filament::section>
            <x-filament::empty-state
                heading="這段期間沒有 commit"
                description="GitHub 只保留近一個月的 commit。換一個期間看看，或到「交付 → 開發活動」按「立即同步」。"
                :icon="\Filament\Support\Icons\Heroicon::OutlinedScale"
            />
        </x-filament::section>
    @else
        <div class="pp-root" wire:loading.delay.attr="data-loading" style="display: flex; flex-direction: column; gap: 1.5rem;">
            @include('filament.pages.project-pnl.stats')
            @include('filament.pages.project-pnl.customers')
            @include('filament.pages.project-pnl.projects')
            @include('filament.pages.project-pnl.presales')
            @includeWhen($pnl['unmapped'] !== [], 'filament.pages.project-pnl.unmapped')
        </div>
    @endif
</x-filament-panels::page>
