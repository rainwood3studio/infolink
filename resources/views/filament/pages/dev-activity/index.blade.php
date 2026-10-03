@php
    $weekdays = ['日', '一', '二', '三', '四', '五', '六'];
    $dayLabel = fn (string $date): string => \Carbon\CarbonImmutable::parse($date)->format('m/d').'（'.$weekdays[\Carbon\CarbonImmutable::parse($date)->dayOfWeek].'）';
@endphp

<x-filament-panels::page>
    <style>
        .da-body[data-loading] { opacity: 0.5; transition: opacity 150ms; }
        .da-card { cursor: pointer; border-radius: 0.75rem; transition: box-shadow 150ms; }
        .da-card:hover { box-shadow: 0 0 0 1px color-mix(in oklab, var(--primary-500) 50%, transparent); }
        .da-card[aria-pressed="true"] { box-shadow: 0 0 0 2px var(--primary-500); }
        .da-muted { opacity: 0.65; }
        .da-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.8125rem; }
        .da-chip { display: inline-flex; align-items: center; gap: 0.25rem; max-width: 100%; padding: 0.0625rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; line-height: 1.25rem; background: color-mix(in oklab, var(--primary-500) 12%, transparent); color: inherit; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .da-chip:hover { background: color-mix(in oklab, var(--primary-500) 22%, transparent); }
        .da-chip-closed { background: color-mix(in oklab, var(--gray-500) 14%, transparent); }
        .da-link { color: inherit; text-decoration: none; }
        .da-link:hover { text-decoration: underline; }
        .da-heat td, .da-heat th { padding: 0; }
        .da-heat-cell { width: 1.875rem; height: 1.875rem; margin: 1px; border-radius: 0.25rem; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-variant-numeric: tabular-nums; }
        .da-commit { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.25rem 0.5rem; padding: 0.3125rem 0; border-top: 1px solid color-mix(in oklab, var(--gray-500) 15%, transparent); font-size: 0.875rem; }
        .da-commit:first-child { border-top: 0; }
    </style>

    <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem 1rem;">
        <x-filament::tabs contained label="期間">
            @foreach ($periods as $key => $label)
                <x-filament::tabs.item :active="$this->period === $key" wire:click="setPeriod('{{ $key }}')" wire:key="period-{{ $key }}">
                    {{ $label }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        @if ($hasData)
            <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem;">
                <span style="font-size: 0.875rem; opacity: 0.7; font-variant-numeric: tabular-nums;">
                    {{ $from->isSameDay($to) ? $dayLabel($from->toDateString()) : $dayLabel($from->toDateString()).' – '.$dayLabel($to->toDateString()) }}
                </span>
                <div style="min-width: 12rem;">
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="person" aria-label="人員">
                            <option value="">全部人員</option>
                            @foreach ($personOptions as $key => $name)
                                <option value="{{ $key }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            </div>
        @endif
    </div>

    @if (! $hasData)
        <x-filament::section>
            <x-filament::empty-state
                heading="尚未同步 GitHub"
                description="按右上角「立即同步」抓取 commit、PR 與 review；之後排程會自動更新。"
                :icon="\Filament\Support\Icons\Heroicon::OutlinedCodeBracket"
            />
        </x-filament::section>
    @else
        <div class="da-body" wire:loading.delay.attr="data-loading" style="display: flex; flex-direction: column; gap: 1.5rem;">
            @include('filament.pages.dev-activity.analysis', ['analysis' => $analysis])
            @include('filament.pages.dev-activity.people', ['people' => $people])
            @include('filament.pages.dev-activity.heatmap', ['heatmap' => $heatmap, 'heatmapIsTrailing' => $heatmapIsTrailing, 'weekdays' => $weekdays])
            @include('filament.pages.dev-activity.log', ['log' => $log, 'dayLabel' => $dayLabel])
            @include('filament.pages.dev-activity.untracked', ['untracked' => $untracked, 'dayLabel' => $dayLabel])
        </div>
    @endif
</x-filament-panels::page>
