@php
    use App\Filament\Pages\RevenueOutlook;

    $summary = $outlook['summary'];
    $options = $outlook['options'];
    $beyond = $outlook['assumptions']['delayed_beyond_horizon'];
    $lowTotal = $outlook['assumptions']['low_confidence_taxed'];
@endphp

<div style="display: flex; flex-direction: column; gap: 0.5rem;">
    <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem 1.5rem;">
        <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.625rem;">
            <span style="font-size: 0.875rem; font-weight: 500;">尾款延後</span>
            <div class="ro-seg" role="group" aria-label="尾款延後幾個月">
                @foreach ($delayOptions as $option)
                    <button
                        type="button"
                        wire:key="delay-{{ $option }}"
                        wire:click="$set('delayMonths', {{ $option }})"
                        aria-pressed="{{ $options['delay_months'] === $option ? 'true' : 'false' }}"
                    >{{ $option === 0 ? '不延後' : "{$option} 個月" }}</button>
                @endforeach
            </div>
        </div>

        <label style="display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; cursor: pointer;">
            <x-filament::input.checkbox wire:model.live="includeLowConfidence" />
            <span>計入低確定性應收</span>
            <span class="ro-muted ro-num" style="font-size: 0.75rem;">{{ $lowTotal > 0 ? RevenueOutlook::money($lowTotal).'（含稅）' : '目前沒有' }}</span>
        </label>
    </div>

    <div class="ro-muted" style="font-size: 0.75rem;">
        @if ($summary['has_bank_data'])
            期初是 {{ $summary['as_of'] }} 的銀行餘額 <span class="ro-num">{{ RevenueOutlook::money($summary['opening_balance']) }}</span>，往後看 {{ $summary['months'] }} 個月（到 {{ RevenueOutlook::monthLabel($summary['horizon_end']) }}）。
        @else
            還沒有銀行明細，期初餘額以 0 計算。
        @endif
        @if ($options['delay_months'] > 0)
            專案應收全部往後 {{ $options['delay_months'] }} 個月，經常性收入不動。
            @if ($beyond['count'] > 0)
                <span class="ro-warn">其中 {{ $beyond['count'] }} 筆共 <span class="ro-num">{{ RevenueOutlook::money($beyond['amount_taxed']) }}</span> 被推到展望期之外，沒有算進來。</span>
            @endif
        @endif
    </div>
</div>
