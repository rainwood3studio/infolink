@php
    use App\Enums\CommitType;
@endphp

<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr)); gap: 1rem;">
    @forelse ($people as $row)
        @php
            $typeTotal = array_sum($row['type_mix']);
            $stats = [
                ['活動天數', $row['active_days']],
                ['commits', $row['commits']],
                ['碰過的議題', $row['issues']],
                ['PR merged', $row['prs_merged']],
                ['reviews', $row['reviews']],
                ['AI 協作', $row['ai_assisted_ratio'] === null ? '—' : round($row['ai_assisted_ratio'] * 100).'%'],
            ];
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
                <div style="display: flex; flex-direction: column; gap: 0.75rem; {{ $row['commits'] === 0 && $row['reviews'] === 0 && $row['prs_merged'] === 0 ? 'opacity: 0.55;' : '' }}">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;">
                        <span style="font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $row['name'] }}</span>
                        @if ($row['is_unmapped'])
                            <x-filament::badge color="warning" size="sm">未對應</x-filament::badge>
                        @elseif ($row['last_commit_at'])
                            <span class="da-muted" style="font-size: 0.75rem; white-space: nowrap;">最後 commit {{ $row['last_commit_at']->diffForHumans() }}</span>
                        @endif
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.5rem 0.75rem;">
                        @foreach ($stats as [$label, $value])
                            <div>
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
