@php
    use App\Filament\Pages\BillingProgress;

    $problems = $summary['overdue']['count'] + $summary['due_uninvoiced']['count'];
    $missingContract = $summary['missing_contract'];
    $overScheduled = $summary['over_scheduled'];

    $tiles = [
        [
            'label' => '合約總額（未稅）',
            'value' => BillingProgress::money($summary['contract_total']),
            'hint' => match (true) {
                $summary['projects'] === 0 => '還沒有專案',
                $missingContract !== [] => count($missingContract).' 個專案沒填合約金額：'.implode('、', $missingContract),
                default => $summary['projects'].' 個專案都有合約金額',
            },
            'tone' => $missingContract !== [] ? 'warn' : null,
        ],
        [
            'label' => '已收',
            'value' => BillingProgress::money($summary['received']),
            'hint' => $summary['contract_total'] > 0 ? '佔合約總額 '.BillingProgress::percent($summary['received'] / $summary['contract_total']) : '沒有合約金額可以對照',
            'tone' => null,
        ],
        [
            'label' => '已開票未收',
            'value' => BillingProgress::money($summary['invoiced_outstanding']),
            'hint' => $summary['overdue']['count'] > 0
                ? '逾期 '.$summary['overdue']['count'].' 筆 '.BillingProgress::money($summary['overdue']['amount']).'（含未開票的）'
                : '沒有逾期的應收',
            'tone' => $summary['overdue']['count'] > 0 ? 'danger' : null,
        ],
        [
            'label' => '尚未排入應收',
            'value' => BillingProgress::money($summary['unscheduled']),
            'hint' => match (true) {
                $overScheduled !== [] => '超過合約金額：'.implode('、', $overScheduled),
                $summary['due_uninvoiced']['count'] > 0 => '另有 '.$summary['due_uninvoiced']['count'].' 筆 7 天內到期但還沒開發票：'.BillingProgress::money($summary['due_uninvoiced']['amount']),
                default => '合約金額扣掉已排進應收的部分；已排未開票 '.BillingProgress::money($summary['planned']),
            },
            'tone' => $overScheduled !== [] ? 'danger' : ($summary['due_uninvoiced']['count'] > 0 ? 'warn' : null),
        ],
    ];
@endphp

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: 1rem;">
    @foreach ($tiles as $tile)
        <x-filament::section compact>
            <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                <div class="bp-muted" style="font-size: 0.8125rem;">{{ $tile['label'] }}</div>
                <div class="bp-num" style="font-size: 1.25rem; font-weight: 600; line-height: 1.75rem; overflow-wrap: anywhere;">{{ $tile['value'] }}</div>
                <div @class(['bp-danger' => $tile['tone'] === 'danger', 'bp-warn' => $tile['tone'] === 'warn', 'bp-muted' => $tile['tone'] === null]) style="font-size: 0.75rem;">{{ $tile['hint'] }}</div>
            </div>
        </x-filament::section>
    @endforeach
</div>
