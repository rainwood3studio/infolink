@php
    use App\Filament\Pages\RevenueOutlook;

    $months = $outlook['months'];
    $summary = $outlook['summary'];
    $count = count($months);
    $lastIndex = $count - 1;
    $showLow = $outlook['options']['include_low_confidence'];
    $hasPipeline = $summary['pipeline_weighted_total'] > 0;
    $hasAssumed = array_sum(array_column($months, 'recurring_assumed')) > 0;
    $hasPlannedInflow = array_sum(array_column($months, 'planned_inflow')) > 0;
    $hasPlannedOutflow = array_sum(array_column($months, 'planned_outflow')) > 0;
    $monthIndex = array_flip(array_column($months, 'month'));
    $assumedIndex = $monthIndex[$outlook['assumptions']['recurring_assumed_from']] ?? null;

    // Both charts share the same horizontal geometry so the months line up.
    $width = 960;
    $left = 56;
    $right = 76;
    $band = ($width - $left - $right) / $count;
    $barWidth = min(24, round($band * 0.45, 1));
    $centerX = fn (int $index): float => round($left + $band * ($index + 0.5), 1);
    $axisSpace = $assumedIndex === null ? 38 : 62;

    $inLayers = array_values(array_filter([
        ['key' => 'receivables', 'label' => '專案應收', 'fill' => 'var(--ro-receivables)'],
        ['key' => 'recurring', 'label' => '經常性收入', 'fill' => 'var(--ro-recurring)'],
        $hasAssumed ? ['key' => 'recurring_assumed', 'label' => '假設續約的經常性收入', 'fill' => 'url(#ro-hatch)'] : null,
        $hasPlannedInflow ? ['key' => 'planned_inflow', 'label' => '已排定流入', 'fill' => 'var(--ro-planned)'] : null,
        $showLow ? ['key' => 'low_confidence', 'label' => '低確定性應收', 'fill' => 'var(--ro-low)'] : null,
        $hasPipeline ? ['key' => 'pipeline_weighted', 'label' => '加權業務機會（未稅）', 'fill' => null] : null,
    ]));
    $outLayers = array_values(array_filter([
        ['key' => 'cost', 'label' => '月成本', 'fill' => 'var(--ro-cost)'],
        $hasPlannedOutflow ? ['key' => 'planned_outflow', 'label' => '已排定支出', 'fill' => 'var(--ro-planned)'] : null,
    ]));

    $inMax = max(array_map(fn (array $month): int => array_sum(array_map(fn (array $layer): int => $month[$layer['key']], $inLayers)), $months));
    $outMax = max(array_map(fn (array $month): int => $month['cost'] + $month['planned_outflow'], $months));
    $flowStep = RevenueOutlook::niceStep($inMax + $outMax, 6);
    $flowHi = max(1, ceil($inMax / $flowStep)) * $flowStep;
    $flowLo = -ceil($outMax / $flowStep) * $flowStep;
    $flowTop = 12;
    $flowPlot = 210;
    $flowBottom = $flowTop + $flowPlot;
    $flowY = fn (int|float $value): float => round($flowTop + ($flowHi - $value) / ($flowHi - $flowLo) * $flowPlot, 1);

    $balanceLines = array_values(array_filter([
        ['key' => 'balance_confirmed', 'label' => '已確定', 'stroke' => 'currentColor', 'dash' => '0.1 6', 'opacity' => 0.6, 'width' => 2.5],
        ['key' => 'balance_base', 'label' => '基準', 'stroke' => 'currentColor', 'dash' => null, 'opacity' => 1, 'width' => 2.5],
        $hasPipeline ? ['key' => 'balance_with_pipeline', 'label' => '含機會', 'stroke' => 'var(--ro-pipeline)', 'dash' => '7 5', 'opacity' => 1, 'width' => 2] : null,
    ]));
    $balances = array_merge(...array_map(fn (array $line): array => array_column($months, $line['key']), $balanceLines));
    $balStep = RevenueOutlook::niceStep(max(1, ...$balances) - min(0, ...$balances));
    $balHi = max(1, ceil(max(1, ...$balances) / $balStep)) * $balStep;
    $balLo = floor(min(0, ...$balances) / $balStep) * $balStep;
    $balTop = 28;
    $balPlot = 220;
    $balBottom = $balTop + $balPlot;
    $balY = fn (int|float $value): float => round($balTop + ($balHi - $value) / ($balHi - $balLo) * $balPlot, 1);
    $polyline = fn (string $key): string => implode(' ', array_map(
        fn (array $month, int $index): string => $centerX($index).','.$balY($month[$key]),
        $months,
        array_keys($months),
    ));

    $peakIndex = $monthIndex[$summary['peak']['month']];
    $zero = $summary['zero_month'];
    $zeroIndex = $zero !== null && ! $zero['extrapolated'] ? $monthIndex[$zero['month']] : null;

    // End labels: the base line always, the others only when they end far enough from the ones already placed.
    $endLabels = [];
    foreach (['balance_base', 'balance_confirmed', 'balance_with_pipeline'] as $key) {
        $line = collect($balanceLines)->firstWhere('key', $key);
        if ($line === null) {
            continue;
        }
        $y = $balY($months[$lastIndex][$key]);
        if (collect($endLabels)->every(fn (array $placed): bool => abs($placed['y'] - $y) >= 15)) {
            $endLabels[] = ['y' => $y, 'text' => $line['label'].' '.RevenueOutlook::wan($months[$lastIndex][$key])];
        }
    }

    $itemLines = fn (array $items): array => array_map(
        fn (array $item): string => '　・'.$item['label'].' '.RevenueOutlook::money($item['amount']),
        $items,
    );
    $flowTitle = function (array $month) use ($showLow, $itemLines): string {
        $lines = [RevenueOutlook::monthLabel($month['month'])];
        $add = function (string $label, int $amount, array $items = [], string $note = '') use (&$lines, $itemLines): void {
            if ($amount !== 0) {
                $lines = [...$lines, $label.' '.RevenueOutlook::money($amount).$note, ...$itemLines($items)];
            }
        };
        $add('專案應收', $month['receivables'], $month['items']['receivables']);
        $add('經常性收入', $month['recurring'], $month['items']['recurring']);
        $add('假設續約的經常性收入', $month['recurring_assumed'], note: '（假設，不是已確定的收入）');
        $add('已排定流入', $month['planned_inflow']);
        $add('低確定性應收', $month['low_confidence'], $month['items']['low_confidence'], $showLow ? '' : '（未計入）');
        $add('加權業務機會', $month['pipeline_weighted'], $month['items']['pipeline'], '（未稅，不算進月淨額）');
        $lines[] = '月成本 '.RevenueOutlook::money(-$month['cost']);
        $add('已排定支出', -$month['planned_outflow'], array_filter($month['items']['planned'], fn (array $item): bool => $item['amount'] < 0));
        $lines[] = '月淨額 '.RevenueOutlook::money($month['net_base'], signed: true);

        return implode("\n", $lines);
    };
    $balanceTitle = fn (array $month): string => implode("\n", array_filter([
        RevenueOutlook::monthLabel($month['month']).'底餘額',
        '基準 '.RevenueOutlook::money($month['balance_base']),
        '已確定 '.RevenueOutlook::money($month['balance_confirmed']),
        $hasPipeline ? '含業務機會 '.RevenueOutlook::money($month['balance_with_pipeline']) : null,
    ]));
