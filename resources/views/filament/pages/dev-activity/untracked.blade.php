<x-filament::section compact collapsible :collapsed="$untracked['total'] === 0">
    <x-slot name="heading">沒掛 Redmine 議題的 commit</x-slot>
    <x-slot name="description">
        @if ($untracked['commits_total'] === 0)
            這段期間沒有 commit。
        @else
            {{ $untracked['total'] }} / {{ $untracked['commits_total'] }} 筆（{{ round($untracked['total'] / $untracked['commits_total'] * 100) }}%）沒有引用任何 Redmine 議題，看不出在解哪張單。
        @endif
    </x-slot>

    @if ($untracked['total'] > 0)
        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem 1.25rem; margin-bottom: 0.75rem; font-size: 0.875rem;">
            @foreach ($untracked['people'] as $row)
                <span wire:key="untracked-{{ $row['key'] }}">
                    <span style="font-weight: 600;">{{ $row['name'] }}</span>
                    <span style="font-variant-numeric: tabular-nums;">{{ $row['count'] }}</span>
                    <span class="da-muted" style="font-size: 0.75rem;">/ {{ $row['commits'] }}</span>
                </span>
            @endforeach
        </div>

        <div>
            @foreach ($untracked['commits'] as $commit)
                <div class="da-commit">
                    <span class="da-muted" style="font-variant-numeric: tabular-nums; font-size: 0.75rem;">{{ $dayLabel($commit['date']) }} {{ $commit['time'] }}</span>
                    <span style="font-size: 0.8125rem;">{{ $commit['person'] }}</span>
                    <span class="da-mono da-muted">{{ $commit['repo'] }}</span>
                    <x-filament::badge :color="$commit['type']->getColor()" size="sm">{{ $commit['type']->getLabel() }}</x-filament::badge>
                    <span style="flex: 1 1 16rem; min-width: 0; overflow-wrap: anywhere;" title="{{ $commit['subject'] }}">{{ $commit['title'] }}</span>
                    <a href="{{ $commit['url'] }}" target="_blank" rel="noopener" class="da-link da-mono da-muted">{{ $commit['sha'] }}</a>
                </div>
            @endforeach
            @if ($untracked['total'] > count($untracked['commits']))
                <div class="da-muted" style="font-size: 0.75rem; padding-top: 0.5rem;">只列最新 {{ count($untracked['commits']) }} 筆。</div>
            @endif
        </div>
    @endif
</x-filament::section>
