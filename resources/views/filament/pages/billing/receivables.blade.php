@php
    use App\Enums\ReceivableStatus;
    use App\Filament\Pages\BillingProgress;
    use App\Filament\Resources\Receivables\ReceivableResource;
@endphp

<div style="overflow-x: auto;">
    <table class="bp-table">
        <thead>
            <tr>
                <th scope="col">項目</th>
                <th scope="col" class="bp-end">未稅</th>
                <th scope="col" class="bp-end">含稅</th>
                <th scope="col">預計收款日</th>
                <th scope="col">狀態</th>
                <th scope="col">開票</th>
                <th scope="col">收款日</th>
                <th scope="col"></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr wire:key="{{ $prefix }}-receivable-{{ $row['id'] }}">
                    <td class="bp-wrap">
                        <a href="{{ ReceivableResource::getUrl('edit', ['record' => $row['id']]) }}" class="bp-link" style="font-weight: 500;">{{ $row['item'] }}</a>
                        @if ($row['confidence'] === 'low')
                            <span class="bp-muted" style="font-size: 0.75rem;">（低確定性）</span>
                        @endif
                    </td>
                    <td class="bp-end bp-num">{{ BillingProgress::money($row['untaxed']) }}</td>
                    <td class="bp-end bp-num bp-muted">{{ BillingProgress::money($row['taxed']) }}</td>
                    <td class="bp-num">
                        <span @class(['bp-danger' => $row['is_overdue']])>{{ BillingProgress::dateLabel($row['expected_on']) }}</span>
                        @if ($row['is_overdue'])
                            <span class="bp-danger" style="font-size: 0.75rem;">逾期 {{ $row['days_overdue'] }} 天</span>
                        @endif
                    </td>
                    <td>
                        <span class="bp-badge"><x-filament::badge :color="ReceivableStatus::from($row['status'])->getColor()" size="sm">{{ $row['status_label'] }}</x-filament::badge></span>
                        @if ($row['is_due_uninvoiced'])
                            <span class="bp-badge"><x-filament::badge color="warning" size="sm">到期未開票</x-filament::badge></span>
                        @endif
                    </td>
                    <td class="bp-num">
                        @if ($row['invoiced_on'] !== null)
                            {{ BillingProgress::dateLabel($row['invoiced_on']) }}
                            <span @class(['bp-muted' => $row['invoice_number'] !== null, 'bp-warn' => $row['invoice_number'] === null]) style="font-size: 0.75rem;">{{ $row['invoice_number'] ?? '沒有發票號碼' }}</span>
                        @else
                            <span class="bp-muted">—</span>
                        @endif
                    </td>
                    <td class="bp-num">{{ BillingProgress::dateLabel($row['received_on']) }}</td>
                    <td>
                        <span class="bp-actions">
                            @if ($row['status'] === 'planned')
                                <button type="button" class="bp-btn" wire:click="mountAction('markInvoiced', { receivable: {{ $row['id'] }} })">標記已開票</button>
                            @endif
                            @if (in_array($row['status'], ['planned', 'invoiced'], true))
                                <button type="button" class="bp-btn bp-btn-primary" wire:click="mountAction('markReceived', { receivable: {{ $row['id'] }} })">標記已收</button>
                            @endif
                        </span>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
