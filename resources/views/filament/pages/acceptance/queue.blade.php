@php
    $priorityColor = fn (?string $priority): ?string => match ($priority) {
        '急', '速', '緊急', '立即' => 'danger',
        '高' => 'warning',
        default => null,
    };
@endphp

<x-filament::section compact>
    <x-slot name="heading">建議驗收順序</x-slot>
    <x-slot name="description">擋著結案專案尾款的排最前面（目標日近的先），其餘等最久的先。</x-slot>

    @if ($projectOptions !== [])
        <x-slot name="afterHeader">
            <div style="min-width: 14rem;">
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="project" aria-label="專案">
                        <option value="">全部專案（{{ $summary['queue'] }}）</option>
                        @foreach ($projectOptions as $identifier => $label)
                            <option value="{{ $identifier }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
        </x-slot>
    @endif

    @if ($queueTotal === 0)
        <span class="aq-muted" style="font-size: 0.875rem;">沒有等待驗收的議題。</span>
    @else
        <div style="overflow-x: auto;">
            <table class="aq-table">
                <thead>
                    <tr>
                        <th scope="col" style="text-align: right;">順序</th>
                        <th scope="col">議題</th>
                        <th scope="col">專案</th>
                        <th scope="col" style="text-align: right;">等待</th>
                        <th scope="col">送驗人</th>
                        <th scope="col">優先</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($queue as $index => $row)
                        <tr wire:key="queue-{{ $row['id'] }}">
                            <td class="aq-muted aq-num" style="text-align: right; font-size: 0.75rem;">{{ $index + 1 }}</td>
                            <td style="min-width: 16rem;">
                                <a href="{{ $row['url'] }}" target="_blank" rel="noopener" class="aq-link aq-num" style="font-weight: 600;">#{{ $row['id'] }}</a>
                                <span style="overflow-wrap: anywhere;">{{ $row['subject'] }}</span>
                            </td>
                            <td>
                                <span style="display: inline-flex; align-items: center; gap: 0.375rem; white-space: nowrap;">
                                    <span>{{ $row['project_name'] }}</span>
                                    @if ($row['is_closing'])
                                        <span style="flex: none;"><x-filament::badge color="danger" size="sm">擋尾款</x-filament::badge></span>
                                    @endif
                                </span>
                            </td>
                            <td class="aq-num" style="text-align: right; white-space: nowrap;" title="{{ $row['waiting_observed'] ? $row['waiting_since'].' 送驗' : '沒有送驗紀錄，從最後更新日 '.$row['waiting_since'].' 算起，實際只會更久' }}">
                                @unless ($row['waiting_observed'])<span class="aq-muted">≥</span>@endunless
                                {{ $row['waiting_days'] }} 天
                            </td>
                            <td style="white-space: nowrap;">
                                @if ($row['handed_over_by'])
                                    {{ $row['handed_over_by'] }}
                                @else
                                    <span class="aq-muted">—</span>
                                @endif
                            </td>
                            <td style="white-space: nowrap;">
                                @if ($priorityColor($row['priority']))
                                    <x-filament::badge :color="$priorityColor($row['priority'])" size="sm">{{ $row['priority'] }}</x-filament::badge>
                                @else
                                    <span class="aq-muted">{{ $row['priority'] ?? '—' }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="aq-muted" style="display: flex; flex-wrap: wrap; gap: 0.25rem 0.75rem; margin-top: 0.75rem; font-size: 0.75rem;">
            @if ($queueTotal > $queue->count())
                <span>只列前 {{ $queue->count() }} 張，共 {{ $queueTotal }} 張。</span>
            @endif
            <span>「≥」表示沒有送驗紀錄，等待天數從最後更新日算起。</span>
            <a href="{{ $verifyingUrl }}" class="aq-link">在議題（鏡像）看全部驗證中 →</a>
        </div>
    @endif
</x-filament::section>
