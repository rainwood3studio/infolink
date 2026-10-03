@php
    use Carbon\CarbonImmutable;

    $weeks = $flow['weeks'];
    $issueMax = max(1, ...array_map(fn (array $week): int => max($week['accepted'], (int) $week['handed_over']), $weeks));
    $commitMax = max(1, ...array_map(fn (array $week): int => (int) $week['acceptor_commits'], $weeks));
    $trackedSince = $flow['status_tracked_since'] === null ? null : CarbonImmutable::parse($flow['status_tracked_since']);
    $hasMissingHandovers = collect($weeks)->contains(fn (array $week): bool => $week['handed_over'] === null || $week['handed_over_partial']);
    $hasMissingCommits = collect($weeks)->contains(fn (array $week): bool => $week['acceptor_commits'] === null);
@endphp

<x-filament::section compact>
    <x-slot name="heading">每週流量</x-slot>
    <x-slot name="description">驗收（結案且在{{ $acceptor }}名下）對送驗（推進到驗證中），旁邊是{{ $acceptor }}同一週的 commit 數：寫程式和驗收搶的是同一個人的時間。</x-slot>

    <div style="overflow-x: auto;">
        <table class="aq-table">
            <thead>
                <tr>
                    <th scope="col" style="width: 1%;">週</th>
                    <th scope="col" style="width: 30%;">驗收</th>
                    <th scope="col" style="width: 30%;">送驗</th>
                    <th scope="col" style="width: 30%;">{{ $flow['acceptor_developer'] ?? $acceptor }} commits</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($weeks as $week)
                    @php
                        $from = CarbonImmutable::parse($week['week_start']);
                        $to = CarbonImmutable::parse($week['week_end']);
                    @endphp
                    <tr wire:key="flow-{{ $week['week_start'] }}">
                        <td class="aq-num" style="white-space: nowrap;">
                            {{ $from->format('m/d') }}–{{ $to->format('m/d') }}
                            @if ($week['is_current'])
                                <span class="aq-muted" style="font-size: 0.75rem;">本週至今</span>
                            @endif
                        </td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 0.5rem;" title="{{ $from->format('m/d') }} 這週驗收 {{ $week['accepted'] }} 張">
                                <span class="aq-num" style="min-width: 1.75rem; text-align: right; font-weight: 600;">{{ $week['accepted'] }}</span>
                                <div class="aq-track"><div class="aq-bar aq-bar-accepted" style="width: {{ $week['accepted'] / $issueMax * 100 }}%;"></div></div>
                            </div>
                        </td>
                        <td>
                            @if ($week['handed_over'] === null)
                                <span class="aq-muted aq-num" style="display: inline-block; min-width: 1.75rem; text-align: right;" title="這週還沒開始記錄狀態變更，沒有資料（不是 0）">—</span>
                            @else
                                <div style="display: flex; align-items: center; gap: 0.5rem;" title="{{ $from->format('m/d') }} 這週送驗 {{ $week['handed_over'] }} 張{{ $week['handed_over_partial'] ? '（'.$trackedSince->format('m/d').' 才開始記錄，這週不完整）' : '' }}">
                                    <span class="aq-num" style="min-width: 1.75rem; text-align: right; font-weight: 600;">{{ $week['handed_over'] }}{{ $week['handed_over_partial'] ? '*' : '' }}</span>
                                    <div class="aq-track"><div class="aq-bar aq-bar-handed" style="width: {{ $week['handed_over'] / $issueMax * 100 }}%;"></div></div>
                                </div>
                            @endif
                        </td>
                        <td>
                            @if ($week['acceptor_commits'] === null)
                                <span class="aq-muted aq-num" style="display: inline-block; min-width: 1.75rem; text-align: right;" title="沒有這週的 commit 資料（不是 0）">—</span>
                            @else
                                <div style="display: flex; align-items: center; gap: 0.5rem;" title="{{ $from->format('m/d') }} 這週 {{ $week['acceptor_commits'] }} 個 commit（不含 merge）">
                                    <span class="aq-num" style="min-width: 1.75rem; text-align: right; font-weight: 600;">{{ $week['acceptor_commits'] }}</span>
                                    <div class="aq-track"><div class="aq-bar aq-bar-commits" style="width: {{ $week['acceptor_commits'] / $commitMax * 100 }}%;"></div></div>
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="aq-muted" style="display: flex; flex-direction: column; gap: 0.125rem; margin-top: 0.75rem; font-size: 0.75rem;">
        <span>驗收與送驗用同一個刻度；commit 數是另一種單位，只跟自己比。</span>
        @if ($trackedSince === null)
            <span>送驗要等第一次同步 Redmine 之後才開始記錄，目前沒有資料（不是 0）。</span>
        @elseif ($hasMissingHandovers)
            <span>送驗從 {{ $trackedSince->format('m/d') }} 開始記錄：更早的週沒有資料（不是 0），標 * 的那週不完整。</span>
        @endif
        @if ($flow['acceptor_developer'] === null)
            <span>還沒有對應到{{ $acceptor }}的開發者（在「開發者」設定 Redmine 名稱與 GitHub 帳號），所以沒有 commit 數。</span>
        @elseif ($hasMissingCommits)
            <span>GitHub 資料只保留 {{ CarbonImmutable::parse($flow['commits_retention_start'])->format('m/d') }} 之後的 commit，更早的週沒有資料。</span>
        @endif
    </div>
</x-filament::section>
