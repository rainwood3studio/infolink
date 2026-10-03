@php
    $openTotal = $issuesByStage->flatten(1)->count();
@endphp

<div style="display: flex; flex-direction: column; gap: 0.75rem;" wire:key="closing-issues-{{ $selected['id'] }}">
    <div style="display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 0.25rem 1rem;">
        <h2 style="font-size: 1.125rem; font-weight: 600;">
            {{ $selected['name'] }}
            <span class="cp-muted cp-num" style="font-size: 0.875rem; font-weight: 400;">未結議題 {{ number_format($openTotal) }} 張，每組內最久沒更新的排最前面</span>
        </h2>
        @if ($mirrorUrl)
            <a href="{{ $mirrorUrl }}" class="cp-link" style="font-size: 0.875rem;">在「議題（鏡像）」查看全部 →</a>
        @endif
    </div>

    @if (! $selected['redmine_linked'])
        <x-filament::section compact>
            <span class="cp-muted" style="font-size: 0.875rem;">這個專案還沒連結 Redmine 專案，沒有議題可列。</span>
        </x-filament::section>
    @elseif ($openTotal === 0)
        <x-filament::section compact>
            <span class="cp-muted" style="font-size: 0.875rem;">Redmine 上已沒有未結議題。</span>
        </x-filament::section>
    @else
        @foreach ($stages as $stage => $meta)
            @php
                $rows = $issuesByStage->get($stage, collect());
            @endphp
            @continue($rows->isEmpty())

            <x-filament::section
                compact
                collapsible
                :collapsed="$stage === \App\Domain\Delivery\ClosingBoard::STAGE_AWAITING_ACCEPTANCE"
                wire:key="closing-stage-{{ $selected['id'] }}-{{ $stage }}"
            >
                <x-slot name="heading">
                    <span style="display: inline-flex; align-items: center; gap: 0.5rem;">
                        <span class="cp-swatch" style="background: var(--cp-{{ $stage }});"></span>
                        {{ $meta['label'] }}
                        <span class="cp-num">{{ $rows->count() }} 張</span>
                    </span>
                </x-slot>
                <x-slot name="description">{{ $meta['hint'] }}</x-slot>

                <div style="overflow-x: auto;">
                    <table class="cp-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>主旨</th>
                                <th>狀態</th>
                                <th>被分派者</th>
                                <th>優先</th>
                                <th>到期日</th>
                                <th>沒更新</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows->take($issuesPerStage) as $issue)
                                <tr wire:key="closing-issue-{{ $issue['id'] }}">
                                    <td class="cp-num"><a href="{{ $issue['url'] }}" target="_blank" rel="noopener" class="cp-link" style="color: var(--cp-line); font-weight: 500;">#{{ $issue['id'] }}</a></td>
                                    <td class="cp-subject">
                                        {{ $issue['subject'] }}
                                        @if ($issue['tracker'])
                                            <span class="cp-muted" style="font-size: 0.75rem;">{{ $issue['tracker'] }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $issue['status'] }}</td>
                                    <td @class(['cp-muted' => $issue['assignee'] === null])>{{ $issue['assignee'] ?? '未指派' }}</td>
                                    <td>{{ $issue['priority'] ?? '—' }}</td>
                                    <td @class(['cp-num', 'cp-danger' => $issue['is_overdue'], 'cp-muted' => $issue['due_date'] === null])>
                                        {{ $issue['due_date'] === null ? '—' : \App\Filament\Pages\ClosingProjects::shortDate($issue['due_date']).($issue['is_overdue'] ? ' 已過期' : '') }}
                                    </td>
                                    <td @class(['cp-num', 'cp-danger' => $issue['days_since_update'] >= \App\Domain\Delivery\ClosingBoard::STALLED_DAYS]) style="{{ $issue['days_since_update'] >= \App\Domain\Delivery\ClosingBoard::STALLED_DAYS ? 'font-weight: 600;' : '' }}" title="建立 {{ $issue['days_since_created'] }} 天">
                                        {{ $issue['days_since_update'] === 0 ? '今天' : $issue['days_since_update'].' 天' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($rows->count() > $issuesPerStage)
                    <div class="cp-muted" style="padding-top: 0.625rem; font-size: 0.8125rem;">
                        只列最久沒更新的 {{ $issuesPerStage }} 張，還有 {{ $rows->count() - $issuesPerStage }} 張
                        @if ($mirrorUrl)
                            ，<a href="{{ $mirrorUrl }}" class="cp-link" style="text-decoration: underline;">到「議題（鏡像）」看這個專案的全部</a>
                        @endif
                    </div>
                @endif
            </x-filament::section>
        @endforeach
    @endif
</div>
