@php
    $bucketLabels = ['le_7' => '7 天內', 'd8_30' => '8–30 天', 'd31_90' => '31–90 天', 'gt_90' => '超過 90 天'];
    $bucketMax = max(1, ...array_values($summary['age_buckets']));
    $unobserved = $summary['queue'] - $summary['waiting_observed'];
@endphp

<x-filament::section compact>
    <x-slot name="heading">等了多久</x-slot>
    <x-slot name="description">隊列裡的議題依等待天數分布。</x-slot>

    @if ($summary['queue'] === 0)
        <span class="aq-muted" style="font-size: 0.875rem;">隊列是空的。</span>
    @else
        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
            @foreach ($summary['age_buckets'] as $key => $count)
                <div style="display: flex; align-items: center; gap: 0.75rem; font-size: 0.875rem;" title="{{ $bucketLabels[$key] }}：{{ $count }} 張（{{ round($count / $summary['queue'] * 100) }}%）">
                    <span style="flex: 0 0 5.5rem;">{{ $bucketLabels[$key] }}</span>
                    <div class="aq-track"><div class="aq-bar aq-bar-accepted" style="width: {{ $count / $bucketMax * 100 }}%;"></div></div>
                    <span class="aq-num" style="flex: 0 0 2.5rem; text-align: right; font-weight: 600;">{{ $count }}</span>
                </div>
            @endforeach
        </div>

        @if ($unobserved > 0)
            <p class="aq-muted" style="margin-top: 0.75rem; font-size: 0.75rem;">
                等待天數從送驗那天算起。{{ $unobserved }} 張沒有送驗紀錄（開始記錄前就送驗了），改從最後更新日算，實際只會更久。
            </p>
        @endif
    @endif
</x-filament::section>
