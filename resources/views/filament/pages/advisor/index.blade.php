@php
    $light = [
        'red' => ['color' => 'var(--danger-500)', 'label' => '本週要處理'],
        'yellow' => ['color' => 'var(--warning-500)', 'label' => '要留意'],
        'green' => ['color' => 'var(--success-500)', 'label' => '正常'],
    ];
    $markdown = fn (string $body): string => \Illuminate\Support\Str::markdown($body, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
@endphp

<x-filament-panels::page>
    <style>
        .adv-muted { opacity: 0.65; }
        .adv-link { color: inherit; text-decoration: none; }
        .adv-link:hover { text-decoration: underline; }
        .adv-dot { display: inline-block; width: 0.75rem; height: 0.75rem; border-radius: 9999px; flex: none; }
        .adv-top { counter-reset: adv; display: flex; flex-direction: column; gap: 0.5rem; margin: 0; padding: 0; list-style: none; }
        .adv-top li { counter-increment: adv; display: flex; gap: 0.75rem; align-items: baseline; font-size: 0.9375rem; }
        .adv-top li::before { content: counter(adv); flex: none; width: 1.5rem; height: 1.5rem; border-radius: 9999px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8125rem; font-weight: 600; background: color-mix(in oklab, var(--primary-500) 18%, transparent); }
    </style>

    @if (! $report)
        <x-filament::section>
            <x-filament::empty-state
                heading="還沒有 AI 顧問分析"
                description="每個工作日 08:55 會自動產生。要馬上看，在 Claude 裡說「跑一次 AI 顧問分析」（infolink MCP 的 company-advisor prompt）。"
                :icon="\Filament\Support\Icons\Heroicon::OutlinedSparkles"
            />
        </x-filament::section>
    @else
        <div style="display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 0.5rem 1rem;">
            <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem 0.75rem;">
                <span style="font-size: 0.875rem;" class="adv-muted">{{ $report->period_start->format('Y-m-d') }} 的分析 · {{ $report->updated_at->diffForHumans() }}產生</span>
                @if ($isStale)
                    <x-filament::badge color="warning" size="sm">不是最新的：今天的分析還沒產生</x-filament::badge>
                @endif
            </div>
            <a href="{{ $reportUrl }}" class="adv-link" style="font-size: 0.875rem;">完整報告與歷史 →</a>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr)); gap: 1rem;">
            @foreach ($domains as $domain)
                @php $state = $light[$domain['status']] ?? null; @endphp
                <x-filament::section compact wire:key="advisor-domain-{{ $domain['key'] }}">
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;">
                            <span style="display: inline-flex; align-items: center; gap: 0.5rem; font-weight: 600;">
                                <span class="adv-dot" style="background: {{ $state['color'] ?? 'color-mix(in oklab, var(--gray-500) 40%, transparent)' }};"></span>
                                {{ $domain['name'] }}
                            </span>
                            <span class="adv-muted" style="font-size: 0.75rem; white-space: nowrap;">{{ $state['label'] ?? '沒有評估' }}</span>
                        </div>
                        <div style="font-size: 0.9375rem; line-height: 1.5;">{{ $domain['headline'] ?? '這份分析沒有提到這個面向。' }}</div>
                    </div>
                </x-filament::section>
            @endforeach
        </div>

        @if ($top !== [])
            <x-filament::section compact>
                <x-slot name="heading">最重要的三件事</x-slot>
                <ol class="adv-top">
                    @foreach ($top as $line)
                        <li><span>{{ $line }}</span></li>
                    @endforeach
                </ol>
            </x-filament::section>
        @endif

        <x-filament::section :icon="\Filament\Support\Icons\Heroicon::OutlinedSparkles">
            <x-slot name="heading">完整分析</x-slot>
            <x-slot name="description">{{ $report->title }}</x-slot>
            <div class="fi-prose" style="max-width: none;">{!! $markdown($report->body) !!}</div>
        </x-filament::section>
    @endif

    @if ($brief)
        <x-filament::section compact collapsible collapsed>
            <x-slot name="heading">每日簡報 · {{ $brief->period_start->format('m/d') }}</x-slot>
            <x-slot name="description">今天要處理的事（平日 08:30 產生，摘要會推到 LINE）。</x-slot>
            <div class="fi-prose" style="max-width: none;">{!! $markdown($brief->body) !!}</div>
        </x-filament::section>
    @endif

    @if ($others->isNotEmpty())
        <x-filament::section compact>
            <x-slot name="heading">其他分析</x-slot>
            <div style="display: flex; flex-direction: column; gap: 0.5rem; font-size: 0.875rem;">
                @foreach ($others as $other)
                    <div style="display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.25rem 0.75rem;" wire:key="advisor-other-{{ $other->id }}">
                        <x-filament::badge :color="$other->type->getColor()" size="sm">{{ $other->type->getLabel() }}</x-filament::badge>
                        <a href="{{ \App\Filament\Resources\Reports\ReportResource::getUrl('view', ['record' => $other]) }}" class="adv-link" style="font-weight: 600;">{{ $other->title }}</a>
                        <span class="adv-muted">{{ $other->period_start->format('m/d') }}</span>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