@endphp

<x-filament::section compact>
    <x-slot name="heading">每月收入與支出</x-slot>
    <x-slot name="description">零線以上是收入，以下是支出，橫線是當月淨額。斜線的部分是假設維運合約續約，還不是已確定的收入。</x-slot>

    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
        <div class="ro-legend">
            @foreach ($inLayers as $layer)
                <span>
                    <span @class(['ro-swatch', 'ro-swatch-assumed' => $layer['key'] === 'recurring_assumed', 'ro-swatch-pipeline' => $layer['key'] === 'pipeline_weighted']) @style(['background: '.$layer['fill'] => ! in_array($layer['key'], ['recurring_assumed', 'pipeline_weighted'], true)])></span>
                    {{ $layer['label'] }}
                </span>
            @endforeach
            @foreach ($outLayers as $layer)
                <span><span class="ro-swatch" style="background: {{ $layer['fill'] }};"></span>{{ $layer['label'] }}</span>
            @endforeach
            <span><span class="ro-swatch" style="width: 1rem; height: 0.1875rem; border-radius: 9999px; background: currentColor;"></span>月淨額</span>
        </div>

        <div style="overflow-x: auto;">
            <svg class="ro-chart" viewBox="0 0 {{ $width }} {{ $flowBottom + $axisSpace }}" role="img" aria-label="未來 {{ $count }} 個月每月的收入、支出與淨額">
                <defs>
                    <pattern id="ro-hatch" patternUnits="userSpaceOnUse" width="6" height="6" patternTransform="rotate(45)">
                        <rect width="6" height="6" fill="var(--ro-recurring)" opacity="0.22" />
                        <rect width="2.5" height="6" fill="var(--ro-recurring)" />
                    </pattern>
                </defs>

                @for ($value = $flowLo; $value <= $flowHi + 1; $value += $flowStep)
                    @if (abs($value) >= 1)
                        <line x1="{{ $left }}" y1="{{ $flowY($value) }}" x2="{{ $width - $right }}" y2="{{ $flowY($value) }}" stroke="var(--ro-rule)" stroke-width="1" />
                    @endif
                    <text class="ro-tick" x="{{ $left - 8 }}" y="{{ $flowY($value) + 4 }}" text-anchor="end">{{ RevenueOutlook::wan($value) }}</text>
                @endfor

                @foreach ($months as $index => $month)
                    @php
                        $x = round($centerX($index) - $barWidth / 2, 1);
                        $stacked = 0;
                        $spent = 0;
                    @endphp
                    @foreach ($inLayers as $layer)
                        @continue($month[$layer['key']] <= 0)
                        @php
                            $top = $flowY($stacked + $month[$layer['key']]);
                            $height = max(1, round($flowY($stacked) - $top - ($stacked > 0 ? 2 : 0), 1));
                            $stacked += $month[$layer['key']];
                        @endphp
                        @if ($layer['key'] === 'pipeline_weighted')
                            <rect x="{{ $x + 0.75 }}" y="{{ $top + 0.75 }}" width="{{ $barWidth - 1.5 }}" height="{{ max(1, $height - 1.5) }}" rx="2" fill="var(--ro-pipeline)" fill-opacity="0.14" stroke="var(--ro-pipeline)" stroke-width="1.5" stroke-dasharray="4 3" />
                        @else
                            <rect x="{{ $x }}" y="{{ $top }}" width="{{ $barWidth }}" height="{{ $height }}" rx="2" fill="{{ $layer['fill'] }}" />
                        @endif
                    @endforeach
                    @foreach ($outLayers as $layer)
                        @continue($month[$layer['key']] <= 0)
                        @php
                            $top = round($flowY(-$spent) + ($spent > 0 ? 2 : 0), 1);
                            $height = max(1, round($flowY(-$spent - $month[$layer['key']]) - $top, 1));
                            $spent += $month[$layer['key']];
                        @endphp
                        <rect x="{{ $x }}" y="{{ $top }}" width="{{ $barWidth }}" height="{{ $height }}" rx="2" fill="{{ $layer['fill'] }}" />
                    @endforeach
                @endforeach

                <line x1="{{ $left }}" y1="{{ $flowY(0) }}" x2="{{ $width - $right }}" y2="{{ $flowY(0) }}" stroke="var(--ro-zero)" stroke-width="1.5" />

                @foreach ($months as $index => $month)
                    <rect x="{{ round($centerX($index) - $barWidth / 2 - 5, 1) }}" y="{{ $flowY($month['net_base']) - 2 }}" width="{{ $barWidth + 10 }}" height="4" rx="2" fill="currentColor" stroke="var(--ro-surface)" stroke-width="1.5" />
                @endforeach

                @include('filament.pages.revenue-outlook.axis', ['bottom' => $flowBottom])

                @foreach ($months as $index => $month)
                    <rect class="ro-hit" x="{{ round($left + $band * $index, 1) }}" y="{{ $flowTop - 8 }}" width="{{ round($band, 1) }}" height="{{ $flowPlot + 40 }}" rx="4"><title>{{ $flowTitle($month) }}</title></rect>
                @endforeach
            </svg>
        </div>
    </div>
