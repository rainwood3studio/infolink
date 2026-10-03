@php
    use App\Filament\Pages\ClosingProjects;
    use App\Filament\Resources\Projects\ProjectResource;
    use App\Filament\Support\Money;
    use App\Filament\Widgets\ClosingProjectsWidget;
    use Carbon\CarbonImmutable;

    $chartWidth = 240;
    $chartHeight = 36;
    $chartPad = 5;
    $trendStart = CarbonImmutable::today()->subDays(\App\Domain\Delivery\ClosingBoard::TREND_DAYS - 1);
    $trendSpan = \App\Domain\Delivery\ClosingBoard::TREND_DAYS - 1;
@endphp

<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(21rem, 1fr)); gap: 1rem; align-items: start;">
    @foreach ($projects as $row)
        @php
            $model = $models[$row['id']];
            $isSelected = $selected['id'] === $row['id'];
            $projection = ClosingProjects::projectionText($row);
            $editUrl = ProjectResource::getUrl('edit', ['record' => $row['id']]);

            $maxOpen = max(1, ...array_column($row['trend'], 'open'));
            $points = array_map(fn (array $day): array => [
                'x' => round($chartPad + ($trendStart->diffInDays(CarbonImmutable::parse($day['date'])) / $trendSpan) * ($chartWidth - 2 * $chartPad), 1),
                'y' => round($chartHeight - $chartPad - ($day['open'] / $maxOpen) * ($chartHeight - 2 * $chartPad), 1),
                'label' => ClosingProjects::shortDate($day['date']).'：'.number_format($day['open']).' 張',
            ], $row['trend']);
            $line = implode(' ', array_map(fn (array $point): string => "{$point['x']},{$point['y']}", $points));
            $first = $row['trend'][0];
            $last = $points[array_key_last($points)];
        @endphp
        <div
            class="cp-card"
            role="button"
            tabindex="0"
            aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
            wire:key="closing-card-{{ $row['id'] }}"
            wire:click="selectProject({{ $row['id'] }})"
            x-on:keydown.enter.prevent="$wire.selectProject({{ $row['id'] }})"
            title="{{ $isSelected ? '下方是這個專案的議題清單' : '看 '.$row['name'].' 的議題清單' }}"
        >
            <x-filament::section compact>
                <div style="display: flex; flex-direction: column; gap: 0.875rem;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem;">
                        <div style="min-width: 0;">
                            <div style="font-weight: 600; overflow-wrap: anywhere;">{{ $row['name'] }}</div>
                            @if ($row['customer'])
                                <div class="cp-muted" style="font-size: 0.75rem;">{{ $row['customer'] }}</div>
                            @endif
                        </div>
                        <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 0.125rem; flex: none;">
                            <x-filament::badge :color="ClosingProjectsWidget::countdownColor($model)">{{ ClosingProjectsWidget::countdown($model) }}</x-filament::badge>
                            @if ($row['target_close_date'])
                                <span class="cp-muted cp-num" style="font-size: 0.75rem;">目標 {{ $row['target_close_date'] }}</span>
                            @endif
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.75rem;">
                        <div>
                            <div class="cp-muted" style="font-size: 0.75rem;">未結議題</div>
                            @if ($row['redmine_linked'])
                                <div class="cp-num" style="font-size: 1.5rem; font-weight: 600; line-height: 2rem;">{{ number_format($row['open']) }}</div>
                                <div @class(['cp-num', 'cp-muted' => $row['stalled_30d'] === 0]) style="font-size: 0.75rem;">
                                    {{ $row['stalled_30d'] > 0 ? "{$row['stalled_30d']} 張超過 30 天沒更新" : '沒有停滯超過 30 天的' }}
                                </div>
                            @else
                                <div style="font-size: 1.5rem; font-weight: 600; line-height: 2rem;">—</div>
                                <div style="font-size: 0.75rem;">
                                    <span class="cp-muted">未連結 Redmine 專案，</span><a href="{{ $editUrl }}" class="cp-link" style="text-decoration: underline;" x-on:click.stop x-on:keydown.enter.stop>到專案頁設定</a>
                                </div>
                            @endif
                        </div>
                        <div>
                            <div class="cp-muted" style="font-size: 0.75rem;">未收應收（含稅）</div>
                            <div class="cp-num" style="font-size: 1.5rem; font-weight: 600; line-height: 2rem;">{{ Money::format($row['outstanding_taxed']) }}</div>
                            @forelse (array_slice($row['receivables'], 0, 3) as $receivable)
                                <div class="cp-num" style="font-size: 0.75rem;">
                                    <span class="cp-muted">{{ $receivable['item'] }} {{ Money::format($receivable['amount_taxed']) }}・</span>@if ($receivable['is_overdue'])<span class="cp-danger">{{ ClosingProjects::shortDate($receivable['expected_on']) }} 已逾期</span>@else<span class="cp-muted">預計 {{ ClosingProjects::shortDate($receivable['expected_on']) }}</span>@endif
                                </div>
                            @empty
                                <div class="cp-muted" style="font-size: 0.75rem;">沒有未收的款項</div>
                            @endforelse
                            @if (count($row['receivables']) > 3)
                                <div class="cp-muted" style="font-size: 0.75rem;">另有 {{ count($row['receivables']) - 3 }} 筆</div>
                            @endif
                        </div>
                    </div>

                    @if ($row['redmine_linked'])
                        <div class="cp-rule" style="display: flex; flex-direction: column; gap: 0.5rem;">
                            @if ($row['open'] > 0)
                                <div class="cp-bar" role="img" aria-label="未結議題依卡在誰分類">
                                    @foreach ($stages as $stage => $meta)
                                        @if ($row['by_stage'][$stage] > 0)
                                            <span style="flex: {{ $row['by_stage'][$stage] }} 1 0; background: var(--cp-{{ $stage }});" title="{{ $meta['label'] }} {{ $row['by_stage'][$stage] }} 張：{{ $meta['hint'] }}"></span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                            <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.125rem 0.75rem; font-size: 0.8125rem;">
                                @foreach ($stages as $stage => $meta)
                                    <span title="{{ $meta['hint'] }}" style="display: flex; align-items: center; gap: 0.375rem; {{ $row['by_stage'][$stage] === 0 ? 'opacity: 0.5;' : '' }}">
                                        <span class="cp-swatch" style="background: var(--cp-{{ $stage }});"></span>
                                        <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $meta['label'] }}</span>
                                        <span class="cp-num" style="margin-left: auto; font-weight: 600;">{{ $row['by_stage'][$stage] }}</span>
                                    </span>
                                @endforeach
                            </div>
                        </div>

                        <div class="cp-rule" style="display: flex; flex-direction: column; gap: 0.375rem;">
                            <div style="display: flex; align-items: baseline; justify-content: space-between; gap: 0.5rem; font-size: 0.75rem;">
                                <span class="cp-muted">近 {{ \App\Domain\Delivery\ClosingBoard::TREND_DAYS }} 天未結數</span>
                                @if (count($points) > 1)
                                    <span class="cp-num"><span class="cp-muted">{{ ClosingProjects::shortDate($first['date']) }}</span> {{ number_format($first['open']) }} → <span class="cp-muted">今天</span> <span style="font-weight: 600;">{{ number_format($row['open']) }}</span></span>
                                @endif
                            </div>
                            @if (count($points) > 1)
                                <svg viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" role="img" aria-label="近 {{ \App\Domain\Delivery\ClosingBoard::TREND_DAYS }} 天未結議題數，{{ number_format($first['open']) }} 張到 {{ number_format($row['open']) }} 張" style="display: block; width: 100%; height: auto;">
                                    <line x1="0" y1="{{ $chartHeight - $chartPad }}" x2="{{ $chartWidth }}" y2="{{ $chartHeight - $chartPad }}" stroke="var(--cp-rule)" stroke-width="1" />
                                    <polygon points="{{ $points[0]['x'] }},{{ $chartHeight - $chartPad }} {{ $line }} {{ $last['x'] }},{{ $chartHeight - $chartPad }}" fill="var(--cp-line)" opacity="0.1" />
                                    <polyline points="{{ $line }}" fill="none" stroke="var(--cp-line)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                                    <circle cx="{{ $last['x'] }}" cy="{{ $last['y'] }}" r="4" fill="var(--cp-line)" />
                                    @foreach ($points as $index => $point)
                                        @php
                                            $left = $index === 0 ? 0 : ($points[$index - 1]['x'] + $point['x']) / 2;
                                            $right = $index === array_key_last($points) ? $chartWidth : ($point['x'] + $points[$index + 1]['x']) / 2;
                                        @endphp
                                        <rect x="{{ $left }}" y="0" width="{{ $right - $left }}" height="{{ $chartHeight }}" fill="transparent"><title>{{ $point['label'] }}</title></rect>
                                    @endforeach
                                </svg>
                            @endif
                            <div @class(['cp-danger' => $projection['color'] === 'danger', 'cp-success' => $projection['color'] === 'success', 'cp-muted' => $projection['color'] === 'gray']) style="font-size: 0.8125rem; {{ $projection['color'] === 'danger' ? 'font-weight: 600;' : '' }}">
                                {{ $projection['text'] }}
                            </div>
                        </div>

                        @if ($row['by_assignee'] !== [])
                            <div class="cp-rule" style="display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.25rem 0.875rem; font-size: 0.8125rem;">
                                <span class="cp-muted" style="font-size: 0.75rem;">卡在誰</span>
                                @foreach (array_slice($row['by_assignee'], 0, $holdersPerCard) as $holder)
                                    <span style="white-space: nowrap;">
                                        {{ $holder['name'] }}
                                        <span class="cp-num" style="font-weight: 600;">{{ $holder['count'] }}</span>
                                        @if ($holder['is_acceptor'])
                                            <span class="cp-muted" style="font-size: 0.75rem;">驗收</span>
                                        @endif
                                    </span>
                                @endforeach
                                @if (count($row['by_assignee']) > $holdersPerCard)
                                    <span class="cp-muted" style="font-size: 0.75rem;">另 {{ count($row['by_assignee']) - $holdersPerCard }} 人</span>
                                @endif
                            </div>
                        @endif
                    @endif

                    @if ($row['target_close_date'] === null)
                        <div class="cp-rule" style="font-size: 0.75rem;">
                            <span class="cp-muted">沒有目標結案日，無法判斷趕不趕得上，</span><a href="{{ $editUrl }}" class="cp-link" style="text-decoration: underline;" x-on:click.stop x-on:keydown.enter.stop>到專案頁補上</a>
                        </div>
                    @endif
                </div>
            </x-filament::section>
        </div>
    @endforeach
</div>
