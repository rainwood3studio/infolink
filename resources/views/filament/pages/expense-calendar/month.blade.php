@php
    use App\Domain\Finance\ExpenseCalendar as Calendar;
    use App\Filament\Pages\ExpenseCalendar;

    $items = $month['items'];
@endphp

<x-filament::section compact>
    <x-slot name="heading">
        <div class="ec-month-head">
            <span>{{ ExpenseCalendar::monthLabel($month['month']) }}</span>
            <span class="ec-num" style="font-size: 0.875rem; font-weight: 500;">
                @if ($items === [])
                    <span class="ec-muted">沒有到期的支出</span>
                @else
                    額外 <strong>{{ ExpenseCalendar::money($month['extra_outflow']) }}</strong>
                    @if ($month['in_baseline_outflow'] > 0)
                        <span class="ec-muted">，已含月成本 {{ ExpenseCalendar::money($month['in_baseline_outflow']) }}</span>
                    @endif
                @endif
            </span>
        </div>
    </x-slot>

    @if ($items !== [])
        <div style="overflow-x: auto;">
            <table class="ec-table ec-num">
                <thead>
                    <tr>
                        <th scope="col">日期</th>
                        <th scope="col">項目</th>
                        <th scope="col">類別</th>
                        <th scope="col" class="ec-end">金額</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr wire:key="item-{{ $month['month'] }}-{{ $item['kind'] }}-{{ $item['id'] }}-{{ $item['date'] }}" @class(['ec-today' => $item['date'] === $today])>
                            <td>{{ ExpenseCalendar::dateLabel($item['date']) }}@if ($item['date'] === $today) <span class="ec-muted" style="font-size: 0.75rem;">今天</span>@endif</td>
                            <td class="ec-name">
                                @if ($item['kind'] === Calendar::KIND_RECURRING)
                                    <a class="ec-link" href="{{ $editUrl($item['id']) }}">{{ $item['name'] }}</a>
                                @else
                                    {{ $item['name'] }}
                                @endif
                                @if ($item['in_baseline'])
                                    <span class="ec-badges"><x-filament::badge color="gray" size="sm" class="ec-badge">已含月成本</x-filament::badge></span>
                                @endif
                            </td>
                            <td>
                                @if ($item['kind'] === Calendar::KIND_PLANNED)
                                    <x-filament::badge color="info" size="sm" class="ec-badge">已排定</x-filament::badge>
                                @else
                                    <x-filament::badge :color="\App\Enums\ExpenseCategory::from($item['category'])->getColor()" size="sm" class="ec-badge">{{ $item['category_label'] }}</x-filament::badge>
                                @endif
                            </td>
                            <td @class(['ec-end', 'ec-covered' => $item['in_baseline']])>{{ ExpenseCalendar::money($item['amount']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>
