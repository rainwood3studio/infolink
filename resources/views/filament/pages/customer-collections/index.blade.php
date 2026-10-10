@php
    use App\Filament\Pages\CustomerCollections;
    use App\Filament\Resources\Customers\CustomerResource;

    $summary = $collections['summary'];
    $maintenance = array_values(array_filter($collections['customers'], fn (array $row): bool => $row['maintenance'] !== null));
    $others = array_values(array_filter($collections['customers'], fn (array $row): bool => $row['maintenance'] === null && $row['count'] > 0));
    $silent = array_values(array_filter($collections['customers'], fn (array $row): bool => $row['maintenance'] === null && $row['count'] === 0));
    $problems = $summary['missing_periods'] + $summary['short_periods'];

    $tiles = [
        [
            'label' => '有維護合約的客戶',
            'value' => $summary['maintenance_customers'] === 0 ? '還沒設定' : $summary['maintenance_customers'].' 家',
            'hint' => match (true) {
                $summary['maintenance_customers'] === 0 => '到客戶資料填維護費、週期與起算日，這裡就會逐期對帳',
                $problems === 0 => '每一期都有入帳',
                default => $summary['missing_periods'].' 期未入帳、'.$summary['short_periods'].' 期金額不足',
            },
            'tone' => $problems > 0 ? 'danger' : null,
        ],
        [
            'label' => '這段期間的客戶入帳',
            'value' => CustomerCollections::money($summary['matched_total']),
            'hint' => $summary['with_deposits'].' 家客戶有入帳（含稅，銀行實收）',
            'tone' => null,
        ],
        [
            'label' => '對不到客戶的入帳',
            'value' => $summary['unmatched_total'] === 0 ? '沒有' : CustomerCollections::money($summary['unmatched_total']),
            'hint' => $summary['unmatched_total'] === 0 ? '每一筆收入都認得出是哪個客戶' : count($collections['unmatched']).' 個戶名對不到客戶，清單在最下面',
            'tone' => $summary['unmatched_total'] === 0 ? null : 'warn',
        ],
    ];
@endphp

