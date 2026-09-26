@php
    $columns = $this->openColumns();
    $closedColumns = $this->closedColumns();
    $dropTarget = fn (string $stage): string => "\$wire.moveDeal(parseInt(\$event.dataTransfer.getData('text/plain')), '{$stage}')";
@endphp

<x-filament-panels::page>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(17rem, 1fr)); gap: 1rem; align-items: start;">
        @foreach ($columns as $column)
            <div
                wire:key="board-column-{{ $column['stage']->value }}"
                x-data="{ over: false }"
                x-on:dragover.prevent="over = true"
                x-on:dragleave="over = false"
                x-on:drop.prevent="over = false; {{ $dropTarget($column['stage']->value) }}"
                x-bind:style="over ? 'outline: 2px dashed rgb(128 128 128 / 0.5); outline-offset: 4px; border-radius: 0.75rem;' : ''"
            >
                <x-filament::section compact>
                    <x-slot name="heading">
                        <span style="display: inline-flex; align-items: center; gap: 0.5rem;">
                            <x-filament::badge :color="$column['stage']->getColor()">{{ $column['stage']->getLabel() }}</x-filament::badge>
                            <span style="opacity: 0.7; font-weight: 400;">{{ $column['deals']->count() }} 件</span>
                        </span>
                    </x-slot>
                    <x-slot name="description">
                        加權 {{ \App\Filament\Support\Money::format($column['weighted']) }}（金額 {{ \App\Filament\Support\Money::format($column['amount']) }}）
                    </x-slot>

                    <div style="display: flex; flex-direction: column; gap: 0.625rem;">
                        @forelse ($column['deals'] as $deal)
                            @php($needsNextAction = \App\Filament\Resources\Deals\DealResource::needsNextAction($deal))
                            <div
                                wire:key="deal-card-{{ $deal->id }}"
                                draggable="true"
                                x-on:dragstart="$event.dataTransfer.setData('text/plain', '{{ $deal->id }}')"
                                style="border: 1px solid rgb(128 128 128 / 0.25); border-radius: 0.5rem; padding: 0.625rem 0.75rem; cursor: grab;"
                            >
                                <div style="font-size: 0.8125rem; opacity: 0.7;">{{ $deal->party_name }}</div>
                                <div style="font-weight: 600; margin: 0.125rem 0 0.375rem;">
                                    <x-filament::link :href="\App\Filament\Resources\Deals\DealResource::getUrl('edit', ['record' => $deal])" color="gray">
                                        {{ $deal->title }}
                                    </x-filament::link>
                                </div>
                                <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.25rem 0.75rem; font-size: 0.875rem;">
                                    <span>加權 {{ \App\Filament\Support\Money::format($deal->weighted_amount) }}</span>
                                    <span style="opacity: 0.6;">{{ $deal->probability }}%</span>
                                </div>
                                <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.375rem; margin-top: 0.375rem; font-size: 0.8125rem;">
                                    @if (blank($deal->next_action))
                                        <x-filament::badge color="danger" size="sm">沒有下一步</x-filament::badge>
                                    @else
                                        <span>{{ $deal->next_action }}</span>
                                    @endif
                                    @if ($deal->next_action_on === null)
                                        @if (filled($deal->next_action))
                                            <x-filament::badge color="danger" size="sm">未排日期</x-filament::badge>
                                        @endif
                                    @elseif ($needsNextAction)
                                        <x-filament::badge color="danger" size="sm">{{ $deal->next_action_on->format('m/d') }} 已過</x-filament::badge>
                                    @else
                                        <span style="opacity: 0.6;">{{ $deal->next_action_on->format('m/d') }}</span>
                                    @endif
                                </div>
                                <div style="display: flex; justify-content: flex-end; margin-top: 0.25rem;">
                                    {{ $this->moveStageAction->arguments(['deal' => $deal->id]) }}
                                </div>
                            </div>
                        @empty
                            <div style="padding: 1rem 0; text-align: center; opacity: 0.5; font-size: 0.875rem;">沒有業務機會</div>
                        @endforelse
                    </div>
                </x-filament::section>
            </div>
        @endforeach
    </div>

    <div style="display: flex; flex-wrap: wrap; gap: 1rem;">
        @foreach ($closedColumns as $closed)
            <a
                href="{{ $closed['url'] }}"
                wire:key="board-closed-{{ $closed['stage']->value }}"
                x-data
                x-on:dragover.prevent
                x-on:drop.prevent="{{ $dropTarget($closed['stage']->value) }}"
                style="flex: 1 1 14rem;"
            >
                <x-filament::section compact>
                    <span style="display: inline-flex; align-items: center; gap: 0.5rem;">
                        <x-filament::badge :color="$closed['stage']->getColor()">{{ $closed['stage']->getLabel() }}</x-filament::badge>
                        <span>{{ $closed['count'] }} 件</span>
                        <span style="opacity: 0.6; font-size: 0.875rem;">{{ \App\Filament\Support\Money::format($closed['amount']) }}</span>
                    </span>
                </x-filament::section>
            </a>
        @endforeach
    </div>
</x-filament-panels::page>
