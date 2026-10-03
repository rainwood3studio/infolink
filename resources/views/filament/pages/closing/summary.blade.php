@php
    use App\Filament\Support\Money;

    $tiles = [
        [
            'label' => '掛著的未收應收（含稅）',
            'value' => Money::format($summary['outstanding_taxed']),
            'note' => $summary['outstanding_overdue_taxed'] > 0 ? '其中 '.Money::format($summary['outstanding_overdue_taxed']).' 已逾期' : '沒有逾期的款項',
            'bad' => $summary['outstanding_overdue_taxed'] > 0,
        ],
        [
            'label' => '未結議題',
            'value' => number_format($summary['open']).' 張',
            'note' => "{$summary['projects']} 個結案中專案",
            'bad' => false,
        ],
        [
            'label' => '已過目標日',
            'value' => "{$summary['overdue']} 個專案",
            'note' => $summary['overdue'] > 0 ? '目標結案日已過，還沒結案' : '沒有專案超過目標日',
            'bad' => $summary['overdue'] > 0,
        ],
        [
            'label' => '照現在速度趕不上',
            'value' => ($summary['projected_late'] + $summary['not_converging']).' 個專案',
            'note' => $summary['projected_late'] + $summary['not_converging'] > 0
                ? "推估會晚 {$summary['projected_late']} 個、未結數沒在減少 {$summary['not_converging']} 個（不含已過目標日）"
                : '其餘專案照近 7 天速度趕得上，或還無法推估',
            'bad' => $summary['projected_late'] + $summary['not_converging'] > 0,
        ],
    ];
@endphp

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 1rem;">
    @foreach ($tiles as $tile)
        <x-filament::section compact>
            <div class="cp-muted" style="font-size: 0.8125rem;">{{ $tile['label'] }}</div>
            <div class="cp-num" style="margin-top: 0.125rem; font-size: 1.5rem; font-weight: 600; line-height: 2rem;">{{ $tile['value'] }}</div>
            <div @class(['cp-danger' => $tile['bad'], 'cp-muted' => ! $tile['bad']]) style="margin-top: 0.125rem; font-size: 0.75rem;">{{ $tile['note'] }}</div>
        </x-filament::section>
    @endforeach
</div>
