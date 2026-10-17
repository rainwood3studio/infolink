@php
    use App\Domain\Finance\ExpenseCalendar as Calendar;
    use App\Filament\Pages\ExpenseCalendar;

    $summary = $calendar['summary'];
    $isEmpty = $summary['items'] === 0 && $summary['recurring_expenses'] === 0;
    $today = $summary['today'];
@endphp

<x-filament-panels::page>
    <style>
        .ec-root { --ec-rule: color-mix(in oklab, var(--gray-500) 18%, transparent); --ec-danger: var(--danger-600); --ec-link: var(--primary-600); --ec-today: color-mix(in oklab, var(--primary-500) 10%, transparent); }
        .dark .ec-root { --ec-danger: var(--danger-400); --ec-link: var(--primary-400); }
        .ec-muted { opacity: 0.65; }
        .ec-num { font-variant-numeric: tabular-nums; }
        .ec-link { color: inherit; text-decoration: none; }
        .ec-link:hover { color: var(--ec-link); text-decoration: underline; }
        .ec-headline { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.25rem 1.5rem; font-size: 0.9375rem; }
        .ec-headline strong { font-weight: 600; }
        .ec-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .ec-table th { padding: 0.25rem 0.75rem 0.375rem 0; text-align: left; font-size: 0.75rem; font-weight: 500; opacity: 0.65; white-space: nowrap; }
        .ec-table td { padding: 0.5rem 0.75rem 0.5rem 0; border-top: 1px solid var(--ec-rule); vertical-align: baseline; white-space: nowrap; }
        .ec-table td.ec-name { width: 100%; white-space: normal; overflow-wrap: anywhere; }
        .ec-table th.ec-end, .ec-table td.ec-end { text-align: right; }
        .ec-table th:last-child, .ec-table td:last-child { padding-right: 0; }
        .ec-table tr.ec-today td { background: var(--ec-today); }
        .ec-table td.ec-covered { opacity: 0.6; }
        .ec-month-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 0.25rem 1rem; }
        .ec-badge { display: inline-flex; flex: none; min-width: max-content; }
        .ec-badges { display: inline-flex; flex-wrap: wrap; gap: 0.25rem; vertical-align: middle; }
    </style>

    <div class="ec-root" style="display: flex; flex-direction: column; gap: 1.5rem;">
        <x-filament::section compact>
            <div class="ec-headline ec-num">
                <span>月成本基準 <strong>{{ $summary['has_baseline'] ? ExpenseCalendar::money($summary['monthly_cost']) : '尚未設定' }}</strong></span>
                <span>接下來 {{ Calendar::LOOKAHEAD_DAYS }} 天額外固定支出 <strong>{{ ExpenseCalendar::money($summary['extra_90d']) }}</strong></span>
                @if ($summary['next_due'] !== null)
                    <span class="ec-muted" style="font-size: 0.8125rem;">
                        最近一筆：{{ ExpenseCalendar::dateLabel($summary['next_due']['date']) }} {{ $summary['next_due']['name'] }} {{ ExpenseCalendar::money($summary['next_due']['amount']) }}{{ $summary['next_due']['in_baseline'] ? '（已含月成本）' : '' }}
                    </span>
                @endif
            </div>
        </x-filament::section>

        @if ($isEmpty)
            <x-filament::section>
                <x-filament::empty-state
                    heading="還沒有固定支出"
                    description="先新增營業稅（雙月 15 日）、勞健保（每月月底）、營所稅（每年 5 月）、年終獎金、保險、租金…有日期的支出才會出現在日曆，推估也才會在到期日扣掉。"
                    :icon="\Filament\Support\Icons\Heroicon::OutlinedCalendarDays"
                >
                    <x-slot name="footer">
                        <x-filament::button tag="a" :href="$createUrl" icon="heroicon-o-plus">
                            新增固定支出
                        </x-filament::button>
                    </x-slot>
                </x-filament::empty-state>
            </x-filament::section>
        @else
            @foreach ($calendar['months'] as $month)
                @include('filament.pages.expense-calendar.month', ['month' => $month, 'today' => $today, 'editUrl' => $editUrl])
            @endforeach

            <div class="ec-muted" style="font-size: 0.75rem;">
                「額外」是月成本基準之外要另外準備的錢：固定支出裡沒勾「已含月成本」的，加上已排定的一次性支出。金額是新台幣整數。
            </div>
        @endif
    </div>
</x-filament-panels::page>
