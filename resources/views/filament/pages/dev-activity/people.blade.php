@php
    use App\Enums\CommitType;
@endphp

<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr)); gap: 1rem;">
    @forelse ($people as $row)
        @php
            $typeTotal = array_sum($row['type_mix']);
            $stats = [
                ['活動天數', $row['active_days'], '有 commit 的天數'],
                ['commits', $row['commits'], '不含 merge commit'],
                ['碰過的議題', $row['issues'], 'commit／PR 引用，或在 Redmine 登工時、送驗、結案的議題'],
                ['PR merged', $row['prs_merged'], null],
                ['reviews', $row['reviews'], null],
                ['AI 協作', $row['ai_assisted_ratio'] === null ? '—' : round($row['ai_assisted_ratio'] * 100).'%', 'commit 帶 Co-Authored-By: Claude 的比例'],
            ];
            $redmine = $row['redmine'];
            $redmineStats = $redmine === null ? [] : array_values(array_filter([
                ['工時', rtrim(rtrim(number_format($redmine['hours'], 1), '0'), '.').'h', "登錄在 {$redmine['hours_days']} 天；工時常沒登完整，僅供參考", null],
                $redmine['is_acceptor']
                    ? ['驗收', $redmine['accepted'], '期間內驗收結案的議題', null]
                    : ['送驗', $redmine['advanced_to_verify'], '期間內推進到「驗證中」的議題（真正的產出指標）', null],
                $redmine['is_acceptor'] || $redmine['closed_without_verify'] === 0
                    ? null
                    : ['直接結案', $redmine['closed_without_verify'], '名下議題沒經過「驗證中」就結案，略過了驗收', 'warning'],
                $redmine['is_acceptor']
                    ? ['待驗收', $redmine['verifying_assigned'], '目前在「驗證中」等他驗收的議題', $redmine['verifying_assigned'] > 0 ? 'warning' : null]
                    : ['名下未結', $redmine['open_assigned'], "目前指派給他的未結議題，其中 {$redmine['stalled_30d']} 張超過 30 天沒更新、{$redmine['verifying_assigned']} 張在驗證中", null],
            ]));
            $hasActivity = $row['commits'] > 0 || $row['reviews'] > 0 || $row['prs_merged'] > 0 || $row['issues'] > 0;
        @endphp
        <div
            class="da-card"
            role="button"
            tabindex="0"
            aria-pressed="{{ $this->person === $row['key'] ? 'true' : 'false' }}"
            wire:key="person-card-{{ $row['key'] }}"
            wire:click="togglePerson('{{ $row['key'] }}')"
            x-on:keydown.enter.prevent="$wire.togglePerson('{{ $row['key'] }}')"
            title="{{ $this->person === $row['key'] ? '再按一次取消篩選' : '只看 '.$row['name'] }}"
        >
            <x-filament::section compact>
                <div style="display: flex; flex-direction: column; gap: 0.75rem; {{ $hasActivity ? '' : 'opacity: 0.55;' }}">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;">
                        <span style="font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $row['name'] }}</span>
                        @if ($row['is_unmapped'])
                            <x-filament::badge color="warning" size="sm">未對應</x-filament::badge>
                        @elseif ($row['last_commit_at'])
                            <span class="da-muted" style="font-size: 0.75rem; white-space: nowrap;">最後 commit {{ $row['last_commit_at']->diffForHumans() }}</span>
                        @endif
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.5rem 0.75rem;">
                        @foreach ($stats as [$label, $value, $hint])
                            <div @if ($hint) title="{{ $hint }}" @endif>
                                <div class="da-muted" style="font-size: 0.75rem;">{{ $label }}</div>
                                <div style="font-size: 1.125rem; font-weight: 600; font-variant-numeric: tabular-nums;">{{ $value }}</div>
                            </div>
                        @endforeach
                    </div>

                    @if ($typeTotal > 0)
                        <div>
                            <div style="display: flex; height: 0.375rem; border-radius: 9999px; overflow: hidden; background: color-mix(in oklab, var(--gray-400) 25%, transparent);">
                                @foreach ($row['type_mix'] as $type => $count)
                                    <div
                                        title="{{ CommitType::from($type)->getLabel() }} {{ $count }}"
                                        style="width: {{ $count / $typeTotal * 100 }}%; background: var(--{{ CommitType::from($type)->getColor() }}-500);"
                                    ></div>
                                @endforeach
                            </div>
                            <div class="da-muted" style="display: flex; flex-wrap: wrap; gap: 0.125rem 0.625rem; margin-top: 0.375rem; font-size: 0.75rem;">
                                @foreach ($row['type_mix'] as $type => $count)
                                    <span style="display: inline-flex; align-items: center; gap: 0.25rem;">
                                        <span style="width: 0.5rem; height: 0.5rem; border-radius: 9999px; background: var(--{{ CommitType::from($type)->getColor() }}-500);"></span>
                                        {{ CommitType::from($type)->getLabel() }} {{ $count }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($redmine !== null)
                        <div style="display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.25rem 0.875rem; padding-top: 0.625rem; border-top: 1px solid color-mix(in oklab, var(--gray-500) 15%, transparent); font-size: 0.8125rem;">
                            <span class="da-muted" style="font-size: 0.75rem;" title="Redmine 上的名字：{{ $redmine['redmine_name'] }}">Redmine</span>
                            @foreach ($redmineStats as [$label, $value, $hint, $color])
                                <span title="{{ $hint }}" style="white-space: nowrap;">
                                    <span class="da-muted" style="font-size: 0.75rem;">{{ $label }}</span>
                                    <span style="font-weight: 600; font-variant-numeric: tabular-nums; {{ $color ? "color: var(--{$color}-600);" : '' }}">{{ $value }}</span>
                                </span>
                            @endforeach
                        </div>
                    @endif

                    <div class="da-muted" style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 0.25rem 0.75rem; font-size: 0.75rem;">
                        <span style="font-variant-numeric: tabular-nums;" title="行數已排除 lock 檔與產生的檔案，僅供參考">
                            +{{ number_format($row['lines_added']) }} / −{{ number_format($row['lines_deleted']) }} 行
                        </span>
                        @if ($row['repos'] !== [])
                            <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                {{ collect($row['repos'])->map(fn (int $count, string $repo): string => "{$repo} {$count}")->implode(' · ') }}
                            </span>
                        @endif
                    </div>
                </div>
            </x-filament::section>
        </div>
    @empty
        <x-filament::section compact>
            <span class="da-muted">這段期間沒有人有 GitHub 活動。</span>
        </x-filament::section>
    @endforelse
</div>
