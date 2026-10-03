@php
    use App\Enums\ProjectStatus;
    use App\Filament\Pages\ProjectPnl;
    use App\Filament\Resources\CostBaselines\CostBaselineResource;
    use App\Filament\Resources\Projects\ProjectResource;

    $projects = $pnl['projects'];
    $maxDays = max(0.1, ...array_column($projects, 'person_days'));
    $missing = $pnl['missing'];
    $names = fn (array $rows): string => implode('、', array_column($rows, 'name'));
@endphp

<x-filament::section compact>
    <x-slot name="heading">依專案</x-slot>
    <x-slot name="description">金額都是未稅。期間收入＝預計收款日落在這段期間的應收（含每月維運費，不管收到了沒）；估算差額＝期間收入 − 估算成本，這段期間沒有應收就不算。</x-slot>

    @if ($projects === [])
        <span class="pp-muted" style="font-size: 0.875rem;">這段期間的 commit 都還沒對應到專案。到「設定 → GitHub repo」把 repo 指定給專案。</span>
    @else
        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
            <div style="overflow-x: auto;">
                <table class="pp-table">
                    <thead>
                        <tr>
                            <th scope="col">專案</th>
                            <th scope="col">投入人天</th>
                            <th scope="col">誰（人天）</th>
                            <th scope="col" class="pp-end">估算成本</th>
                            <th scope="col" class="pp-end">期間收入</th>
                            <th scope="col" class="pp-end">估算差額</th>
                            <th scope="col" class="pp-end">合約金額</th>
                            <th scope="col" class="pp-end">已收／未收</th>
                            <th scope="col" class="pp-end">Redmine 工時</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($projects as $project)
                            @php $editUrl = ProjectResource::getUrl('edit', ['record' => $project['id']]); @endphp
                            <tr wire:key="pp-project-{{ $project['id'] }}">
                                <td class="pp-wrap">
                                    <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.25rem 0.5rem;">
                                        <a href="{{ $editUrl }}" class="pp-link" style="font-weight: 600;">{{ $project['name'] }}</a>
                                        <span class="pp-badge"><x-filament::badge :color="ProjectStatus::from($project['status'])->getColor()" size="sm">{{ $project['status_label'] }}</x-filament::badge></span>
                                    </div>
                                    @if ($project['customer'])
                                        <div class="pp-sub">{{ $project['customer'] }}</div>
                                    @endif
                                </td>
                                <td class="pp-num" title="{{ $project['commits'] }} 筆 commit：{{ implode('、', array_map(fn (array $repo): string => $repo['full_name'].' '.$repo['commits'], $project['repos'])) }}">
                                    @if ($project['commits'] > 0)
                                        <span style="font-weight: 600;">{{ ProjectPnl::days($project['person_days']) }}</span>
                                        <span class="pp-sub">{{ ProjectPnl::percent($project['share']) }}</span>
                                        <div class="pp-mini"><div class="pp-fill pp-fill-effort" style="width: {{ round($project['person_days'] / $maxDays * 100, 2) }}%;"></div></div>
                                    @else
                                        <span class="pp-muted">沒有 commit</span>
                                    @endif
                                </td>
                                <td class="pp-wrap pp-num">
                                    @if ($project['by_person'] === [])
                                        <span class="pp-muted">—</span>
                                    @else
                                        {{ ProjectPnl::peopleLine($project['by_person']) }}
                                    @endif
                                </td>
                                <td class="pp-end pp-num">
                                    @if ($project['estimated_cost'] === null)
                                        <span class="pp-muted">—</span>
                                    @else
                                        {{ ProjectPnl::money($project['estimated_cost']) }}
                                    @endif
                                </td>
                                <td class="pp-end pp-num">
                                    @if ($project['revenue_in_period'] > 0)
                                        {{ ProjectPnl::money($project['revenue_in_period']) }}
                                    @else
                                        <span class="pp-muted" title="這段期間沒有預計收款的應收">—</span>
                                    @endif
                                    @if ($project['recurring_monthly'])
                                        <div class="pp-sub" title="每月維運費：最近一個有登記的月份的經常性應收">維運費 {{ ProjectPnl::money($project['recurring_monthly']) }}／月</div>
                                    @endif
                                </td>
                                <td class="pp-end pp-num">
                                    @if ($project['margin_estimate'] === null)
                                        <span class="pp-muted" title="{{ $project['estimated_cost'] === null ? '沒有月成本可以估算' : '這段期間沒有應收，不估差額' }}">—</span>
                                    @else
                                        <span @class(['pp-danger' => $project['margin_estimate'] < 0]) style="font-weight: 600;">{{ ProjectPnl::money($project['margin_estimate'], signed: true) }}</span>
                                        @if ($project['margin_estimate'] < 0)
                                            <div class="pp-sub pp-danger" style="opacity: 1;">收入蓋不過投入</div>
                                        @endif
                                    @endif
                                </td>
                                <td class="pp-end pp-num">
                                    @if ($project['contract_amount_untaxed'] === null)
                                        <a href="{{ $editUrl }}" class="pp-badge" title="到專案頁補上合約金額"><x-filament::badge color="warning" size="sm">未填</x-filament::badge></a>
                                    @else
                                        {{ ProjectPnl::money($project['contract_amount_untaxed']) }}
                                    @endif
                                </td>
                                <td class="pp-end pp-num" title="未收含稅 {{ ProjectPnl::money($project['outstanding_taxed']) }}；這段期間收到 {{ ProjectPnl::money($project['received_in_period']) }}">
                                    @if ($project['received_total'] === 0 && $project['outstanding_untaxed'] === 0)
                                        <span class="pp-muted">沒有應收紀錄</span>
                                    @else
                                        <div>已收 {{ ProjectPnl::money($project['received_total']) }}</div>
                                        <div class="pp-sub">未收 {{ ProjectPnl::money($project['outstanding_untaxed']) }}</div>
                                    @endif
                                </td>
                                <td class="pp-end pp-num">
                                    @if ($project['redmine_linked'])
                                        {{ rtrim(rtrim(number_format($project['redmine_hours'], 1), '0'), '.') }} h
                                    @else
                                        <span class="pp-muted" title="專案沒有連結 Redmine 專案">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pp-muted" style="display: flex; flex-direction: column; gap: 0.125rem; font-size: 0.75rem;">
                @if ($missing['cost_baseline'])
                    <span class="pp-warn" style="opacity: 1;">還沒有月成本基準，所以估算成本和估算差額都是空的。<a href="{{ CostBaselineResource::getUrl('index') }}" class="pp-link">到成本基準設定</a></span>
                @else
                    <span>估算成本＝月成本 {{ ProjectPnl::money($pnl['monthly_cost']) }} × {{ $pnl['period']['days'] }}／30 天＝{{ ProjectPnl::money($pnl['period_cost']) }}，再依人天比例攤。沒有每個人的成本，驗收、開會、業務這些不留 commit 的工作也看不到，所以只能當方向參考。</span>
                @endif
                <span>Redmine 工時只有少數人在填，只供參考。</span>
                @if ($missing['contract_amount'] !== [])
                    <span>{{ count($missing['contract_amount']) }} 個有投入的專案沒填合約金額：{{ $names($missing['contract_amount']) }}。點「未填」去補。</span>
                @endif
                @if ($missing['redmine_link'] !== [])
                    <span>{{ count($missing['redmine_link']) }} 個有投入的專案沒連結 Redmine 專案，看不到工時：{{ $names($missing['redmine_link']) }}。</span>
                @endif
                @if ($pnl['omitted_projects'] > 0)
                    <span>另外 {{ $pnl['omitted_projects'] }} 個專案這段期間沒有投入也沒有款項，沒有列出來。</span>
                @endif
            </div>
        </div>
    @endif
</x-filament::section>
