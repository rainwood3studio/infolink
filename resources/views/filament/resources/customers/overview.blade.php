@php
    use App\Filament\Pages\RevenueOutlook;
    use App\Filament\Resources\Customers\Pages\ViewCustomer;
    use App\Filament\Resources\Deals\DealResource;
    use App\Filament\Resources\Projects\ProjectResource;
    use App\Filament\Resources\Receivables\ReceivableResource;

    $maintenance = $overview['maintenance'];
    $receivables = $overview['receivables'];
    $deposits = $overview['deposits'];
    $redmineUrl = rtrim((string) config('services.redmine.url'), '/');

    $maintenanceTile = match (true) {
        $maintenance === null => ['value' => '沒有', 'hint' => '到「編輯」填維護費、週期與起算日', 'tone' => null],
        $maintenance['is_ended'] => ['value' => ViewCustomer::money($maintenance['fee_untaxed']).'／'.$maintenance['per_label'], 'hint' => '已到期 '.abs($maintenance['days_until_end']).' 天（'.ViewCustomer::dateLabel($maintenance['until']).'）', 'tone' => 'danger'],
        $maintenance['is_expiring_soon'] => ['value' => ViewCustomer::money($maintenance['fee_untaxed']).'／'.$maintenance['per_label'], 'hint' => ($maintenance['days_until_end'] === 0 ? '今天到期' : $maintenance['days_until_end'].' 天後到期').'（'.ViewCustomer::dateLabel($maintenance['until']).'）', 'tone' => 'danger'],
        $maintenance['until'] !== null => ['value' => ViewCustomer::money($maintenance['fee_untaxed']).'／'.$maintenance['per_label'], 'hint' => '到 '.ViewCustomer::dateLabel($maintenance['until']).'（'.$maintenance['days_until_end'].' 天）', 'tone' => null],
        default => ['value' => ViewCustomer::money($maintenance['fee_untaxed']).'／'.$maintenance['per_label'], 'hint' => $maintenance['cycle_label'].'收費，'.ViewCustomer::dateLabel($maintenance['since']).' 起持續中', 'tone' => null],
    };

    $tiles = [
        [
            'label' => '12 個月入帳',
            'value' => ViewCustomer::money($deposits['total_12m']),
            'hint' => $deposits['last_on'] === null ? '還沒有對到這個客戶的入帳' : '最近 '.ViewCustomer::dateLabel($deposits['last_on']).' '.ViewCustomer::money($deposits['last_amount']).'（含稅，銀行實收）',
            'tone' => null,
        ],
        [
            'label' => '未收應收（含稅）',
            'value' => ViewCustomer::money($receivables['outstanding_taxed']),
            'hint' => $receivables['outstanding_count'].' 筆，未稅 '.ViewCustomer::money($receivables['outstanding_untaxed']),
            'tone' => null,
        ],
        [
            'label' => '逾期（含稅）',
            'value' => $receivables['overdue_taxed'] === 0 ? '沒有' : ViewCustomer::money($receivables['overdue_taxed']),
            'hint' => $receivables['overdue_taxed'] === 0 ? '每一筆都還沒到預計收款日' : $receivables['overdue_count'].' 筆已過預計收款日',
            'tone' => $receivables['overdue_taxed'] === 0 ? null : 'danger',
        ],
        ['label' => '維護合約', ...$maintenanceTile],
    ];

    // Mini bar chart of the last 12 months of deposits (same geometry as the 客戶收款 cards).
    $months = array_keys($deposits['by_month']);
    $count = max(1, count($months));
    $width = 960;
    $left = 52;
    $right = 12;
    $top = 10;
    $plot = 96;
    $bottom = $top + $plot;
    $band = ($width - $left - $right) / $count;
    $barWidth = round(min(28, $band * 0.6), 1);
    $centerX = fn (int $index): float => round($left + $band * ($index + 0.5), 1);
    $feeLine = $maintenance !== null && $maintenance['cycle'] === 'monthly' && ! $maintenance['is_ended'] ? $maintenance['fee_taxed'] : null;
    $max = max(1, max($deposits['by_month'] ?: [0]), $feeLine ?? 0);
    $step = RevenueOutlook::niceStep($max, 3);
    $hi = max(1, ceil($max / $step)) * $step;
    $y = fn (int|float $value): float => round($bottom - $value / $hi * $plot, 1);
@endphp

