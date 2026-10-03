@php
    $maxRows = \App\Filament\Pages\AcceptanceQueue::MAX_OFF_FLOW_ROWS;
    $offFlowTotal = $summary['off_flow']['total'];
@endphp

<x-filament::section compact collapsible :collapsed="$offFlowTotal === 0">
    <x-slot name="heading">流程外：驗證中但不在{{ $acceptor }}名下</x-slot>
    <x-slot name="description">
        @if ($offFlowTotal === 0)
            沒有流程外的議題。
        @else
            {{ $offFlowTotal }} 張沒有人會驗收。請負責人轉給{{ $acceptor }}進隊列；已經確認沒問題的就直接結案。
        @endif
    </x-slot>

    @if ($offFlowTotal > 0)
        <div style="display: flex; flex-direction: column; gap: 1.25rem;">
            @foreach ($offFlow as $group)
                <div wire:key="off-flow-{{ $group['assignee'] }}">
                    <div style="display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.25rem 0.75rem; margin-bottom: 0.25rem;">
                        <span style="font-weight: 600;">{{ $group['assignee'] }}</span>
                        <span class="aq-num" style="font-size: 0.875rem;">{{ $group['count'] }} 張</span>
                        <span class="aq-muted aq-num" style="font-size: 0.75rem;">最久 {{ $group['oldest_days_since_update'] }} 天沒更新</span>
                    </div>

                    <div style="overflow-x: auto;">
                        <table class="aq-table">
                            <tbody>
                                @foreach (array_slice($group['issues'], 0, $maxRows) as $row)
                                    <tr wire:key="off-flow-issue-{{ $row['id'] }}">
                                        <td style="min-width: 16rem;">
                                            <a href="{{ $row['url'] }}" target="_blank" rel="noopener" class="aq-link aq-num" style="font-weight: 600;">#{{ $row['id'] }}</a>
                                            <span style="overflow-wrap: anywhere;">{{ $row['subject'] }}</span>
                                        </td>
                                        <td style="width: 18rem;">
                                            <span style="display: inline-flex; flex-wrap: wrap; align-items: center; gap: 0.375rem;">
                                                <span>{{ $row['project_name'] }}</span>
                                                @if ($row['is_closing'])
                                                    <x-filament::badge color="danger" size="sm">擋尾款</x-filament::badge>
                                                @endif
                                            </span>
                                        </td>
                                        <td class="aq-num" style="width: 8rem; text-align: right; white-space: nowrap;">{{ $row['days_since_update'] }} 天沒更新</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($group['count'] > $maxRows)
                        <div class="aq-muted" style="padding-top: 0.375rem; font-size: 0.75rem;">只列最久沒更新的 {{ $maxRows }} 張。</div>
                    @endif
                </div>
            @endforeach
        </div>

        <div style="margin-top: 0.75rem; font-size: 0.75rem;">
            <a href="{{ $offFlowUrl }}" class="aq-link">在議題（鏡像）看全部 →</a>
        </div>
    @endif
</x-filament::section>
