@php
    use App\Filament\Pages\ProjectPnl;

    $top = collect($pnl['customers'])->first(fn (array $customer): bool => $customer['person_days'] > 0);
    $hasPresales = $pnl['presales'] !== [];
    $hasUnmapped = $pnl['unmapped'] !== [];

    $tiles = [
        [
            'label' => '總投入人天',
            'value' => ProjectPnl::days($pnl['total_person_days']).' 人天',
            'hint' => number_format($pnl['total_commits']).' 筆 commit（不含 merge）。'
                .($pnl['period_cost'] === null ? '還沒設定月成本，無法估算成本' : '這段期間的成本估 '.ProjectPnl::money($pnl['period_cost'])),
            'tone' => null,
        ],
        [
            'label' => '最大客戶佔投入',
            'value' => $top === null ? '—' : $top['name'].' '.ProjectPnl::percent($top['effort_share']),
            'hint' => match (true) {
                $top === null => '這段期間的投入都還沒對應到客戶的專案',
                $top['received_share'] === null => '還沒有已收款紀錄可以對照',
                default => '同一客戶佔歷來已收款 '.ProjectPnl::percent($top['received_share']),
            },
            'tone' => null,
        ],
        [
            'label' => '尚未簽約的投入',
            'value' => $hasPresales ? ProjectPnl::days($pnl['presales_person_days']).' 人天' : '沒有',
            'hint' => $hasPresales
                ? '佔投入 '.ProjectPnl::percent($pnl['presales_share'])
                    .($pnl['presales_estimated_cost'] === null ? '' : '，估算成本 '.ProjectPnl::money($pnl['presales_estimated_cost']))
                : '沒有 repo 指定給業務機會，或這段期間沒有動到',
            'tone' => null,
        ],
        [
            'label' => '沒對應到專案的 commit',
            'value' => ProjectPnl::percent($pnl['unmapped_commit_share']),
            'hint' => $hasUnmapped
                ? count($pnl['unmapped']).' 個 repo、'.ProjectPnl::days($pnl['unmapped_person_days']).' 人天不知道算誰的，清單在最下面'
                : '每一筆 commit 都對應到專案或業務機會',
            'tone' => $hasUnmapped ? 'warn' : null,
        ],
    ];
@endphp

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: 1rem;">
    @foreach ($tiles as $tile)
        <x-filament::section compact>
            <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                <div class="pp-muted" style="font-size: 0.8125rem;">{{ $tile['label'] }}</div>
                <div class="pp-num" style="font-size: 1.25rem; font-weight: 600; line-height: 1.75rem; overflow-wrap: anywhere;">{{ $tile['value'] }}</div>
                <div @class(['pp-warn' => $tile['tone'] === 'warn', 'pp-muted' => $tile['tone'] === null]) style="font-size: 0.75rem;">{{ $tile['hint'] }}</div>
            </div>
        </x-filament::section>
    @endforeach
</div>
