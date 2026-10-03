@php
    use App\Enums\DealStage;
    use App\Filament\Pages\ProjectPnl;
    use App\Filament\Resources\Deals\DealResource;
@endphp

<x-filament::section compact>
    <x-slot name="heading">尚未簽約的投入</x-slot>
    <x-slot name="description">已經在做、但還只是業務機會的工作。這些人天目前沒有任何合約會付錢。</x-slot>
    <x-slot name="afterHeader">
        <a href="{{ DealResource::getUrl('index') }}" class="pp-link" style="font-size: 0.875rem;">全部業務機會 →</a>
    </x-slot>

    @if ($pnl['presales'] === [])
        <span class="pp-muted" style="font-size: 0.875rem;">這段期間沒有投入在還沒簽約的機會上。替潛在客戶做的 repo，到「設定 → GitHub repo」指定給業務機會就會列在這裡。</span>
    @else
        <div style="overflow-x: auto;">
            <table class="pp-table">
                <thead>
                    <tr>
                        <th scope="col">業務機會</th>
                        <th scope="col">階段</th>
                        <th scope="col">投入人天</th>
                        <th scope="col">誰（人天）</th>
                        <th scope="col" class="pp-end">估算成本</th>
                        <th scope="col">Repo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($pnl['presales'] as $deal)
                        <tr wire:key="pp-deal-{{ $deal['deal_id'] }}">
                            <td class="pp-wrap">
                                <a href="{{ DealResource::getUrl('edit', ['record' => $deal['deal_id']]) }}" class="pp-link" style="font-weight: 600;">{{ $deal['title'] }}</a>
                                <div class="pp-sub">{{ $deal['party'] }}</div>
                            </td>
                            <td><span class="pp-badge"><x-filament::badge :color="DealStage::from($deal['stage'])->getColor()" size="sm">{{ $deal['stage_label'] }}</x-filament::badge></span></td>
                            <td class="pp-num" title="{{ $deal['commits'] }} 筆 commit">
                                <span style="font-weight: 600;">{{ ProjectPnl::days($deal['person_days']) }}</span>
                                <span class="pp-sub">{{ ProjectPnl::percent($deal['share']) }}</span>
                            </td>
                            <td class="pp-wrap pp-num">{{ ProjectPnl::peopleLine($deal['by_person']) }}</td>
                            <td class="pp-end pp-num">
                                @if ($deal['estimated_cost'] === null)
                                    <span class="pp-muted">—</span>
                                @else
                                    {{ ProjectPnl::money($deal['estimated_cost']) }}
                                @endif
                            </td>
                            <td class="pp-wrap">{{ implode('、', array_column($deal['repos'], 'full_name')) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>
