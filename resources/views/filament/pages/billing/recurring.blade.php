@php
    use App\Filament\Pages\BillingProgress;
    use App\Filament\Resources\Customers\CustomerResource;
@endphp

<x-filament::section compact>
    <x-slot name="heading">經常性收費</x-slot>
    <x-slot name="description">有維護合約的客戶，前兩期、本期與後兩期各有沒有應收列、開了票沒、收到沒。「沒有應收列」表示那一期還沒建應收，推估與對帳都會漏掉它。</x-slot>

    @if ($recurring === [])
        <span class="bp-muted" style="font-size: 0.875rem;">還沒有客戶設定維護合約。到客戶資料填維護費、週期與起算日。</span>
    @else
        <div style="display: flex; flex-direction: column; gap: 1rem;">
            @foreach ($recurring as $row)
                <div wire:key="bp-recurring-{{ $row['customer_id'] }}" style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <div class="bp-head">
                        <div style="display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.25rem 0.75rem;">
                            <a href="{{ CustomerResource::getUrl('edit', ['record' => $row['customer_id']]) }}" class="bp-link" style="font-weight: 600;">{{ $row['customer'] }}</a>
                            <span class="bp-muted bp-num" style="font-size: 0.8125rem;">{{ $row['cycle_label'] }}維護 {{ BillingProgress::money($row['fee_untaxed']) }}／{{ $row['per_label'] }}，{{ BillingProgress::dateLabel($row['since']) }} 起{{ $row['until'] === null ? '' : '，到 '.BillingProgress::dateLabel($row['until']) }}</span>
                        </div>
                        <div class="bp-figures bp-num">
                            @if ($row['missing'] > 0)
                                <span class="bp-danger">沒有應收列 {{ $row['missing'] }} 期</span>
                            @endif
                            @if ($row['planned'] > 0)
                                <span class="bp-warn">未開票 {{ $row['planned'] }} 期</span>
                            @endif
                            @if ($row['missing'] === 0 && $row['planned'] === 0)
                                <span class="bp-muted">每一期都有應收並已開票</span>
                            @endif
                        </div>
                    </div>
                    <div class="bp-periods">
                        @foreach ($row['periods'] as $period)
                            @php($receivable = $period['receivable'])
                            <div class="bp-period bp-period-{{ $period['status'] }} bp-num @if ($period['is_future']) bp-period-future @endif @if ($period['is_current']) bp-period-current @endif" wire:key="bp-period-{{ $row['customer_id'] }}-{{ $period['key'] }}" title="{{ $period['label'] }}（{{ BillingProgress::dateLabel($period['from']) }} – {{ BillingProgress::dateLabel($period['to']) }}）{{ $period['status_label'] }}{{ $receivable === null ? '' : '：'.$receivable['item'].' '.BillingProgress::money($receivable['untaxed']).'，預計 '.BillingProgress::dateLabel($receivable['expected_on']).($receivable['invoice_number'] ? '，發票 '.$receivable['invoice_number'] : '') }}">
                                <span style="font-weight: 600;">{{ $period['label'] }}</span>
                                <span>{{ $period['status_label'] }}</span>
                                @if ($receivable !== null && in_array($receivable['status'], ['planned', 'invoiced'], true))
                                    <span class="bp-actions" style="margin-top: 0.125rem;">
                                        @if ($receivable['status'] === 'planned')
                                            <button type="button" class="bp-btn" style="padding: 0 0.375rem; font-size: 0.6875rem;" wire:click="mountAction('markInvoiced', { receivable: {{ $receivable['id'] }} })">開票</button>
                                        @endif
                                        <button type="button" class="bp-btn bp-btn-primary" style="padding: 0 0.375rem; font-size: 0.6875rem;" wire:click="mountAction('markReceived', { receivable: {{ $receivable['id'] }} })">已收</button>
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-filament::section>
