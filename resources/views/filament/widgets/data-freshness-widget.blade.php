<x-filament-widgets::widget>
    <x-filament::section compact wire:poll.60s>
        <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem 1.5rem; font-size: 0.875rem;">
            <span style="font-weight: 600;">資料新鮮度</span>
            @foreach ($this->getSources() as $source)
                <span style="display: inline-flex; flex-wrap: wrap; align-items: center; gap: 0.375rem;">
                    <span style="opacity: 0.7;">{{ $source['label'] }}</span>
                    <x-filament::badge :color="$source['color']" :icon="$source['icon']">{{ $source['value'] }}</x-filament::badge>
                    @if ($source['hint'])
                        <span style="opacity: 0.6; font-size: 0.8125rem;">（{{ $source['hint'] }}）</span>
                    @endif
                </span>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