<x-filament-panels::page>
    <style>
        .cc-root {
            --cc-deposit: #1baf7a; --cc-fee: #2a78d6;
            --cc-paid: #1baf7a; --cc-short: #eda100; --cc-missing: #dc2626; --cc-pending: color-mix(in oklab, var(--gray-500) 35%, transparent);
            --cc-danger: var(--danger-600); --cc-warn: var(--warning-600); --cc-link: var(--primary-600);
            --cc-rule: color-mix(in oklab, var(--gray-500) 18%, transparent); --cc-track: color-mix(in oklab, var(--gray-500) 12%, transparent);
        }
        .dark .cc-root {
            --cc-deposit: #199e70; --cc-fee: #3987e5; --cc-paid: #199e70; --cc-short: #c98500; --cc-missing: #ef4444;
            --cc-danger: var(--danger-400); --cc-warn: var(--warning-400); --cc-link: var(--primary-400);
        }
        .cc-root[data-loading] { opacity: 0.5; transition: opacity 150ms; }
        .cc-muted { opacity: 0.65; }
        .cc-num { font-variant-numeric: tabular-nums; }
        .cc-danger { color: var(--cc-danger); }
        .cc-warn { color: var(--cc-warn); }
        .cc-link { color: inherit; text-decoration: none; }
        .cc-link:hover { color: var(--cc-link); text-decoration: underline; }
        .cc-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 0.25rem 1rem; }
        .cc-figures { display: flex; flex-wrap: wrap; gap: 0.25rem 1.25rem; font-size: 0.8125rem; }
        .cc-chart { display: block; width: 100%; height: auto; }
        .cc-chart text { fill: currentColor; font-size: 11px; font-variant-numeric: tabular-nums; }
        .cc-chart .cc-tick { opacity: 0.6; }
        .cc-chart .cc-grid { stroke: var(--cc-rule); }
        .cc-chart .cc-bar { fill: var(--cc-deposit); }
        .cc-chart .cc-bar:hover { filter: brightness(1.12); }
        .cc-chart .cc-feeline { stroke: var(--cc-fee); stroke-width: 1.5; stroke-dasharray: 5 4; }
        .cc-chart .cc-feelabel { fill: var(--cc-fee); font-weight: 600; }
        .cc-periods { display: flex; flex-wrap: wrap; gap: 0.375rem; }
        .cc-period { display: inline-flex; flex-direction: column; gap: 0.0625rem; min-width: 4.5rem; padding: 0.25rem 0.5rem; border-radius: 0.375rem; font-size: 0.6875rem; line-height: 1rem; border-left: 3px solid var(--cc-pending); background: var(--cc-track); }
        .cc-period-paid { border-left-color: var(--cc-paid); }
        .cc-period-short { border-left-color: var(--cc-short); background: color-mix(in oklab, var(--cc-short) 14%, transparent); }
        .cc-period-missing { border-left-color: var(--cc-missing); background: color-mix(in oklab, var(--cc-missing) 14%, transparent); }
        .cc-period-pending { border-left-style: dashed; }
        .cc-legend { display: flex; flex-wrap: wrap; gap: 0.25rem 1rem; font-size: 0.75rem; }
        .cc-legend > span { display: inline-flex; align-items: center; gap: 0.375rem; white-space: nowrap; }
        .cc-swatch { display: inline-block; width: 0.75rem; height: 0.75rem; border-radius: 0.125rem; flex: none; }
        .cc-swatch-fee { width: 1rem; height: 0; border-top: 2px dashed var(--cc-fee); border-radius: 0; }
        .cc-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .cc-table th { padding: 0.25rem 0.75rem 0.375rem 0; text-align: left; font-size: 0.75rem; font-weight: 500; opacity: 0.65; white-space: nowrap; }
        .cc-table td { padding: 0.5rem 0.75rem 0.5rem 0; border-top: 1px solid var(--cc-rule); white-space: nowrap; vertical-align: top; }
        .cc-table th.cc-end, .cc-table td.cc-end { text-align: right; }
        .cc-table th:last-child, .cc-table td:last-child { padding-right: 0; }
        .cc-badge { display: inline-flex; flex: none; min-width: max-content; }
    </style>

    <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem 1rem;">
        <x-filament::tabs contained label="期間">
            @foreach ($windows as $key => $label)
                <x-filament::tabs.item :active="$this->window === $key" wire:click="setWindow('{{ $key }}')" wire:key="window-{{ $key }}">
                    {{ $label }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        <span style="font-size: 0.875rem; opacity: 0.7; font-variant-numeric: tabular-nums;">
            @if ($collections['has_bank_data'])
                {{ CustomerCollections::monthLabel($collections['months'][0]) }} – {{ CustomerCollections::monthLabel(end($collections['months'])) }}，銀行明細到 {{ CustomerCollections::dateLabel($collections['as_of']) }}
            @else
                還沒有銀行明細
            @endif
        </span>
    </div>

    @if (! $collections['has_bank_data'])
        <x-filament::section>
            <x-filament::empty-state
                heading="還沒有銀行明細"
                description="匯入銀行明細後，這裡會把每個客戶的入帳依月份排開，並逐期檢查維護費有沒有進來。"
                :icon="\Filament\Support\Icons\Heroicon::OutlinedBuildingOffice2"
            />
        </x-filament::section>
    @else
        <div class="cc-root" wire:loading.delay.attr="data-loading" style="display: flex; flex-direction: column; gap: 1.5rem;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: 1rem;">
                @foreach ($tiles as $tile)
                    <x-filament::section compact>
                        <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                            <div class="cc-muted" style="font-size: 0.8125rem;">{{ $tile['label'] }}</div>
                            <div class="cc-num" style="font-size: 1.25rem; font-weight: 600; line-height: 1.75rem; overflow-wrap: anywhere;">{{ $tile['value'] }}</div>
                            <div @class(['cc-danger' => $tile['tone'] === 'danger', 'cc-warn' => $tile['tone'] === 'warn', 'cc-muted' => $tile['tone'] === null]) style="font-size: 0.75rem;">{{ $tile['hint'] }}</div>
                        </div>
                    </x-filament::section>
                @endforeach
            </div>

            <div class="cc-legend">
                <span><span class="cc-swatch" style="background: var(--cc-deposit);"></span>當月入帳（含稅）</span>
                <span><span class="cc-swatch cc-swatch-fee"></span>每月維護費（含稅）</span>
                <span><span class="cc-swatch" style="background: var(--cc-paid);"></span>已入帳</span>
                <span><span class="cc-swatch" style="background: var(--cc-short);"></span>金額不足</span>
                <span><span class="cc-swatch" style="background: var(--cc-missing);"></span>未入帳</span>
                <span><span class="cc-swatch" style="background: var(--cc-pending);"></span>本期尚未入帳</span>
            </div>

            @if ($maintenance !== [])
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <h2 style="font-size: 1rem; font-weight: 600;">有維護合約的客戶</h2>
                    @foreach ($maintenance as $row)
                        @include('filament.pages.customer-collections.customer', ['row' => $row, 'months' => $collections['months']])
                    @endforeach
                </div>
            @endif

            @if ($others !== [])
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <h2 style="font-size: 1rem; font-weight: 600;">其他客戶</h2>
                    @foreach ($others as $row)
                        @include('filament.pages.customer-collections.customer', ['row' => $row, 'months' => $collections['months']])
                    @endforeach
                </div>
            @endif

            @if ($silent !== [])
                <div class="cc-muted" style="font-size: 0.8125rem;">
                    這段期間沒有入帳：{{ implode('、', array_map(fn (array $row): string => $row['short_name'].($row['total_all_time'] > 0 ? '（最近 '.CustomerCollections::dateLabel($row['last_on']).'）' : ''), $silent)) }}
                </div>
            @endif

            @includeWhen($collections['unmatched'] !== [], 'filament.pages.customer-collections.unmatched', ['unmatched' => $collections['unmatched']])
        </div>
    @endif
</x-filament-panels::page>
