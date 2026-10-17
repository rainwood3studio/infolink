@php
    use App\Filament\Pages\CustomerCollections;
    use App\Filament\Pages\RevenueOutlook;
    use App\Filament\Resources\Customers\CustomerResource;

    $maintenance = $row['maintenance'];
    $isMonthly = $maintenance !== null && $maintenance['cycle'] === 'monthly';
    $feeLine = $isMonthly ? $maintenance['fee_taxed'] : null;
    $count = count($months);

    $width = 960;
    $left = 52;
    $right = 12;
    $top = 10;
    $plot = 96;
    $bottom = $top + $plot;
    $band = ($width - $left - $right) / $count;
    $barWidth = round(min(28, $band * 0.6), 1);
    $centerX = fn (int $index): float => round($left + $band * ($index + 0.5), 1);
    $max = max(1, max($row['by_month']), $feeLine ?? 0);
    $step = RevenueOutlook::niceStep($max, 3);
    $hi = max(1, ceil($max / $step)) * $step;
    $y = fn (int|float $value): float => round($bottom - $value / $hi * $plot, 1);
    $labelEvery = $count > 18 ? 3 : ($count > 12 ? 2 : 1);

    $firstMonth = $months[0];
    $periods = $maintenance === null ? [] : array_values(array_filter(
        $maintenance['periods'],
        fn (array $period): bool => $maintenance['cycle'] === 'yearly' || substr($period['to'], 0, 7) >= $firstMonth,
    ));
    $missingLabels = $maintenance === null ? [] : array_column(array_filter($maintenance['periods'], fn (array $period): bool => $period['status'] === 'missing'), 'label');
    $shortLabels = $maintenance === null ? [] : array_column(array_filter($maintenance['periods'], fn (array $period): bool => $period['status'] === 'short'), 'label');
@endphp

<x-filament::section compact wire:key="cc-customer-{{ $row['id'] }}">
    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
        <div class="cc-head">
            <div style="display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.25rem 0.75rem;">
                <a href="{{ CustomerResource::getUrl('edit', ['record' => $row['id']]) }}" class="cc-link" style="font-size: 1rem; font-weight: 600;">{{ $row['short_name'] }}</a>
                <span class="cc-muted" style="font-size: 0.8125rem;">{{ $row['name'] }}</span>
                <a href="{{ CustomerResource::getUrl('view', ['record' => $row['id']]) }}" class="cc-link" style="font-size: 0.75rem; color: var(--cc-link);">總覽 →</a>
                @if ($row['status'] !== 'active')
                    <x-filament::badge color="gray" class="cc-badge">{{ $row['status_label'] }}</x-filament::badge>
                @endif
                @if ($maintenance !== null)
                    <x-filament::badge :color="$maintenance['is_ended'] ? 'gray' : 'info'" class="cc-badge">
                        {{ $maintenance['cycle_label'] }}維護 {{ CustomerCollections::money($maintenance['fee_untaxed']) }}／{{ $maintenance['per_label'] }}
                    </x-filament::badge>
                    <span class="cc-muted cc-num" style="font-size: 0.75rem;">
                        含稅 {{ CustomerCollections::money($maintenance['fee_taxed']) }}，{{ CustomerCollections::dateLabel($maintenance['since']) }} 起{{ $maintenance['until'] === null ? '' : '，到 '.CustomerCollections::dateLabel($maintenance['until']) }}
                    </span>
                @endif
            </div>
            <div class="cc-figures cc-num">
                <span><span class="cc-muted">期間入帳</span> <span style="font-weight: 600;">{{ CustomerCollections::money($row['total']) }}</span> <span class="cc-muted">{{ $row['count'] }} 筆</span></span>
                <span><span class="cc-muted">最近入帳</span> {{ CustomerCollections::dateLabel($row['last_on']) }}</span>
            </div>
        </div>

        <svg class="cc-chart" viewBox="0 0 {{ $width }} {{ $bottom + 22 }}" role="img" aria-label="{{ $row['short_name'] }} 每月入帳">
            @for ($value = 0; $value <= $hi; $value += $step)
                <line class="cc-grid" x1="{{ $left }}" x2="{{ $width - $right }}" y1="{{ $y($value) }}" y2="{{ $y($value) }}" />
                <text class="cc-tick" x="{{ $left - 6 }}" y="{{ $y($value) + 4 }}" text-anchor="end">{{ RevenueOutlook::wan($value) }}</text>
            @endfor
            @foreach ($months as $index => $month)
                @php($amount = $row['by_month'][$month])
                @if ($amount > 0)
                    <rect class="cc-bar" x="{{ round($centerX($index) - $barWidth / 2, 1) }}" y="{{ $y($amount) }}" width="{{ $barWidth }}" height="{{ max(1, round($bottom - $y($amount), 1)) }}" rx="2">
                        <title>{{ CustomerCollections::monthLabel($month) }} 入帳 {{ CustomerCollections::money($amount) }}</title>
                    </rect>
                @endif
                @if ($index % $labelEvery === 0)
                    <text class="cc-tick" x="{{ $centerX($index) }}" y="{{ $bottom + 16 }}" text-anchor="middle">{{ CustomerCollections::monthLabel($month) }}</text>
                @endif
            @endforeach
            @if ($feeLine !== null)
                <line class="cc-feeline" x1="{{ $left }}" x2="{{ $width - $right }}" y1="{{ $y($feeLine) }}" y2="{{ $y($feeLine) }}" />
                <text class="cc-feelabel" x="{{ $width - $right }}" y="{{ $y($feeLine) - 4 }}" text-anchor="end">維護費 {{ RevenueOutlook::wan($feeLine) }}</text>
            @endif
        </svg>

        @if ($maintenance !== null)
            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                <div class="cc-periods">
                    @foreach ($periods as $period)
                        <div class="cc-period cc-period-{{ $period['status'] }} cc-num" wire:key="cc-period-{{ $row['id'] }}-{{ $period['key'] }}" title="{{ $period['label'] }}（{{ CustomerCollections::dateLabel($period['from']) }} – {{ CustomerCollections::dateLabel($period['to']) }}）{{ $period['status_label'] }}：收到 {{ CustomerCollections::money($period['received']) }}，應收 {{ CustomerCollections::money($period['expected_taxed']) }}（含稅）">
                            <span style="font-weight: 600;">{{ $period['label'] }}</span>
                            <span>{{ $period['received'] > 0 ? RevenueOutlook::wan($period['received']) : $period['status_label'] }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="cc-num" style="font-size: 0.75rem;">
                    <span class="cc-muted">已入帳 {{ $maintenance['paid'] }} 期</span>
                    @if ($missingLabels !== [])
                        <span class="cc-danger">，未入帳 {{ count($missingLabels) }} 期（{{ implode('、', $missingLabels) }}）</span>
                    @endif
                    @if ($shortLabels !== [])
                        <span class="cc-warn">，金額不足 {{ count($shortLabels) }} 期（{{ implode('、', $shortLabels) }}）</span>
                    @endif
                    @if ($maintenance['next_due'] !== null)
                        <span class="cc-muted">，下一期 {{ CustomerCollections::dateLabel($maintenance['next_due']) }} 起</span>
                    @elseif ($maintenance['is_ended'])
                        <span class="cc-muted">，合約已到期</span>
                    @endif
                </div>
            </div>
        @endif
    </div>
</x-filament::section>
