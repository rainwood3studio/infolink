@php
    $items = $this->getItems();
    $delegatedItems = $this->getDelegatedItems();
    $delegatedOverdue = $delegatedItems->filter->isOverdue()->count();
    $postponeOptions = $this->getPostponeOptions();
    $delegates = $this->getDelegates();
    $ownerName = \App\Models\ActionItem::ownerName();
    $rowStyle = 'display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem; padding: 0.625rem 0;';
    $rowBorder = 'border-bottom: 1px solid rgb(128 128 128 / 0.15);';
    $mutedStyle = 'opacity: 0.6; font-size: 0.875em;';
    $actionsStyle = 'display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem;';
@endphp

<x-filament-widgets::widget>
    <div style="display: grid; gap: 1.5rem;">
        <x-filament::section heading="今天要處理" icon="heroicon-o-bell-alert" :description="$items->isEmpty() ? null : $items->count() . ' 項'">
            @forelse ($items as $item)
                <div wire:key="{{ $item->type }}-{{ $item->model->getKey() }}" style="{{ $rowStyle }} {{ $loop->last ? '' : $rowBorder }}">
                    <x-filament::badge :color="$item->color">{{ $item->badge }}</x-filament::badge>

                    <span style="flex: 1 1 16rem; min-width: 0;">
                        @if (! $item->isInsight())
                            <x-filament::icon icon="heroicon-o-check-circle" style="display: inline-block; width: 1rem; height: 1rem; vertical-align: -0.15em; opacity: 0.6;" />
                        @endif
                        {{ $item->title }}
                        @if ($item->dueOn)
                            <span style="{{ $mutedStyle }}">（{{ $item->dueOn->format('m/d') }} 到期 · {{ filled($item->model->owner) ? $item->model->owner : $ownerName }}）</span>
                        @endif
                    </span>

                    <span style="{{ $actionsStyle }}">
                        @if ($item->isInsight())
                            @if ($item->model->status === \App\Enums\InsightStatus::Open)
                                <x-filament::button size="xs" color="gray" wire:click="acknowledgeInsight({{ $item->model->getKey() }})">確認</x-filament::button>
                            @endif
                            <x-filament::button size="xs" color="gray" wire:click="createActionItemFromInsight({{ $item->model->getKey() }})">建立待辦</x-filament::button>
                            <x-filament::button size="xs" color="success" wire:click="resolveInsight({{ $item->model->getKey() }})">已處理</x-filament::button>
                        @else
                            <x-filament::button size="xs" color="success" wire:click="completeActionItem({{ $item->model->getKey() }})">完成</x-filament::button>

                            <x-filament::dropdown placement="bottom-end">
                                <x-slot name="trigger">
                                    <x-filament::button size="xs" color="gray" icon="heroicon-m-chevron-down" icon-position="after">延後</x-filament::button>
                                </x-slot>

                                <x-filament::dropdown.list>
                                    @foreach ($postponeOptions as $option)
                                        <x-filament::dropdown.list.item wire:click="postponeActionItem({{ $item->model->getKey() }}, '{{ $option['value'] }}')" x-on:click="close">
                                            {{ $option['label'] }}
                                            <span style="{{ $mutedStyle }}">{{ $option['date'] }}</span>
                                        </x-filament::dropdown.list.item>
                                    @endforeach
                                </x-filament::dropdown.list>
                            </x-filament::dropdown>

                            @if ($delegates !== [])
                                <x-filament::dropdown placement="bottom-end">
                                    <x-slot name="trigger">
                                        <x-filament::button size="xs" color="gray" icon="heroicon-m-chevron-down" icon-position="after">交辦</x-filament::button>
                                    </x-slot>

                                    <x-filament::dropdown.list>
                                        @foreach ($delegates as $delegate)
                                            <x-filament::dropdown.list.item wire:click="delegateActionItem({{ $item->model->getKey() }}, {{ \Illuminate\Support\Js::from($delegate) }})" x-on:click="close">
                                                {{ $delegate }}
                                            </x-filament::dropdown.list.item>
                                        @endforeach
                                    </x-filament::dropdown.list>
                                </x-filament::dropdown>
                            @endif

                            <x-filament::button size="xs" color="gray" wire:click="dropActionItem({{ $item->model->getKey() }})" wire:confirm="確定放棄「{{ $item->title }}」？">放棄</x-filament::button>
                        @endif
                    </span>
                </div>
            @empty
                <x-filament::empty-state heading="目前沒有需要處理的事" icon="heroicon-o-check-badge" :contained="false" />
            @endforelse
        </x-filament::section>

        @if ($delegatedItems->isNotEmpty())
            <x-filament::section heading="已交辦" icon="heroicon-o-user-group" :description="$delegatedItems->count() . ' 項' . ($delegatedOverdue > 0 ? '，' . $delegatedOverdue . ' 項逾期' : '')">
                @foreach ($delegatedItems as $item)
                    <div wire:key="delegated-{{ $item->model->getKey() }}" style="{{ $rowStyle }} {{ $loop->last ? '' : $rowBorder }}">
                        <x-filament::badge :color="$item->color">{{ $item->badge }}</x-filament::badge>
                        <x-filament::badge color="info" icon="heroicon-m-user">{{ $item->model->owner }}</x-filament::badge>

                        <span style="flex: 1 1 16rem; min-width: 0;">
                            {{ $item->title }}
                            @if ($item->dueOn)
                                <span style="{{ $mutedStyle }}">（{{ $item->dueOn->format('m/d') }} 到期）</span>
                            @endif
                        </span>

                        <span style="{{ $actionsStyle }}">
                            <x-filament::button size="xs" color="success" wire:click="completeActionItem({{ $item->model->getKey() }})">完成</x-filament::button>
                            <x-filament::button size="xs" color="gray" wire:click="takeBackActionItem({{ $item->model->getKey() }})">收回</x-filament::button>
                            <x-filament::button size="xs" color="gray" wire:click="dropActionItem({{ $item->model->getKey() }})" wire:confirm="確定放棄「{{ $item->title }}」？">放棄</x-filament::button>
                        </span>
                    </div>
                @endforeach
            </x-filament::section>
        @endif
    </div>
</x-filament-widgets::widget>
