@php
    $projectMax = max(1, 0, ...array_column($summary['by_project'], 'count'));
    $countdown = fn (?int $daysLeft): string => match (true) {
        $daysLeft === null => '未設定目標日',
        $daysLeft > 0 => "剩 {$daysLeft} 天",
        $daysLeft === 0 => '今天到期',
        default => '已過 '.abs($daysLeft).' 天',
    };
@endphp

<x-filament::section compact>
    <x-slot name="heading">依專案</x-slot>
    <x-slot name="description">結案中的專案排前面；點一下專案只看它的隊列。</x-slot>

    @if ($summary['by_project'] === [])
        <span class="aq-muted" style="font-size: 0.875rem;">隊列是空的。</span>
    @else
        <div style="display: flex; flex-direction: column; gap: 0.125rem;">
            @foreach ($summary['by_project'] as $row)
                <button
                    type="button"
                    class="aq-project"
                    wire:key="project-{{ $row['identifier'] }}"
                    wire:click="$set('project', {{ $this->project === $row['identifier'] ? 'null' : \Illuminate\Support\Js::from($row['identifier']) }})"
                    aria-pressed="{{ $this->project === $row['identifier'] ? 'true' : 'false' }}"
                    title="{{ $this->project === $row['identifier'] ? '再按一次取消篩選' : '只看 '.$row['name'] }}"
                >
                    <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.25rem 0.5rem; font-size: 0.875rem;">
                        <span style="display: inline-flex; flex-wrap: wrap; align-items: center; gap: 0.375rem; min-width: 0;">
                            <span style="font-weight: 500; overflow-wrap: anywhere;">{{ $row['name'] }}</span>
                            @if ($row['is_closing'])
                                <x-filament::badge :color="$row['days_left'] !== null && $row['days_left'] > \App\Filament\Widgets\ClosingProjectsWidget::URGENT_DAYS ? 'gray' : 'danger'" size="sm">
                                    結案中 · {{ $countdown($row['days_left']) }}
                                </x-filament::badge>
                            @endif
                        </span>
                        <span class="aq-muted aq-num" style="font-size: 0.75rem; white-space: nowrap;">最久 {{ $row['oldest_waiting_days'] }} 天</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 0.75rem; margin-top: 0.25rem;">
                        <div class="aq-track"><div class="aq-bar aq-bar-accepted" style="width: {{ $row['count'] / $projectMax * 100 }}%;"></div></div>
                        <span class="aq-num" style="flex: 0 0 2.5rem; text-align: right; font-size: 0.875rem; font-weight: 600;">{{ $row['count'] }}</span>
                    </div>
                </button>
            @endforeach
        </div>
    @endif
</x-filament::section>
