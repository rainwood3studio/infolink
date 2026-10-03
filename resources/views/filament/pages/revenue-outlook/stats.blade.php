@php
    use App\Filament\Pages\RevenueOutlook;

    $summary = $outlook['summary'];
    $burning = $summary['monthly_gap'] > 0;
    $zero = $summary['zero_month'];
    $zeroConfirmed = $summary['zero_month_confirmed'];
    $horizonEnd = RevenueOutlook::monthLabel($summary['horizon_end']);

    $zeroNote = fn (?array $zero): string => $zero === null
        ? '不會歸零'
        : RevenueOutlook::monthLabel($zero['month']).($zero['extrapolated'] ? '（推估）' : '');

    $tiles = [
        [
            'label' => '每月缺口',
            'value' => $burning ? RevenueOutlook::money($summary['monthly_gap']) : '沒有缺口',
            'hint' => '月成本 '.RevenueOutlook::money($summary['monthly_cost']).' − 經常性收入 '.RevenueOutlook::money($summary['recurring_monthly'])
                .($burning ? '' : '，經常性收入已經蓋過月成本'),
            'bad' => $burning,
        ],
        [
            'label' => '一年要補的新生意',
            'value' => $burning ? RevenueOutlook::money($summary['annual_gap']) : '不需要',
            'hint' => $burning
                ? '每月缺口 × 12，補到這個數才打平。目前加權業務機會 '.RevenueOutlook::money($summary['pipeline_weighted_total']).'（未稅）'
                : '經常性收入每年多出 '.RevenueOutlook::money(abs($summary['annual_gap'])),
            'bad' => false,
        ],
        [
            'label' => '現金高點',
            'value' => RevenueOutlook::money($summary['peak']['balance']),
            'hint' => RevenueOutlook::monthLabel($summary['peak']['month']).'底',
            'bad' => false,
        ],
        [
            'label' => '現金開始往下掉',
            'value' => $summary['first_declining_month'] === null ? '—' : RevenueOutlook::monthLabel($summary['first_declining_month']),
            'hint' => $summary['first_declining_month'] === null
                ? "到{$horizonEnd}為止，沒有一路為負的月淨額"
                : "從這個月起到{$horizonEnd}，每個月的淨額都是負的",
            'bad' => false,
        ],
        [
            'label' => '照這樣現金歸零',
            'value' => $zero === null ? '不會歸零' : RevenueOutlook::monthLabel($zero['month']),
            'hint' => ($zero !== null && $zero['extrapolated'] ? "超出展望期，用每月缺口往後推 {$zero['months_of_runway_after_horizon']} 個月。" : '')
                .'只算已確定的：'.$zeroNote($zeroConfirmed),
            'bad' => $zero !== null && ! $zero['extrapolated'],
        ],
    ];
@endphp

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(10.75rem, 1fr)); gap: 1rem;">
    @foreach ($tiles as $tile)
        <x-filament::section compact>
            <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                <div class="ro-muted" style="font-size: 0.8125rem;">{{ $tile['label'] }}</div>
                <div @class(['ro-danger' => $tile['bad']]) style="font-size: 1.25rem; font-weight: 600; line-height: 1.75rem; white-space: nowrap;">{{ $tile['value'] }}</div>
                <div class="ro-muted" style="font-size: 0.75rem;">{{ $tile['hint'] }}</div>
            </div>
        </x-filament::section>
    @endforeach
</div>
