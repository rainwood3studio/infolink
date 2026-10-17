@php
    use App\Filament\Pages\BillingProgress;
    use App\Filament\Resources\BankTransactions\BankTransactionResource;
    use App\Filament\Resources\Customers\CustomerResource;

    $rows = $deposits['deposits'];
    $unmatched = $deposits['unmatched_counterparties'];
    $summary = $deposits['summary'];
@endphp

<x-filament::section compact>
    <x-slot name="heading">待沖銷入帳</x-slot>
    <x-slot name="description">銀行明細裡類別為「收入」、還沒對到應收的入帳（{{ BillingProgress::dateLabel($deposits['window_from']) }} 起）。有金額相符的建議就按「沖銷」；沒有就「選擇應收」自己挑。沖銷後應收標成已收、收款日＝入帳日。</x-slot>

    @if ($rows === [] && $unmatched === [])
        <span class="bp-muted" style="font-size: 0.875rem;">每一筆收入都已經對到應收。</span>
    @else
        <div style="display: flex; flex-direction: column; gap: 1rem;">
            @if ($rows !== [])
                <div style="overflow-x: auto;">
                    <table class="bp-table">
                        <thead>
                            <tr>
                                <th scope="col">日期</th>
                                <th scope="col">戶名</th>
                                <th scope="col">客戶</th>
                                <th scope="col" class="bp-end">入帳（含稅）</th>
                                <th scope="col">建議沖銷</th>
                                <th scope="col"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @php
                                    $transaction = $row['transaction'];
                                    $best = $row['best'];
                                    $near = $best === null ? ($row['suggestions'][0] ?? null) : null;
                                @endphp
                                <tr wire:key="bp-deposit-{{ $transaction['id'] }}">
                                    <td class="bp-num"><a href="{{ BankTransactionResource::getUrl('edit', ['record' => $transaction['id']]) }}" class="bp-link">{{ BillingProgress::dateLabel($transaction['date']) }}</a></td>
                                    <td class="bp-wrap">{{ $transaction['counterparty'] ?? $transaction['summary'] }}</td>
                                    <td><a href="{{ CustomerResource::getUrl('edit', ['record' => $row['customer']['id']]) }}" class="bp-link" style="font-weight: 500;">{{ $row['customer']['short_name'] }}</a></td>
                                    <td class="bp-end bp-num" style="font-weight: 600;">{{ BillingProgress::money($transaction['deposit']) }}</td>
                                    <td class="bp-wrap">
                                        @if ($best !== null)
                                            <div>{{ $best['label'] }}</div>
                                            <div class="bp-muted bp-num" style="font-size: 0.75rem;">{{ $best['kind_label'] }}，含稅 {{ BillingProgress::money($best['amount_taxed']) }}@if ($best['difference'] !== 0)，差 {{ BillingProgress::money($best['difference']) }}@endif，預計 {{ BillingProgress::dateLabel($best['expected_on']) }}</div>
                                        @elseif ($near !== null && $near['far'])
                                            <div class="bp-warn">{{ $near['label'] }}（預計 {{ BillingProgress::dateLabel($near['expected_on']) }}）</div>
                                            <div class="bp-muted" style="font-size: 0.75rem;">金額相符但預計收款日差 {{ $near['days_from_deposit'] }} 天，可能是另一期的款，請確認後再沖銷</div>
                                        @elseif ($near !== null)
                                            <div class="bp-warn">{{ $near['label'] }}（{{ $near['kind_label'] }}，差 {{ BillingProgress::money($near['difference']) }}）</div>
                                            <div class="bp-muted" style="font-size: 0.75rem;">金額只是接近，請確認後再沖銷</div>
                                        @elseif ($row['outstanding_count'] === 0)
                                            <span class="bp-muted">這個客戶沒有未收的應收</span>
                                        @else
                                            <span class="bp-muted">沒有金額相符的應收（{{ $row['outstanding_count'] }} 筆未收）</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="bp-actions">
                                            @if ($best !== null)
                                                <button type="button" class="bp-btn bp-btn-primary" wire:click="mountAction('settleBest', { transaction: {{ $transaction['id'] }} })">沖銷</button>
                                            @endif
                                            <button type="button" class="bp-btn" wire:click="mountAction('settleDeposit', { transaction: {{ $transaction['id'] }} })">選擇應收</button>
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($unmatched !== [])
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <div style="font-size: 0.875rem; font-weight: 600;">對不到客戶的入帳</div>
                    <div class="bp-muted" style="font-size: 0.8125rem;">這些戶名不是任何客戶的名稱、簡稱或銀行入帳戶名。到 <a href="{{ CustomerResource::getUrl('index') }}" class="bp-link" style="text-decoration: underline;">客戶</a> 加「銀行入帳戶名」，或直接「選擇應收」沖銷。</div>
                    <div style="overflow-x: auto;">
                        <table class="bp-table">
                            <tbody>
                                @foreach ($unmatched as $row)
                                    @php($transaction = $row['transaction'])
                                    <tr wire:key="bp-unmatched-{{ $transaction['id'] }}">
                                        <td class="bp-num"><a href="{{ BankTransactionResource::getUrl('edit', ['record' => $transaction['id']]) }}" class="bp-link">{{ BillingProgress::dateLabel($transaction['date']) }}</a></td>
                                        <td class="bp-wrap">{{ $transaction['counterparty'] ?? $transaction['summary'] }}</td>
                                        <td class="bp-end bp-num" style="font-weight: 600;">{{ BillingProgress::money($transaction['deposit']) }}</td>
                                        <td><button type="button" class="bp-btn" wire:click="mountAction('settleDeposit', { transaction: {{ $transaction['id'] }} })">選擇應收</button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    @endif
</x-filament::section>
