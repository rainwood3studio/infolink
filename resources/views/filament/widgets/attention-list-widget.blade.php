@php($items = $this->getItems())

<x-filament-widgets::widget>
    <x-filament::section heading="今天要處理" icon="heroicon-o-bell-alert" :description="$items->isEmpty() ? null : $items->count() . ' 項'">
        @forelse ($items as $item)
            <div wire:key="{{ $item->type }}-{{ $item->model->getKey() }}" style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem; padding: 0.625rem 0; {{ $loop->last ? '' : 'border-bottom: 1px solid rgb(128 128 128 / 0.15);' }}">
                <x-filament::badge :color="$item->color">{{ $item->badge }}</x-filament::badge>

                <span style="flex: 1 1 16rem; min-width: 0;">
                    @if (! $item->isInsight())
                        <x-filament::icon icon="heroicon-o-check-circle" style="display: inline-block; width: 1rem; height: 1rem; vertical-align: -0.15em; opacity: 0.6;" />
                    @endif
                    {{ $item->title }}
                    @if ($item->dueOn)
                        <span style="opacity: 0.6; font-size: 0.875em;">（{{ $item->dueOn->format('m/d') }} 到期）</span>
                    @endif
                </span>

                <span style="display: flex; gap: 0.5rem;">
                    @if ($item->isInsight())
                        @if ($item->model->status === \App\Enums\InsightStatus::Open)
                            <x-filament::button size="xs" color="gray" wire:click="acknowledgeInsight({{ $item->model->getKey() }})">確認</x-filament::button>
                        @endif
                        <x-filament::button size="xs" color="gray" wire:click="createActionItemFromInsight({{ $item->model->getKey() }})">建立待辦</x-filament::button>
                        <x-filament::button size="xs" color="success" wire:click="resolveInsight({{ $item->model->getKey() }})">已處理</x-filament::button>
                    @else
                        <x-filament::button size="xs" color="success" wire:click="completeActionItem({{ $item->model->getKey() }})">完成</x-filament::button>
                    @endif
                </span>
            </div>
        @empty
            <x-filament::empty-state heading="目前沒有需要處理的事" icon="heroicon-o-check-badge" :contained="false" />
        @endforelse
    </x-filament::section>
</x-filament-widgets::widget>
