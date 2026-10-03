@php
    /** Days beyond this many start collapsed. */
    $openDays = 3;
@endphp

<div style="display: flex; flex-direction: column; gap: 0.75rem;">
    <div style="display: flex; align-items: baseline; gap: 0.75rem;">
        <h2 style="font-size: 1rem; font-weight: 600;">每日工作日誌</h2>
        <span class="da-muted" style="font-size: 0.8125rem;">每天 → 每人 → 每個 repo；議題來自 commit 引用與當天在 Redmine 的工時／送驗／結案，短 sha 連到 GitHub。</span>
    </div>

    @forelse ($log as $index => $day)
        <div wire:key="log-day-{{ $day['date'] }}-{{ $this->period }}-{{ $this->person ?? 'all' }}">
            <x-filament::section compact collapsible :collapsed="$index >= $openDays">
                <x-slot name="heading">{{ $dayLabel($day['date']) }}</x-slot>
                <x-slot name="description">{{ $day['commits'] }} commits · {{ count($day['people']) }} 人</x-slot>

                <div style="display: flex; flex-direction: column; gap: 1.25rem;">
                    @foreach ($day['people'] as $person)
                        <div wire:key="log-{{ $day['date'] }}-{{ $person['key'] }}">
                            <div style="display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.25rem 0.75rem; margin-bottom: 0.5rem;">
                                <span style="font-weight: 600;">{{ $person['name'] }}</span>
                                <span class="da-muted" style="font-size: 0.8125rem; font-variant-numeric: tabular-nums;">
                                    @if ($person['commits'] > 0)
                                        {{ $person['commits'] }} commits · +{{ number_format($person['lines_added']) }} / −{{ number_format($person['lines_deleted']) }}
                                    @else
                                        無 commit
                                    @endif
                                    @if ($person['redmine_hours'] > 0)
                                        · Redmine 工時 {{ rtrim(rtrim(number_format($person['redmine_hours'], 1), '0'), '.') }}h
                                    @endif
                                </span>
                            </div>

                            @if ($person['issues'] !== [])
                                <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.375rem; margin-bottom: 0.5rem;">
                                    <span class="da-muted" style="font-size: 0.75rem;">議題</span>
                                    @foreach ($person['issues'] as $issue)
                                        <a href="{{ $issue['url'] }}" target="_blank" rel="noopener" @class(['da-chip', 'da-chip-closed' => $issue['is_closed']]) title="#{{ $issue['id'] }} {{ $issue['subject'] }}（{{ $issue['status'] }}）" style="max-width: 28rem;">
                                            <span style="font-weight: 600;">#{{ $issue['id'] }}</span>
                                            <span style="overflow: hidden; text-overflow: ellipsis;">{{ $issue['subject'] }}</span>
                                            <span class="da-muted">· {{ $issue['status'] }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            @endif

                            @if ($person['repos'] !== [] || $person['merged_prs'] !== [])
                            <div style="display: flex; flex-direction: column; gap: 0.5rem; padding-left: 0.75rem; border-left: 2px solid color-mix(in oklab, var(--gray-500) 20%, transparent);">
                                @foreach ($person['repos'] as $repo)
                                    <div>
                                        <a href="{{ $repo['url'] }}" target="_blank" rel="noopener" class="da-link da-mono da-muted" style="font-size: 0.75rem;">{{ $repo['full_name'] }}</a>
                                        <div>
                                            @foreach ($repo['commits'] as $commit)
                                                <div class="da-commit">
                                                    <span class="da-muted" style="font-variant-numeric: tabular-nums; font-size: 0.75rem;">{{ $commit['time'] }}</span>
                                                    <x-filament::badge :color="$commit['type']->getColor()" size="sm">{{ $commit['type']->getLabel() }}</x-filament::badge>
                                                    @if ($commit['scope'])
                                                        <span class="da-mono da-muted">{{ $commit['scope'] }}</span>
                                                    @endif
                                                    <span style="flex: 1 1 16rem; min-width: 0; overflow-wrap: anywhere;" title="{{ $commit['subject'] }}">{{ $commit['title'] }}</span>
                                                    @foreach ($commit['issues'] as $issue)
                                                        <a href="{{ $issue['url'] }}" target="_blank" rel="noopener" @class(['da-chip', 'da-chip-closed' => $issue['is_closed']]) title="{{ $issue['subject'] }}（{{ $issue['status'] }}）">#{{ $issue['id'] }}</a>
                                                    @endforeach
                                                    @if ($commit['is_ai_assisted'])
                                                        <x-filament::badge color="gray" size="sm" :icon="\Filament\Support\Icons\Heroicon::Sparkles" tooltip="Co-Authored-By Claude">AI</x-filament::badge>
                                                    @endif
                                                    <span class="da-muted" style="font-size: 0.75rem; font-variant-numeric: tabular-nums;">+{{ $commit['lines_added'] }} −{{ $commit['lines_deleted'] }}</span>
                                                    <a href="{{ $commit['url'] }}" target="_blank" rel="noopener" class="da-link da-mono da-muted">{{ $commit['sha'] }}</a>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach

                                @if ($person['merged_prs'] !== [])
                                    <div>
                                        <span class="da-muted" style="font-size: 0.75rem;">merge 的 PR</span>
                                        @foreach ($person['merged_prs'] as $pr)
                                            <div class="da-commit">
                                                <span class="da-muted" style="font-variant-numeric: tabular-nums; font-size: 0.75rem;">{{ $pr['time'] }}</span>
                                                <x-filament::badge color="success" size="sm">PR merged</x-filament::badge>
                                                <span class="da-mono da-muted">{{ $pr['repo'] }}</span>
                                                <a href="{{ $pr['url'] }}" target="_blank" rel="noopener" class="da-link" style="flex: 1 1 16rem; min-width: 0;">#{{ $pr['number'] }} {{ $pr['title'] }}</a>
                                                @foreach ($pr['issues'] as $issue)
                                                    <a href="{{ $issue['url'] }}" target="_blank" rel="noopener" @class(['da-chip', 'da-chip-closed' => $issue['is_closed']]) title="{{ $issue['subject'] }}（{{ $issue['status'] }}）">#{{ $issue['id'] }}</a>
                                                @endforeach
                                                <span class="da-muted" style="font-size: 0.75rem; font-variant-numeric: tabular-nums;">+{{ $pr['lines_added'] }} −{{ $pr['lines_deleted'] }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        </div>
    @empty
        <x-filament::section compact>
            <span class="da-muted">這段期間沒有 commit、merge 的 PR 或 Redmine 活動。</span>
        </x-filament::section>
    @endforelse
</div>
