@php
    $parsedDates = collect($heatmap['dates'])->mapWithKeys(fn (string $date): array => [$date => \Carbon\CarbonImmutable::parse($date)]);
@endphp

<x-filament::section compact>
    <x-slot name="heading">每日 commit 熱度</x-slot>
    <x-slot name="description">
        {{ $heatmapIsTrailing ? '近 '.count($heatmap['dates']).' 天（今天／昨天改看較長區間）' : '期間內每人每天的 commit 數' }}，不含 merge。
    </x-slot>

    @if ($heatmap['rows'] === [])
        <span class="da-muted" style="font-size: 0.875rem;">這段期間沒有 commit。</span>
    @else
        <div style="overflow-x: auto;">
            <table class="da-heat" style="border-collapse: separate; border-spacing: 0;">
                <thead>
                    <tr>
                        <th></th>
                        @foreach ($parsedDates as $date => $day)
                            <th scope="col" class="da-muted" style="font-size: 0.6875rem; font-weight: 400; text-align: center; line-height: 1.1; padding-bottom: 0.25rem; {{ $day->isWeekend() ? 'opacity: 0.45;' : '' }}">
                                {{ $day->format('n/j') }}<br>{{ $weekdays[$day->dayOfWeek] }}
                            </th>
                        @endforeach
                        <th scope="col" class="da-muted" style="font-size: 0.6875rem; font-weight: 400; padding-left: 0.5rem;">合計</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($heatmap['rows'] as $row)
                        <tr wire:key="heat-{{ $row['key'] }}">
                            <th scope="row" style="font-size: 0.8125rem; font-weight: 500; text-align: left; white-space: nowrap; padding-right: 0.75rem; max-width: 12rem; overflow: hidden; text-overflow: ellipsis;">
                                {{ $row['name'] }}
                            </th>
                            @foreach ($parsedDates as $date => $day)
                                @php
                                    $count = $row['counts'][$date] ?? 0;
                                    $strength = $count > 0 ? 18 + (int) round(82 * $count / max(1, $heatmap['max'])) : 0;
                                @endphp
                                <td>
                                    <div
                                        class="da-heat-cell"
                                        title="{{ $row['name'] }} · {{ $day->format('m/d') }}（{{ $weekdays[$day->dayOfWeek] }}）· {{ $count }} commits"
                                        style="background: {{ $count > 0 ? "color-mix(in oklab, var(--primary-500) {$strength}%, transparent)" : 'color-mix(in oklab, var(--gray-400) 12%, transparent)' }}; {{ $strength >= 60 ? 'color: white; font-weight: 600;' : '' }}"
                                    >
                                        {{ $count > 0 ? $count : '' }}
                                    </div>
                                </td>
                            @endforeach
                            <td style="padding-left: 0.5rem; font-size: 0.8125rem; font-weight: 600; font-variant-numeric: tabular-nums; text-align: right;">{{ $row['total'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>
