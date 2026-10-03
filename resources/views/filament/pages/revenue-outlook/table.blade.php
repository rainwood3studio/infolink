@php
    use App\Filament\Pages\RevenueOutlook;

    $months = $outlook['months'];
    $showLow = $outlook['options']['include_low_confidence'];
    $hasLow = $outlook['assumptions']['low_confidence_taxed'] > 0;
    $hasPipeline = $outlook['summary']['pipeline_weighted_total'] > 0;
    $hasPlanned = array_sum(array_column($months, 'planned_inflow')) + array_sum(array_column($months, 'planned_outflow')) > 0;
    $assumedFrom = $outlook['assumptions']['recurring_assumed_from'];

    $number = fn (int $amount): string => ($amount < 0 ? '−' : '').number_format(abs($amount));
    $cell = fn (int $amount): string => $amount === 0 ? '—' : $number($amount);
    $itemsTitle = fn (array $items): string => implode("\n", array_map(
        fn (array $item): string => $item['label'].' '.RevenueOutlook::money($item['amount']),
        $items,
    ));
@endphp

<x-filament::section compact>
    <x-slot name="heading">逐月明細</x-slot>
    <x-slot name="description">金額是新台幣，應收含稅。月淨額與月底餘額用基準情境算{{ $showLow ? '（已計入低確定性應收）' : '' }}。</x-slot>

    <div style="overflow-x: auto;">
        <table class="ro-table ro-num">
            <thead>
                <tr>
                    <th scope="col">月份</th>
                    <th scope="col">專案應收</th>
                    <th scope="col">經常性收入</th>
                    <th scope="col">假設續約</th>
                    @if ($hasLow)
                        <th scope="col">低確定性{{ $showLow ? '' : '（未計入）' }}</th>
                    @endif
                    <th scope="col">月成本</th>
                    @if ($hasPlanned)
                        <th scope="col">已排定收支</th>
                    @endif
                    <th scope="col">月淨額</th>
                    <th scope="col">月底餘額（基準）</th>
                    <th scope="col">月底餘額（已確定）</th>
                    @if ($hasPipeline)
                        <th scope="col">加權業務機會（未稅）</th>
                        <th scope="col">月底餘額（含業務機會）</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($months as $month)
                    @php $planned = $month['planned_inflow'] - $month['planned_outflow']; @endphp
                    <tr wire:key="month-{{ $month['month'] }}">
                        <td>
                            {{ RevenueOutlook::monthLabel($month['month']) }}
                            @if ($month['month'] === $assumedFrom)
                                <span class="ro-muted" style="font-size: 0.75rem;">起為假設</span>
                            @endif
                        </td>
                        <td @class(['ro-zero-cell' => $month['receivables'] === 0]) title="{{ $itemsTitle($month['items']['receivables']) }}">{{ $cell($month['receivables']) }}</td>
                        <td @class(['ro-zero-cell' => $month['recurring'] === 0]) title="{{ $itemsTitle($month['items']['recurring']) }}">{{ $cell($month['recurring']) }}</td>
                        <td @class(['ro-zero-cell' => $month['recurring_assumed'] === 0])>{{ $cell($month['recurring_assumed']) }}</td>
                        @if ($hasLow)
                            <td @class(['ro-zero-cell' => $month['low_confidence'] === 0, 'ro-muted' => ! $showLow && $month['low_confidence'] !== 0]) title="{{ $itemsTitle($month['items']['low_confidence']) }}">{{ $cell($month['low_confidence']) }}</td>
                        @endif
                        <td @class(['ro-zero-cell' => $month['cost'] === 0])>{{ $cell(-$month['cost']) }}</td>
                        @if ($hasPlanned)
                            <td @class(['ro-zero-cell' => $planned === 0]) title="{{ $itemsTitle($month['items']['planned']) }}">{{ $cell($planned) }}</td>
                        @endif
                        <td @class(['ro-danger' => $month['net_base'] < 0]) style="font-weight: 600;">{{ $month['net_base'] > 0 ? '+' : '' }}{{ $number($month['net_base']) }}</td>
                        <td @class(['ro-danger' => $month['balance_base'] < 0]) style="font-weight: 600;">{{ $number($month['balance_base']) }}</td>
                        <td @class(['ro-danger' => $month['balance_confirmed'] < 0])>{{ $number($month['balance_confirmed']) }}</td>
                        @if ($hasPipeline)
                            <td @class(['ro-zero-cell' => $month['pipeline_weighted'] === 0]) title="{{ $itemsTitle($month['items']['pipeline']) }}">{{ $cell($month['pipeline_weighted']) }}</td>
                            <td @class(['ro-danger' => $month['balance_with_pipeline'] < 0])>{{ $number($month['balance_with_pipeline']) }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="ro-muted" style="display: flex; flex-direction: column; gap: 0.125rem; margin-top: 0.75rem; font-size: 0.75rem;">
        <span>第一個月的月成本只扣還沒支出的部分；已逾期的應收算在第一個月。滑到金額上可以看是哪幾筆。</span>
        @if ($hasPipeline)
            <span>加權業務機會＝金額 × 成交機率，未稅，而且成交不等於入帳，所以不算進月淨額，只另外列一條「含業務機會」的餘額。</span>
        @endif
    </div>
</x-filament::section>
