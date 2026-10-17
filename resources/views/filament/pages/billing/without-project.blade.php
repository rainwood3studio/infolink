@php
    use App\Filament\Pages\BillingProgress;
    use App\Filament\Resources\Customers\CustomerResource;
@endphp

<x-filament::section compact>
    <x-slot name="heading">沒有掛專案的應收</x-slot>
    <x-slot name="description">這些應收沒有指定專案，不會算進任何一條進度。到應收帳款把專案補上，或留在這裡（顧問費、零星的案子）。</x-slot>

    <div style="display: flex; flex-direction: column; gap: 1rem;">
        @foreach ($groups as $group)
            <div wire:key="bp-without-{{ $group['customer_id'] }}" style="display: flex; flex-direction: column; gap: 0.5rem;">
                <div class="bp-head">
                    <a href="{{ CustomerResource::getUrl('edit', ['record' => $group['customer_id']]) }}" class="bp-link" style="font-weight: 600;">{{ $group['customer'] }}</a>
                    <div class="bp-figures bp-num">
                        <span><span class="bp-muted">已收</span> {{ BillingProgress::money($group['received']) }}</span>
                        <span><span class="bp-muted">未收</span> {{ BillingProgress::money($group['outstanding']) }}</span>
                        @if ($group['overdue']['count'] > 0)
                            <span class="bp-danger">逾期 {{ $group['overdue']['count'] }} 筆 {{ BillingProgress::money($group['overdue']['amount']) }}</span>
                        @endif
                        @if ($group['due_uninvoiced']['count'] > 0)
                            <span class="bp-warn">到期未開票 {{ $group['due_uninvoiced']['count'] }} 筆</span>
                        @endif
                    </div>
                </div>
                @include('filament.pages.billing.receivables', ['rows' => $group['receivables'], 'prefix' => 'bp-without-'.$group['customer_id']])
            </div>
        @endforeach
    </div>
</x-filament::section>
