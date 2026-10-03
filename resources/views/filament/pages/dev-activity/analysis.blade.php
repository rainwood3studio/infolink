<x-filament::section compact collapsible :icon="\Filament\Support\Icons\Heroicon::OutlinedSparkles">
    <x-slot name="heading">AI 分析建議</x-slot>
    <x-slot name="description">
        @if ($analysis)
            {{ $analysis->period_start->format('m/d') }} 產生（{{ $analysis->updated_at->diffForHumans() }}）· 每個工作日 08:45 由 Claude 依前一個工作日與近 7 天的資料分析
        @else
            每個工作日 08:45 由 Claude 依前一個工作日與近 7 天的資料分析。
        @endif
    </x-slot>

    @if ($analysis)
        <x-slot name="afterHeader">
            <a href="{{ \App\Filament\Resources\Reports\ReportResource::getUrl('view', ['record' => $analysis]) }}" class="da-link" style="font-size: 0.875rem;">完整報告與歷史 →</a>
        </x-slot>

        <div class="fi-prose" style="max-width: none;">
            {!! \Illuminate\Support\Str::markdown($analysis->body, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
        </div>
    @else
        <p class="da-muted" style="font-size: 0.875rem;">還沒有分析。下一個工作日早上會自動產生；要馬上看，在 Claude 裡說「跑一次開發活動分析」。</p>
    @endif
</x-filament::section>