</x-filament::section>

<x-filament::section compact>
    <x-slot name="heading">月底現金餘額</x-slot>
    <x-slot name="description">「已確定」和「基準」分開的地方，就是經常性收入開始靠假設的月份。</x-slot>

    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
        <div class="ro-legend">
            @foreach ([
                'balance_base' => '基準：已確定＋假設維運合約續約'.($showLow ? '＋低確定性應收' : ''),
                'balance_confirmed' => '已確定：只算已登記的高確定性應收與經常性收入',
                'balance_with_pipeline' => '含業務機會：基準＋加權業務機會（未稅）',
            ] as $key => $label)
                @php $line = collect($balanceLines)->firstWhere('key', $key); @endphp
                @continue($line === null)
                <span>
                    <svg width="26" height="8" viewBox="0 0 26 8" aria-hidden="true" style="flex: none;">
                        <line x1="1" y1="4" x2="25" y2="4" stroke="{{ $line['stroke'] }}" stroke-width="{{ $line['width'] }}" stroke-linecap="round" opacity="{{ $line['opacity'] }}" @if ($line['dash']) stroke-dasharray="{{ $line['dash'] }}" @endif />
                    </svg>
                    {{ $label }}
                </span>
            @endforeach
            @unless ($hasPipeline)
                <span class="ro-muted">含業務機會：目前沒有能計入的機會，和基準重疊</span>
            @endunless
        </div>

        <div style="overflow-x: auto;">
            <svg class="ro-chart" viewBox="0 0 {{ $width }} {{ $balBottom + $axisSpace }}" role="img" aria-label="未來 {{ $count }} 個月的月底現金餘額，高點 {{ RevenueOutlook::monthLabel($summary['peak']['month']) }} {{ RevenueOutlook::money($summary['peak']['balance']) }}">
                @if ($balLo < 0)
                    <rect x="{{ $left }}" y="{{ $balY(0) }}" width="{{ $width - $left - $right }}" height="{{ $balBottom - $balY(0) }}" fill="var(--ro-danger)" opacity="0.08" />
                @endif

                @for ($value = $balLo; $value <= $balHi + 1; $value += $balStep)
                    @if (abs($value) >= 1)
                        <line x1="{{ $left }}" y1="{{ $balY($value) }}" x2="{{ $width - $right }}" y2="{{ $balY($value) }}" stroke="var(--ro-rule)" stroke-width="1" />
                    @endif
                    <text class="ro-tick" x="{{ $left - 8 }}" y="{{ $balY($value) + 4 }}" text-anchor="end">{{ RevenueOutlook::wan($value) }}</text>
                @endfor

                <line x1="{{ $left }}" y1="{{ $balY(0) }}" x2="{{ $width - $right }}" y2="{{ $balY(0) }}" stroke="{{ $balLo < 0 ? 'var(--ro-danger)' : 'var(--ro-zero)' }}" stroke-width="1.5" />

                @foreach ($balanceLines as $line)
                    <polyline points="{{ $polyline($line['key']) }}" fill="none" stroke="{{ $line['stroke'] }}" stroke-width="{{ $line['width'] }}" stroke-linejoin="round" stroke-linecap="round" opacity="{{ $line['opacity'] }}" @if ($line['dash']) stroke-dasharray="{{ $line['dash'] }}" @endif />
                @endforeach

                <circle cx="{{ $centerX($peakIndex) }}" cy="{{ $balY($summary['peak']['balance']) }}" r="4.5" fill="currentColor" stroke="var(--ro-surface)" stroke-width="2" />
                <text class="ro-label" x="{{ min($width - $right - 30, max($left + 34, $centerX($peakIndex))) }}" y="{{ $balY($summary['peak']['balance']) - 11 }}" text-anchor="middle">高點 {{ RevenueOutlook::wan($summary['peak']['balance']) }}</text>

                @if ($zeroIndex !== null)
                    <circle cx="{{ $centerX($zeroIndex) }}" cy="{{ $balY($months[$zeroIndex]['balance_base']) }}" r="4.5" fill="var(--ro-danger)" stroke="var(--ro-surface)" stroke-width="2" />
                    <text class="ro-label" x="{{ $centerX($zeroIndex) - 9 }}" y="{{ $balY($months[$zeroIndex]['balance_base']) - 9 }}" text-anchor="end">跌破 0</text>
                @endif

                @foreach ($endLabels as $label)
                    <text class="ro-label" x="{{ $centerX($lastIndex) + 9 }}" y="{{ $label['y'] + 4 }}">{{ $label['text'] }}</text>
                @endforeach

                @include('filament.pages.revenue-outlook.axis', ['bottom' => $balBottom])

                @foreach ($months as $index => $month)
                    <rect class="ro-hit" x="{{ round($left + $band * $index, 1) }}" y="{{ $balTop - 8 }}" width="{{ round($band, 1) }}" height="{{ $balPlot + 40 }}" rx="4"><title>{{ $balanceTitle($month) }}</title></rect>
                @endforeach
            </svg>
        </div>
    </div>
</x-filament::section>