<x-filament-panels::page>
    <style>
        .co-root {
            --co-deposit: #1baf7a; --co-fee: #2a78d6;
            --co-danger: var(--danger-600); --co-warn: var(--warning-600); --co-link: var(--primary-600);
            --co-rule: color-mix(in oklab, var(--gray-500) 18%, transparent);
        }
        .dark .co-root { --co-deposit: #199e70; --co-fee: #3987e5; --co-danger: var(--danger-400); --co-warn: var(--warning-400); --co-link: var(--primary-400); }
        .co-muted { opacity: 0.65; }
        .co-num { font-variant-numeric: tabular-nums; }
        .co-danger { color: var(--co-danger); }
        .co-warn { color: var(--co-warn); }
        .co-link { color: inherit; text-decoration: none; }
        .co-link:hover { color: var(--co-link); text-decoration: underline; }
        .co-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: 1rem; }
        .co-two { display: grid; grid-template-columns: repeat(auto-fit, minmax(22rem, 1fr)); gap: 1.5rem; align-items: start; }
        .co-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .co-table th { padding: 0.25rem 0.75rem 0.375rem 0; text-align: left; font-size: 0.75rem; font-weight: 500; opacity: 0.65; white-space: nowrap; }
        .co-table td { padding: 0.5rem 0.75rem 0.5rem 0; border-top: 1px solid var(--co-rule); white-space: nowrap; vertical-align: top; }
        .co-table th.co-end, .co-table td.co-end { text-align: right; }
        .co-table th:last-child, .co-table td:last-child { padding-right: 0; }
        .co-table-wrap { overflow-x: auto; }
        .co-chart { display: block; width: 100%; height: auto; }
        .co-chart text { fill: currentColor; font-size: 11px; font-variant-numeric: tabular-nums; }
        .co-chart .co-tick { opacity: 0.6; }
        .co-chart .co-gridline { stroke: var(--co-rule); }
        .co-chart .co-bar { fill: var(--co-deposit); }
        .co-chart .co-bar:hover { filter: brightness(1.12); }
        .co-chart .co-feeline { stroke: var(--co-fee); stroke-width: 1.5; stroke-dasharray: 5 4; }
        .co-chart .co-feelabel { fill: var(--co-fee); font-weight: 600; }
        .co-badges { display: flex; flex-wrap: wrap; gap: 0.375rem; }
        .co-badge { display: inline-flex; flex: none; min-width: max-content; }
        .co-kv { display: grid; grid-template-columns: max-content 1fr; gap: 0.375rem 1rem; font-size: 0.875rem; }
        .co-kv dt { opacity: 0.65; white-space: nowrap; }
        .co-kv dd { margin: 0; }
    </style>

    <div class="co-root" style="display: flex; flex-direction: column; gap: 1.5rem;">
        <div class="co-grid">
            @foreach ($tiles as $tile)
                <x-filament::section compact>
                    <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                        <div class="co-muted" style="font-size: 0.8125rem;">{{ $tile['label'] }}</div>
                        <div class="co-num" style="font-size: 1.25rem; font-weight: 600; line-height: 1.75rem; overflow-wrap: anywhere;">{{ $tile['value'] }}</div>
                        <div @class(['co-danger' => $tile['tone'] === 'danger', 'co-warn' => $tile['tone'] === 'warn', 'co-muted' => $tile['tone'] === null]) style="font-size: 0.75rem;">{{ $tile['hint'] }}</div>
                    </div>
                </x-filament::section>
            @endforeach
        </div>

        <div class="co-badges">
            <x-filament::badge :color="$overview['status'] === 'active' ? 'success' : 'gray'" class="co-badge">{{ $overview['status_label'] }}</x-filament::badge>
            @foreach ($overview['flags'] as $flag)
                <x-filament::badge :color="str_starts_with($flag, '合約金額未填') || $flag === '入帳對不到戶名' ? 'warning' : 'danger'" class="co-badge">{{ $flag }}</x-filament::badge>
            @endforeach
            @if ($overview['flags'] === [])
                <span class="co-muted" style="font-size: 0.8125rem;">沒有要處理的事</span>
            @endif
        </div>

        <div class="co-two">
            <x-filament::section heading="維護合約" compact>
                @if ($maintenance === null)
                    <div class="co-muted" style="font-size: 0.875rem;">沒有維護合約。到「編輯」填維護費、週期與起算日，「財務 → 客戶收款」就會逐期檢查維護費有沒有入帳。</div>
                @else
                    <dl class="co-kv co-num">
                        <dt>維護費</dt>
                        <dd>未稅 {{ ViewCustomer::money($maintenance['fee_untaxed']) }}／{{ $maintenance['per_label'] }}，含稅 {{ ViewCustomer::money($maintenance['fee_taxed']) }}</dd>
                        <dt>週期</dt>
                        <dd>{{ $maintenance['cycle_label'] }}（年化未稅 {{ ViewCustomer::money($maintenance['annualised_fee_untaxed']) }}）</dd>
                        <dt>起算日</dt>
                        <dd>{{ ViewCustomer::dateLabel($maintenance['since']) }}</dd>
                        <dt>到期日</dt>
                        <dd>
                            @if ($maintenance['until'] === null)
                                <span class="co-muted">沒填，持續中</span>
                            @else
                                <span @class(['co-danger' => $maintenance['is_ended'] || $maintenance['is_expiring_soon']])>
                                    {{ ViewCustomer::dateLabel($maintenance['until']) }}
                                    @if ($maintenance['is_ended'])
                                        （已到期 {{ abs($maintenance['days_until_end']) }} 天）
                                    @elseif ($maintenance['days_until_end'] === 0)
                                        （今天到期）
                                    @else
                                        （{{ $maintenance['days_until_end'] }} 天後）
                                    @endif
                                </span>
                            @endif
                        </dd>
                    </dl>
                    @if ($maintenance['is_ended'] || $maintenance['is_expiring_soon'])
                        <div class="co-muted" style="font-size: 0.75rem; margin-top: 0.75rem;">與客戶確認續約並建立新一期維護費應收；續約後把維護合約起訖日往後延。</div>
                    @endif
                @endif
            </x-filament::section>

            <x-filament::section heading="基本資料" compact>
                <dl class="co-kv">
                    <dt>全名</dt>
                    <dd>{{ $overview['name'] }}</dd>
                    <dt>銀行入帳戶名</dt>
                    <dd>{{ $overview['counterparties'] === [] ? '—' : implode('、', $overview['counterparties']) }}</dd>
                    <dt>銀行實際印的戶名</dt>
                    <dd>{{ $deposits['matched_counterparties'] === [] ? '—' : implode('、', $deposits['matched_counterparties']) }}</dd>
                    <dt>Redmine 專案 ID</dt>
                    <dd>{{ $overview['redmine_project_ids'] === [] ? '—' : implode('、', $overview['redmine_project_ids']) }}</dd>
                    <dt>歷來入帳</dt>
                    <dd class="co-num">{{ ViewCustomer::money($deposits['total_all_time']) }}</dd>
                </dl>
            </x-filament::section>
        </div>

        <x-filament::section heading="專案" compact>
            @if ($overview['projects'] === [])
                <div class="co-muted" style="font-size: 0.875rem;">還沒有專案。</div>
            @else
                <div class="co-table-wrap">
                    <table class="co-table co-num">
                        <thead>
                            <tr>
                                <th>專案</th>
                                <th>狀態</th>
                                <th class="co-end">合約金額（未稅）</th>
                                <th class="co-end">已排應收</th>
                                <th class="co-end">已收</th>
                                <th class="co-end">未收</th>
                                <th class="co-end">逾期</th>
                                <th>目標結案</th>
                                <th class="co-end">Redmine 未結</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($overview['projects'] as $project)
                                <tr>
                                    <td><a href="{{ ProjectResource::getUrl('edit', ['record' => $project['id']]) }}" class="co-link" style="font-weight: 600;">{{ $project['name'] }}</a></td>
                                    <td><x-filament::badge :color="\App\Enums\ProjectStatus::from($project['status'])->getColor()" class="co-badge">{{ $project['status_label'] }}</x-filament::badge></td>
                                    <td class="co-end">
                                        @if ($project['contract_amount_untaxed'] === null)
                                            <span class="co-warn">未填</span>
                                        @else
                                            {{ ViewCustomer::money($project['contract_amount_untaxed']) }}
                                        @endif
                                    </td>
                                    <td class="co-end">{{ ViewCustomer::money($project['receivables']['scheduled_untaxed']) }}</td>
                                    <td class="co-end">{{ ViewCustomer::money($project['receivables']['received_untaxed']) }}</td>
                                    <td class="co-end">{{ ViewCustomer::money($project['receivables']['outstanding_untaxed']) }}</td>
                                    <td class="co-end">
                                        @if ($project['receivables']['overdue_untaxed'] > 0)
                                            <span class="co-danger">{{ ViewCustomer::money($project['receivables']['overdue_untaxed']) }}</span>
                                        @else
                                            <span class="co-muted">—</span>
                                        @endif
                                    </td>
                                    <td>{{ ViewCustomer::dateLabel($project['target_close_date']) }}</td>
                                    <td class="co-end">
                                        @if ($project['open_issues'] === null)
                                            <span class="co-muted">未連結</span>
                                        @else
                                            {{ $project['open_issues'] }} 筆
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="未收應收" compact>
            @if ($receivables['items'] === [])
                <div class="co-muted" style="font-size: 0.875rem;">沒有未收的專案款{{ $receivables['outstanding_count'] > 0 ? '；未收的只有經常性維護費（見下方）' : '' }}。</div>
            @else
                <div class="co-table-wrap">
                    <table class="co-table co-num">
                        <thead>
                            <tr>
                                <th>項目</th>
                                <th>專案</th>
                                <th class="co-end">未稅</th>
                                <th class="co-end">含稅</th>
                                <th>預計收款</th>
                                <th>狀態</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($receivables['items'] as $item)
                                <tr>
                                    <td><a href="{{ ReceivableResource::getUrl('edit', ['record' => $item['id']]) }}" class="co-link" style="font-weight: 600;">{{ $item['item'] }}</a>{{ $item['confidence'] === 'low' ? '（低確定性）' : '' }}</td>
                                    <td>{{ $item['project'] ?? '—' }}</td>
                                    <td class="co-end">{{ ViewCustomer::money($item['amount_untaxed']) }}</td>
                                    <td class="co-end">{{ ViewCustomer::money($item['amount_taxed']) }}</td>
                                    <td>
                                        {{ ViewCustomer::dateLabel($item['expected_on']) }}
                                        @if ($item['is_overdue'])
                                            <span class="co-danger">逾期 {{ abs($item['days']) }} 天</span>
                                        @elseif ($item['days'] === 0)
                                            <span class="co-warn">今天</span>
                                        @else
                                            <span class="co-muted">{{ $item['days'] }} 天後</span>
                                        @endif
                                    </td>
                                    <td><x-filament::badge :color="\App\Enums\ReceivableStatus::from($item['status'])->getColor()" class="co-badge">{{ $item['status_label'] }}</x-filament::badge></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="近 12 個月入帳" compact>
            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                <div class="co-muted co-num" style="font-size: 0.8125rem;">
                    銀行明細裡類別為「收入」、對到這個客戶的入帳（含稅，銀行實收）：{{ ViewCustomer::monthLabel($months[0]) }} – {{ ViewCustomer::monthLabel(end($months)) }} 共 {{ ViewCustomer::money($deposits['total_12m']) }}，{{ $deposits['count_12m'] }} 筆。
                </div>
                <svg class="co-chart" viewBox="0 0 {{ $width }} {{ $bottom + 22 }}" role="img" aria-label="{{ $overview['short_name'] }} 每月入帳">
                    @for ($value = 0; $value <= $hi; $value += $step)
                        <line class="co-gridline" x1="{{ $left }}" x2="{{ $width - $right }}" y1="{{ $y($value) }}" y2="{{ $y($value) }}" />
                        <text class="co-tick" x="{{ $left - 6 }}" y="{{ $y($value) + 4 }}" text-anchor="end">{{ RevenueOutlook::wan($value) }}</text>
                    @endfor
                    @foreach ($months as $index => $month)
                        @php($amount = $deposits['by_month'][$month])
                        @if ($amount > 0)
                            <rect class="co-bar" x="{{ round($centerX($index) - $barWidth / 2, 1) }}" y="{{ $y($amount) }}" width="{{ $barWidth }}" height="{{ max(1, round($bottom - $y($amount), 1)) }}" rx="2">
                                <title>{{ ViewCustomer::monthLabel($month) }} 入帳 {{ ViewCustomer::money($amount) }}</title>
                            </rect>
                        @endif
                        <text class="co-tick" x="{{ $centerX($index) }}" y="{{ $bottom + 16 }}" text-anchor="middle">{{ ViewCustomer::monthLabel($month) }}</text>
                    @endforeach
                    @if ($feeLine !== null)
                        <line class="co-feeline" x1="{{ $left }}" x2="{{ $width - $right }}" y1="{{ $y($feeLine) }}" y2="{{ $y($feeLine) }}" />
                        <text class="co-feelabel" x="{{ $width - $right }}" y="{{ $y($feeLine) - 4 }}" text-anchor="end">維護費 {{ RevenueOutlook::wan($feeLine) }}</text>
                    @endif
                </svg>
            </div>
        </x-filament::section>

        <div class="co-two">
            <x-filament::section heading="經常性應收（維護費）" compact>
                @if ($overview['recurring'] === [])
                    <div class="co-muted" style="font-size: 0.875rem;">沒有經常性應收。</div>
                @else
                    <div class="co-table-wrap">
                        <table class="co-table co-num">
                            <thead>
                                <tr>
                                    <th>預計收款</th>
                                    <th>項目</th>
                                    <th class="co-end">含稅</th>
                                    <th>狀態</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($overview['recurring'] as $item)
                                    <tr>
                                        <td>{{ ViewCustomer::dateLabel($item['expected_on']) }}</td>
                                        <td><a href="{{ ReceivableResource::getUrl('edit', ['record' => $item['id']]) }}" class="co-link">{{ $item['item'] }}</a></td>
                                        <td class="co-end">{{ ViewCustomer::money($item['amount_taxed']) }}</td>
                                        <td>
                                            <x-filament::badge :color="$item['is_overdue'] ? 'danger' : \App\Enums\ReceivableStatus::from($item['status'])->getColor()" class="co-badge">{{ $item['is_overdue'] ? '逾期' : $item['status_label'] }}</x-filament::badge>
                                            @if ($item['received_on'] !== null)
                                                <span class="co-muted" style="font-size: 0.75rem;">{{ ViewCustomer::dateLabel($item['received_on']) }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>

            <x-filament::section heading="業務機會" compact>
                @if ($overview['deals'] === [])
                    <div class="co-muted" style="font-size: 0.875rem;">沒有進行中的業務機會。</div>
                @else
                    <div class="co-table-wrap">
                        <table class="co-table co-num">
                            <thead>
                                <tr>
                                    <th>機會</th>
                                    <th>階段</th>
                                    <th class="co-end">金額（未稅）</th>
                                    <th class="co-end">機率</th>
                                    <th>下一步</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($overview['deals'] as $deal)
                                    <tr>
                                        <td><a href="{{ DealResource::getUrl('edit', ['record' => $deal['id']]) }}" class="co-link" style="font-weight: 600;">{{ $deal['title'] }}</a></td>
                                        <td><x-filament::badge :color="\App\Enums\DealStage::from($deal['stage'])->getColor()" class="co-badge">{{ $deal['stage_label'] }}</x-filament::badge></td>
                                        <td class="co-end">{{ ViewCustomer::money($deal['amount_untaxed']) }}</td>
                                        <td class="co-end">{{ $deal['probability'] }}%</td>
                                        <td style="white-space: normal;">
                                            {{ $deal['next_action'] ?: '—' }}
                                            @if ($deal['next_action_on'] !== null)
                                                <span class="co-muted" style="font-size: 0.75rem;">{{ ViewCustomer::dateLabel($deal['next_action_on']) }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        </div>

        <x-filament::section heading="Redmine 未結" compact>
            @if ($overview['redmine'] === [])
                <div class="co-muted" style="font-size: 0.875rem;">沒有連結 Redmine 專案。到「編輯」填 Redmine 專案 ID，或在專案上連結 Redmine 專案。</div>
            @else
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <div class="co-num" style="font-size: 0.875rem;">共 <span style="font-weight: 600;">{{ $overview['open_issues'] }}</span> 筆未結議題</div>
                    <div class="co-table-wrap">
                        <table class="co-table co-num">
                            <thead>
                                <tr>
                                    <th>Redmine 專案</th>
                                    <th>ID</th>
                                    <th class="co-end">未結</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($overview['redmine'] as $row)
                                    <tr>
                                        <td>
                                            @if ($row['identifier'] !== null && $redmineUrl !== '')
                                                <a href="{{ $redmineUrl }}/projects/{{ $row['identifier'] }}/issues" target="_blank" rel="noopener" class="co-link" style="font-weight: 600;">{{ $row['name'] ?? $row['identifier'] }}</a>
                                            @else
                                                <span class="co-muted">{{ $row['name'] ?? '（鏡像裡還沒有這個專案）' }}</span>
                                            @endif
                                        </td>
                                        <td>{{ $row['project_id'] }}</td>
                                        <td class="co-end">{{ $row['open'] }} 筆</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
