@php
    use App\Filament\Pages\ProjectPnl;

    $rows = array_map(fn (array $customer): array => [
        'key' => 'customer-'.$customer['id'],
        'name' => $customer['name'],
        'note' => $customer['outstanding_taxed'] > 0 ? '未收 '.ProjectPnl::money($customer['outstanding_taxed']).'（含稅）' : null,
        'person_days' => $customer['person_days'],
        'effort_share' => $customer['effort_share'],
        'has_revenue' => true,
        'received_total' => $customer['received_total'],
        'received_share' => $customer['received_share'],
    ], $pnl['customers']);

    if ($pnl['presales'] !== []) {
        $rows[] = ['key' => 'presales', 'name' => '尚未簽約', 'note' => '還沒有合約的業務機會', 'person_days' => $pnl['presales_person_days'], 'effort_share' => $pnl['presales_share'], 'has_revenue' => false];
    }

    if ($pnl['unmapped'] !== []) {
        $rows[] = ['key' => 'unmapped', 'name' => '沒對應的 repo', 'note' => '還不知道算哪個客戶', 'person_days' => $pnl['unmapped_person_days'], 'effort_share' => $pnl['unmapped_share'], 'has_revenue' => false];
    }

    $width = fn (?float $share): string => round(($share ?? 0) * 100, 2).'%';
@endphp

<x-filament::section compact>
    <x-slot name="heading">依客戶</x-slot>
    <x-slot name="description">每個客戶兩條：這段期間吃掉多少投入，對上歷來收了多少錢。兩條差很多，就是投入和收入不成比例。</x-slot>

    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
        <div class="pp-legend">
            <span><span class="pp-swatch" style="background: var(--pp-effort);"></span>投入：這段期間人天的佔比</span>
            <span><span class="pp-swatch" style="background: var(--pp-revenue);"></span>收入：歷來已收款（未稅）的佔比</span>
        </div>

        <div>
            @foreach ($rows as $row)
                <div class="pp-customer" wire:key="pp-{{ $row['key'] }}">
                    <div style="min-width: 0;">
                        <div style="font-size: 0.875rem; font-weight: 600; overflow-wrap: anywhere;">{{ $row['name'] }}</div>
                        @if ($row['note'])
                            <div class="pp-sub pp-num">{{ $row['note'] }}</div>
                        @endif
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.25rem; min-width: 0;">
                        <div class="pp-barline" title="{{ $row['name'] }} 投入 {{ ProjectPnl::days($row['person_days']) }} 人天，佔 {{ ProjectPnl::percent($row['effort_share']) }}">
                            <div class="pp-track">@if ($row['effort_share'] > 0)<div class="pp-fill pp-fill-effort" style="width: {{ $width($row['effort_share']) }};"></div>@endif</div>
                            <span class="pp-value pp-num"><span style="font-weight: 600;">{{ ProjectPnl::percent($row['effort_share']) }}</span> <span class="pp-muted">投入 {{ ProjectPnl::days($row['person_days']) }} 人天</span></span>
                        </div>
                        @if ($row['has_revenue'])
                            <div class="pp-barline" title="{{ $row['name'] }} 歷來已收 {{ ProjectPnl::money($row['received_total']) }}（未稅），佔 {{ ProjectPnl::percent($row['received_share']) }}">
                                <div class="pp-track">@if ($row['received_share'] > 0)<div class="pp-fill pp-fill-revenue" style="width: {{ $width($row['received_share']) }};"></div>@endif</div>
                                <span class="pp-value pp-num"><span style="font-weight: 600;">{{ ProjectPnl::percent($row['received_share']) }}</span> <span class="pp-muted">已收 {{ ProjectPnl::money($row['received_total']) }}</span></span>
                            </div>
                        @else
                            <div class="pp-barline">
                                <div class="pp-track" style="background: transparent;"></div>
                                <span class="pp-value pp-muted">沒有收入</span>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        @if ($pnl['received_total'] === 0)
            <div class="pp-muted" style="font-size: 0.75rem;">應收帳款裡還沒有任何「已收款」的紀錄，所以收入那一條都是空的。</div>
        @endif
    </div>
</x-filament::section>
