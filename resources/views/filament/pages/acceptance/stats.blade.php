@php
    use App\Filament\Support\Money;

    $trim = fn (float $number): string => rtrim(rtrim(number_format($number, 1), '0'), '.');
    $weeksToClear = $flow['weeks_to_clear'];
    $closing = $summary['closing'];
    $recent = $flow['recent'];

    $tiles = [
        [
            'label' => '待驗收',
            'value' => number_format($summary['queue']),
            'unit' => '張',
            'hint' => $summary['queue'] === 0
                ? '隊列是空的'
                : "最久等了 {$summary['oldest_waiting_days']} 天，中位數 {$summary['median_waiting_days']} 天",
            'warn' => false,
        ],
        [
            'label' => '其中擋著結案專案',
            'value' => number_format($closing['issues']),
            'unit' => '張',
            'hint' => $closing['issues'] === 0
                ? '沒有議題擋著結案中的專案'
                : "{$closing['projects']} 個結案中的專案，未收應收 ".Money::format($closing['outstanding_taxed']).'（含稅）',
            'warn' => $closing['issues'] > 0,
        ],
        [
            'label' => '平均每週驗收',
            'value' => $trim($flow['avg_accepted_per_week']),
            'unit' => '張',
            'hint' => '近 '.\App\Domain\Delivery\AcceptanceQueue::AVERAGE_WEEKS.' 個完整週的平均（不含本週）',
            'warn' => false,
        ],
        [
            'label' => '照這速度清空需要',
            'value' => match (true) {
                $summary['queue'] === 0 => '0',
                $weeksToClear === null => '—',
                $weeksToClear < 1 => '< 1',
                $weeksToClear >= 10 => number_format(round($weeksToClear)),
                default => $trim($weeksToClear),
            },
            'unit' => $weeksToClear === null && $summary['queue'] > 0 ? null : '週',
            'hint' => match (true) {
                $summary['queue'] === 0 => '沒有等待驗收的議題',
                $weeksToClear === null => '近四週沒有驗收，無法推估',
                default => "預計 {$flow['projected_clear_date']} 清空（不算之後新送驗的）",
            },
            'warn' => $weeksToClear === null && $summary['queue'] > 0,
        ],
        [
            'label' => '流程外',
            'value' => number_format($summary['off_flow']['total']),
            'unit' => '張',
            'hint' => $summary['off_flow']['total'] === 0
                ? "驗證中的議題都在{$acceptor}名下"
                : "驗證中但不在{$acceptor}名下".($summary['off_flow']['unassigned'] > 0 ? "，其中 {$summary['off_flow']['unassigned']} 張沒指派" : ''),
            'warn' => $summary['off_flow']['total'] > 0,
        ],
    ];
@endphp

<div style="display: flex; flex-direction: column; gap: 0.75rem;">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(10.5rem, 1fr)); gap: 1rem;">
        @foreach ($tiles as $tile)
            <x-filament::section compact>
                <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                    <div class="aq-muted" style="font-size: 0.8125rem;">{{ $tile['label'] }}</div>
                    <div style="display: flex; align-items: baseline; gap: 0.375rem;">
                        <span @class(['aq-num', 'aq-warn' => $tile['warn']]) style="font-size: 1.75rem; font-weight: 600; line-height: 1.2;">{{ $tile['value'] }}</span>
                        @if ($tile['unit'])
                            <span class="aq-muted" style="font-size: 0.875rem;">{{ $tile['unit'] }}</span>
                        @endif
                    </div>
                    <div class="aq-muted" style="font-size: 0.75rem;">{{ $tile['hint'] }}</div>
                </div>
            </x-filament::section>
        @endforeach
    </div>

    @if ($flow['is_growing'])
        <p class="aq-warn" style="font-size: 0.875rem;">
            隊列還在變長：{{ \Carbon\CarbonImmutable::parse($recent['since'])->format('m/d') }} 以來送驗 {{ $recent['handed_over'] }} 張、驗收 {{ $recent['accepted'] }} 張，上面的清空時間只會更久。
        </p>
    @endif
</div>
