<x-filament-panels::page>
    <style>
        .aq-body[data-loading] { opacity: 0.5; transition: opacity 150ms; }
        .aq-muted { opacity: 0.65; }
        .aq-num { font-variant-numeric: tabular-nums; }
        .aq-link { color: var(--primary-600); text-decoration: none; }
        .aq-link:hover { text-decoration: underline; }
        .dark .aq-link { color: var(--primary-400); }
        .aq-warn { color: var(--warning-600); }
        .dark .aq-warn { color: var(--warning-400); }
        .aq-track { flex: 1 1 auto; min-width: 2.5rem; height: 0.5rem; border-radius: 9999px; background: color-mix(in oklab, var(--gray-400) 18%, transparent); overflow: hidden; }
        .aq-bar { height: 100%; border-radius: 9999px; }
        .aq-bar-accepted { background: var(--primary-500); }
        .aq-bar-handed { background: color-mix(in oklab, var(--gray-500) 70%, transparent); }
        .aq-bar-commits { background: color-mix(in oklab, var(--gray-500) 40%, transparent); }
        .aq-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .aq-table th { padding: 0.375rem 0.75rem 0.375rem 0; font-size: 0.75rem; font-weight: 500; text-align: left; white-space: nowrap; opacity: 0.65; }
        .aq-table td { padding: 0.4375rem 0.75rem 0.4375rem 0; vertical-align: baseline; border-top: 1px solid color-mix(in oklab, var(--gray-500) 15%, transparent); }
        .aq-table th:last-child, .aq-table td:last-child { padding-right: 0; }
        .aq-project { display: block; width: 100%; padding: 0.375rem 0.5rem; margin: 0 -0.5rem; border-radius: 0.5rem; text-align: left; cursor: pointer; color: inherit; background: transparent; }
        .aq-project:hover { background: color-mix(in oklab, var(--gray-500) 10%, transparent); }
        .aq-project[aria-pressed="true"] { background: color-mix(in oklab, var(--primary-500) 12%, transparent); }
    </style>

    @if (! $hasData)
        <x-filament::section>
            <x-filament::empty-state
                heading="還沒有 Redmine 議題"
                description="同步 Redmine 之後，這裡會列出等待驗收的議題。"
                :icon="\Filament\Support\Icons\Heroicon::OutlinedClipboardDocumentCheck"
            />
        </x-filament::section>
    @else
        <div class="aq-body" wire:loading.delay.attr="data-loading" style="display: flex; flex-direction: column; gap: 1.5rem;">
            @include('filament.pages.acceptance.stats')

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 26rem), 1fr)); gap: 1.5rem; align-items: start;">
                <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                    @include('filament.pages.acceptance.flow')
                    @include('filament.pages.acceptance.age')
                </div>
                @include('filament.pages.acceptance.projects')
            </div>

            @include('filament.pages.acceptance.queue')
            @include('filament.pages.acceptance.off-flow')

            @if ($syncedAt)
                <p class="aq-muted" style="font-size: 0.75rem;">Redmine 議題最後同步：{{ $syncedAt->format('Y-m-d H:i') }}（{{ $syncedAt->diffForHumans() }}）。</p>
            @endif
        </div>
    @endif
</x-filament-panels::page>
