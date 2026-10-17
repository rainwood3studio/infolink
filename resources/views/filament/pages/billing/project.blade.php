@php
    use App\Enums\ProjectStatus;
    use App\Filament\Pages\BillingProgress;
    use App\Filament\Resources\Projects\ProjectResource;

    $contract = $project['contract_amount_untaxed'];
    $base = max($contract ?? 0, $project['scheduled'], 1);
    $unscheduled = $project['unscheduled'];
    $receivedShare = $contract !== null && $contract > 0 ? $project['received'] / $contract : null;
    $open = $project['has_problems'];
@endphp

<x-filament::section compact wire:key="bp-project-{{ $project['id'] }}">
    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
        <div class="bp-head">
            <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.25rem 0.75rem;">
                <a href="{{ ProjectResource::getUrl('edit', ['record' => $project['id']]) }}" class="bp-link" style="font-size: 1rem; font-weight: 600;">{{ $project['name'] }}</a>
                @if ($project['customer'] !== null)
                    <span class="bp-muted" style="font-size: 0.8125rem;">{{ $project['customer'] }}</span>
                @endif
                <span class="bp-badge"><x-filament::badge :color="ProjectStatus::from($project['status'])->getColor()" size="sm">{{ $project['status_label'] }}</x-filament::badge></span>
                @if ($project['overdue']['count'] > 0)
                    <span class="bp-badge"><x-filament::badge color="danger" size="sm">逾期 {{ $project['overdue']['count'] }} 筆 {{ BillingProgress::money($project['overdue']['amount']) }}</x-filament::badge></span>
                @endif
                @if ($project['due_uninvoiced']['count'] > 0)
                    <span class="bp-badge"><x-filament::badge color="warning" size="sm">到期未開票 {{ $project['due_uninvoiced']['count'] }} 筆 {{ BillingProgress::money($project['due_uninvoiced']['amount']) }}</x-filament::badge></span>
                @endif
                @if ($project['is_over_scheduled'])
                    <span class="bp-badge"><x-filament::badge color="danger" size="sm">應收超過合約 {{ BillingProgress::money(abs($unscheduled)) }}</x-filament::badge></span>
                @endif
                @if ($contract === null)
                    <span class="bp-badge"><x-filament::badge color="gray" size="sm">沒填合約金額</x-filament::badge></span>
                @endif
            </div>
            <div class="bp-figures bp-num">
                <span><span class="bp-muted">合約</span> <span style="font-weight: 600;">{{ BillingProgress::money($contract) }}</span></span>
                <span><span class="bp-muted">已收</span> <span class="bp-ok" style="font-weight: 600;">{{ BillingProgress::money($project['received']) }}</span>@if ($receivedShare !== null) <span class="bp-muted">{{ BillingProgress::percent($receivedShare) }}</span>@endif</span>
                <span><span class="bp-muted">已開票未收</span> {{ BillingProgress::money($project['invoiced_outstanding']) }}</span>
                <span><span class="bp-muted">已排未開票</span> {{ BillingProgress::money($project['planned']) }}</span>
                <span><span class="bp-muted">未排入</span> <span @class(['bp-danger' => $project['is_over_scheduled']])>{{ $unscheduled === null ? '—' : ($unscheduled < 0 ? '−'.BillingProgress::money(abs($unscheduled)) : BillingProgress::money($unscheduled)) }}</span></span>
            </div>
        </div>

        <div class="bp-bar" role="img" aria-label="{{ $project['name'] }} 請款進度">
            <span class="bp-seg-received" style="width: {{ BillingProgress::width($project['received'], $base) }}%;" title="已收 {{ BillingProgress::money($project['received']) }}"></span>
            <span class="bp-seg-invoiced" style="width: {{ BillingProgress::width($project['invoiced_outstanding'], $base) }}%;" title="已開票未收 {{ BillingProgress::money($project['invoiced_outstanding']) }}"></span>
            <span class="bp-seg-planned" style="width: {{ BillingProgress::width($project['planned'], $base) }}%;" title="已排未開票 {{ BillingProgress::money($project['planned']) }}"></span>
            @if ($unscheduled !== null && $unscheduled > 0)
                <span class="bp-seg-unscheduled" style="width: {{ BillingProgress::width($unscheduled, $base) }}%;" title="尚未排入應收 {{ BillingProgress::money($unscheduled) }}"></span>
            @endif
        </div>
        @if ($project['is_over_scheduled'])
            <div class="bp-bar" style="height: 0.25rem;" aria-hidden="true">
                <span style="width: {{ BillingProgress::width($contract, $base) }}%;"></span>
                <span class="bp-seg-over" style="width: {{ BillingProgress::width(abs($unscheduled), $base) }}%;" title="超過合約 {{ BillingProgress::money(abs($unscheduled)) }}"></span>
            </div>
        @endif

        @if ($project['receivables'] === [])
            <span class="bp-muted" style="font-size: 0.8125rem;">還沒有應收。把每一期款項建成應收，進度才算得出來。</span>
        @else
            <details class="bp-details" @if ($open) open @endif>
                <summary>{{ count($project['receivables']) }} 筆應收</summary>
                <div style="margin-top: 0.5rem;">
                    @include('filament.pages.billing.receivables', ['rows' => $project['receivables'], 'prefix' => 'bp-project-'.$project['id']])
                </div>
            </details>
        @endif
    </div>
</x-filament::section>
