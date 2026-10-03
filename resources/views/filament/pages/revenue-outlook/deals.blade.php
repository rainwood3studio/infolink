@php
    use App\Domain\Finance\RevenueOutlook as Outlook;
    use App\Enums\DealStage;
    use App\Filament\Pages\ClosingProjects;
    use App\Filament\Pages\RevenueOutlook;
    use App\Filament\Resources\Deals\DealResource;

    $deals = $outlook['deals'];
    $summary = $outlook['summary'];
    $uncounted = $summary['open_deals'] - $summary['counted_deals'];
    $incomplete = array_filter($deals, fn (array $deal): bool => $deal['missing'] !== []);
@endphp

<x-filament::section compact>
    <x-slot name="heading">業務機會</x-slot>
    <x-slot name="description">有金額和預計成交日的機會才排得進圖裡。加權金額＝金額 × 成交機率，未稅。</x-slot>
    <x-slot name="afterHeader">
        <a href="{{ DealResource::getUrl('index') }}" class="ro-link" style="font-size: 0.875rem;">全部業務機會 →</a>
    </x-slot>

    @if ($deals === [])
        <x-filament::empty-state
            heading="目前沒有進行中的業務機會"
            description="每月缺口要靠新生意補。新增機會並填上金額與預計成交日，就會出現在上面的圖裡。"
            :icon="\Filament\Support\Icons\Heroicon::OutlinedBriefcase"
        />
    @else
        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
            <div style="font-size: 0.875rem;">
                {{ $summary['open_deals'] }} 個進行中的機會，{{ $summary['counted_deals'] }} 個算得進去，加權合計 <span class="ro-num" style="font-weight: 600;">{{ RevenueOutlook::money($summary['pipeline_weighted_total']) }}</span>。@if ($uncounted > 0)<span class="ro-warn" style="font-weight: 600;">另外 {{ $uncounted }} 個算不進去，補齊下面標出來的欄位才看得到它們補了多少缺口。</span>@endif
            </div>

            <div style="overflow-x: auto;">
                <table class="ro-table ro-list">
                    <thead>
                        <tr>
                            <th scope="col">機會</th>
                            <th scope="col">階段</th>
                            <th scope="col" class="ro-end">金額（未稅）</th>
                            <th scope="col" class="ro-end">機率</th>
                            <th scope="col" class="ro-end">加權</th>
                            <th scope="col">預計成交</th>
                            <th scope="col">下一步</th>
                            <th scope="col">缺什麼</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($deals as $deal)
                            <tr wire:key="deal-{{ $deal['id'] }}">
                                <td class="ro-wrap">
                                    <a href="{{ DealResource::getUrl('edit', ['record' => $deal['id']]) }}" class="ro-link" style="font-weight: 600;">{{ $deal['title'] }}</a>
                                    <div class="ro-muted" style="font-size: 0.75rem;">{{ $deal['party'] }}</div>
                                </td>
                                <td><span class="ro-badge"><x-filament::badge :color="DealStage::from($deal['stage'])->getColor()" size="sm">{{ $deal['stage_label'] }}</x-filament::badge></span></td>
                                <td class="ro-end ro-num">
                                    @if ($deal['amount_untaxed'])
                                        {{ RevenueOutlook::money($deal['amount_untaxed']) }}
                                    @else
                                        <span class="ro-muted">—</span>
                                    @endif
                                    @if ($deal['recurring_monthly'])
                                        <div class="ro-muted" style="font-size: 0.75rem;">＋每月 {{ RevenueOutlook::money($deal['recurring_monthly']) }}</div>
                                    @endif
                                </td>
                                <td class="ro-end ro-num">{{ $deal['probability'] }}%</td>
                                <td class="ro-end ro-num" style="font-weight: 600;">
                                    @if ($deal['amount_untaxed'])
                                        {{ RevenueOutlook::money($deal['weighted']) }}
                                    @else
                                        <span class="ro-muted" style="font-weight: 400;">—</span>
                                    @endif
                                </td>
                                <td class="ro-num">
                                    @if ($deal['expected_close_on'])
                                        {{ ClosingProjects::shortDate($deal['expected_close_on']) }}
                                    @else
                                        <span class="ro-muted">—</span>
                                    @endif
                                </td>
                                <td class="ro-wrap">
                                    @if ($deal['next_action'] || $deal['next_action_on'])
                                        {{ $deal['next_action'] ?? '（沒寫要做什麼）' }}
                                        @if ($deal['next_action_on'])
                                            <span class="ro-muted ro-num" style="font-size: 0.75rem;">{{ ClosingProjects::shortDate($deal['next_action_on']) }}</span>
                                        @endif
                                    @else
                                        <span class="ro-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <span style="display: inline-flex; flex-wrap: wrap; gap: 0.25rem;">
                                        @foreach ($deal['missing'] as $field)
                                            <span class="ro-badge"><x-filament::badge color="warning" size="sm">缺{{ $field }}</x-filament::badge></span>
                                        @endforeach
                                        @if ($deal['not_counted_reason'] === Outlook::NOT_COUNTED_CLOSE_DATE_PASSED)
                                            <span class="ro-badge"><x-filament::badge color="danger" size="sm">預計成交日已過</x-filament::badge></span>
                                        @elseif ($deal['not_counted_reason'] === Outlook::NOT_COUNTED_BEYOND_HORIZON)
                                            <span class="ro-badge"><x-filament::badge color="gray" size="sm">超出展望期</x-filament::badge></span>
                                        @elseif ($deal['counted'])
                                            <span class="ro-badge"><x-filament::badge color="success" size="sm">已計入</x-filament::badge></span>
                                        @endif
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($incomplete !== [])
                <div class="ro-muted" style="font-size: 0.75rem;">沒有金額或預計成交日的機會排不進任何一個月；沒有下一步日期的機會不影響計算，但代表沒有人在推。點機會名稱去補。</div>
            @endif
        </div>
    @endif
</x-filament::section>
