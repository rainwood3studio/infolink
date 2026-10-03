@php
    use App\Filament\Pages\ClosingProjects;
    use App\Filament\Pages\RevenueOutlook;

    $closingReceivables = $outlook['closing_receivables'];
    $closingTotal = array_sum(array_column($closingReceivables, 'amount_taxed'));
    $atRisk = array_filter($closingReceivables, fn (array $row): bool => $row['at_risk']);
    $atRiskTotal = array_sum(array_column($atRisk, 'amount_taxed'));
@endphp

<x-filament::section compact>
    <x-slot name="heading">這些尾款掛在還沒結案的專案上</x-slot>
    <x-slot name="description">專案沒結案，款就收不到。想知道晚收會怎樣，用最上面的「尾款延後」。</x-slot>

    @if ($closingReceivables === [])
        <span class="ro-muted" style="font-size: 0.875rem;">目前沒有掛在結案中專案上的未收款。</span>
    @else
        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
            <div style="font-size: 0.875rem;">
                共 {{ count($closingReceivables) }} 筆 <span class="ro-num" style="font-weight: 600;">{{ RevenueOutlook::money($closingTotal) }}</span>（含稅）@if ($atRisk !== [])，<span class="ro-danger" style="font-weight: 600;">其中 {{ count($atRisk) }} 筆 <span class="ro-num">{{ RevenueOutlook::money($atRiskTotal) }}</span> 的專案照目前速度結不完或會晚。</span>@else，照目前速度都趕得上，或還無法推估。@endif
            </div>

            <div style="overflow-x: auto;">
                <table class="ro-table ro-list">
                    <thead>
                        <tr>
                            <th scope="col">款項</th>
                            <th scope="col" class="ro-end">金額（含稅）</th>
                            <th scope="col">預計收款</th>
                            <th scope="col">專案</th>
                            <th scope="col">照現在的速度</th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($closingReceivables as $row)
                            @php
                                $project = $row['project'];
                                $projection = ClosingProjects::projectionText($project);
                            @endphp
                            <tr wire:key="closing-receivable-{{ $row['id'] }}">
                                <td>
                                    <span style="display: inline-flex; align-items: center; gap: 0.375rem;">
                                        <span>{{ $row['label'] }}</span>
                                        @if ($row['confidence'] === 'low')
                                            <span class="ro-badge"><x-filament::badge color="warning" size="sm">低確定性</x-filament::badge></span>
                                        @endif
                                    </span>
                                </td>
                                <td class="ro-end ro-num" style="font-weight: 600;">{{ RevenueOutlook::money($row['amount_taxed']) }}</td>
                                <td class="ro-num">
                                    {{ ClosingProjects::shortDate($row['expected_on']) }}
                                    @if ($row['is_overdue'])
                                        <span class="ro-danger">已逾期</span>
                                    @endif
                                </td>
                                <td>
                                    {{ $project['name'] }}
                                    <span class="ro-muted ro-num" style="font-size: 0.75rem;">
                                        @if ($project['days_left'] === null)
                                            未設定目標日
                                        @elseif ($project['days_left'] < 0)
                                            已過目標日 {{ abs($project['days_left']) }} 天
                                        @else
                                            離目標日 {{ $project['days_left'] }} 天
                                        @endif
                                        ・未結 {{ number_format($project['open']) }} 張
                                    </span>
                                </td>
                                <td class="ro-wrap">
                                    <span @class(['ro-danger' => $projection['color'] === 'danger', 'ro-muted' => $projection['color'] === 'gray']) @style(['font-weight: 600' => $projection['color'] === 'danger'])>{{ $projection['text'] }}</span>
                                </td>
                                <td class="ro-end">
                                    <a href="{{ ClosingProjects::getUrl(['project' => $project['id']]) }}" class="ro-link">結案作戰 →</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-filament::section>
