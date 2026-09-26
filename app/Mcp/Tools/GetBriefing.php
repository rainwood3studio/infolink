<?php

namespace App\Mcp\Tools;

use App\Domain\Delivery\DeliverySummary;
use App\Domain\Finance\FinancePosition;
use App\Domain\Metrics\MetricRecorder;
use App\Domain\Work\ActionItemService;
use App\Domain\Work\AttentionItem;
use App\Enums\InsightSeverity;
use App\Enums\ProjectStatus;
use App\Enums\ReportType;
use App\Enums\SyncJob;
use App\Mcp\Tools\Concerns\PresentsRecords;
use App\Models\BankTransaction;
use App\Models\Insight;
use App\Models\MetricDefinition;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RedmineIssue;
use App\Models\Report;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_briefing')]
#[Description(<<<'TEXT'
START HERE. The whole company picture in one call; use the other tools only to drill down. Amounts are whole NTD integers; dates YYYY-MM-DD; timestamps ISO 8601 (Asia/Taipei).
- `data_freshness`: per background job (redmine_issues, redmine_time, redmine_snapshot, vault_tasks, rules) `last_status` (ok/failed/running/skipped, null = never ran), `last_run_at`, `last_ok_at`, `last_error`; `bank_latest_txn_date` (bank data is imported by hand, so finance numbers are only as fresh as this); `latest_daily_brief` (date and id of the newest daily_brief report). Mention stale data before drawing conclusions.
- `pinned_metrics`: the dashboard headline metrics (company-wide total). `value` = latest value for `period` (period start), `previous` = the value before it, `change` = value − previous, `status` ok/warn/critical against the thresholds, `better` (up/down/none), `unit` (twd, count, ratio 0..1, months, hours, days), `description` (shortened; list_metric_definitions has the full text). value null = never recorded.
- `metrics_over_threshold`: every metric (pinned or not) whose latest total is warn or critical, with its thresholds.
- `finance` (null until bank data exists): `balance` (latest bank balance), `monthly_cost` (recurring monthly cost baseline), `runway_months` (balance ÷ monthly_cost, ignoring receivables), `ar_outstanding_taxed` (high-confidence, non-recurring, tax-inclusive), `ar_low_confidence_taxed`, `ar_overdue_taxed`, `forecast_year_end` and `forecast_min_90d` (lowest forecast month-end balance in the next 90 days; the forecast only counts high-confidence receivables). Details: get_cash_position.
- `delivery`: Redmine mirror stock — open, by_status, verifying split (acceptor = 文豪's normal acceptance queue; others = 驗證中 assigned to someone else, i.e. off the acceptance flow; unassigned), stalled_30d/90d, overdue, unassigned, and `top_projects` (5 largest by open issues). All acceptance is done by 文豪, so closed counts are not team throughput. Details: redmine_summary.
- `attention`: the 「今天要處理」 list in order — unresolved critical then warning insights (`type` insight, `id`, `fingerprint`, `severity`), then pending action items due today or overdue (`type` action_item, `id`, `priority`, `due_on`, `days_overdue`).
- `open_insights_by_severity`: counts of unresolved insights (open + acknowledged) per severity, including info.
- `overdue_receivables` and `upcoming_receivables` (expected in the next 30 days): outstanding receivables incl. recurring fees (`is_recurring`), with `untaxed`/`taxed` amounts, `confidence` and `days_overdue`.
- `closing_projects`: projects in 結案 (closing) status with `target_close_date`, `days_left` (negative = past target) and `open_issues` (open issues in the linked Redmine project; null if not linked).
TEXT)]
class GetBriefing extends ReadTool
{
    use PresentsRecords;

    public const int UPCOMING_DAYS = 30;

    public const int TOP_PROJECTS = 5;

    public const int DESCRIPTION_LENGTH = 160;

    public function handle(
        Request $request,
        MetricRecorder $recorder,
        FinancePosition $financePosition,
        DeliverySummary $deliverySummary,
        ActionItemService $actionItems,
    ): Response {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $definitions = MetricDefinition::query()->orderBy('sort')->orderBy('key')->get();
        $metrics = $definitions->mapWithKeys(fn (MetricDefinition $definition): array => [
            $definition->key => $this->metric($recorder, $definition),
        ]);

        return Response::json([
            'generated_at' => now()->toIso8601String(),
            'data_freshness' => $this->freshness(),
            'pinned_metrics' => $definitions->where('is_pinned', true)->map(fn (MetricDefinition $definition): array => $metrics[$definition->key])->values()->all(),
            'metrics_over_threshold' => $definitions
                ->filter(fn (MetricDefinition $definition): bool => $metrics[$definition->key]['status'] !== MetricRecorder::STATUS_OK)
                ->map(fn (MetricDefinition $definition): array => [
                    ...array_intersect_key($metrics[$definition->key], array_flip(['key', 'name', 'unit', 'better', 'period', 'value', 'status'])),
                    'warn_threshold' => static::number($definition->warn_threshold),
                    'critical_threshold' => static::number($definition->critical_threshold),
                ])
                ->values()
                ->all(),
            'finance' => $this->finance($financePosition),
            'delivery' => $this->delivery($deliverySummary),
            'attention' => $actionItems->attentionList()->map(fn (AttentionItem $item): array => $this->attentionItem($item))->all(),
            'open_insights_by_severity' => $this->insightCounts(),
            'overdue_receivables' => $this->receivables(Receivable::query()->overdue()),
            'upcoming_receivables' => $this->receivables(Receivable::query()
                ->outstanding()
                ->whereDate('expected_on', '>=', today())
                ->whereDate('expected_on', '<=', today()->addDays(self::UPCOMING_DAYS))),
            'closing_projects' => $this->closingProjects(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function freshness(): array
    {
        $brief = Report::query()
            ->where('type', ReportType::DailyBrief)
            ->latest('period_start')
            ->latest('id')
            ->first(['id', 'period_start', 'created_at']);

        return [
            'sync_jobs' => collect(SyncJob::cases())
                ->mapWithKeys(fn (SyncJob $job): array => [$job->value => static::presentSyncJob($job)])
                ->all(),
            'bank_latest_txn_date' => ($date = BankTransaction::query()->max('txn_date')) === null ? null : substr((string) $date, 0, 10),
            'latest_daily_brief' => $brief === null ? null : [
                'id' => $brief->id,
                'date' => static::date($brief->period_start),
                'created_at' => static::dateTime($brief->created_at),
            ],
        ];
    }

    /**
     * @return array{key: string, name: string, unit: string, better: string, period_type: string, period: ?string, value: int|float|null, previous: int|float|null, change: int|float|null, status: string, description: ?string}
     */
    protected function metric(MetricRecorder $recorder, MetricDefinition $definition): array
    {
        $latest = $recorder->latestWithPrevious($definition->key);

        return [
            'key' => $definition->key,
            'name' => $definition->name,
            'unit' => $definition->unit->value,
            'better' => $definition->better->value,
            'period_type' => $definition->period_type->value,
            'period' => static::date($latest['current']?->period_start),
            'value' => static::number($latest['current']?->value),
            'previous' => static::number($latest['previous']?->value),
            'change' => static::number($latest['change']),
            'status' => $latest['status'],
            'description' => $definition->description === null ? null : static::excerpt($definition->description, self::DESCRIPTION_LENGTH),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function finance(FinancePosition $financePosition): ?array
    {
        $position = $financePosition->current();

        if ($position === null) {
            return null;
        }

        return [
            'as_of' => $position['as_of']->toDateString(),
            'balance' => $position['balance'],
            'monthly_cost' => $position['monthly_cost'],
            'runway_months' => $position['runway_months'],
            'ar_outstanding_taxed' => $position['outstanding_taxed'],
            'ar_low_confidence_taxed' => $position['low_confidence_taxed'],
            'ar_overdue_taxed' => $position['overdue_taxed'],
            'forecast_year_end' => $position['forecast']->yearEndBalance,
            'forecast_min_90d' => $position['forecast_min_90d'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function delivery(DeliverySummary $deliverySummary): array
    {
        $current = $deliverySummary->current();

        return [
            ...array_diff_key($current, ['projects' => true]),
            'top_projects' => array_slice($current['projects'], 0, self::TOP_PROJECTS),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function attentionItem(AttentionItem $item): array
    {
        if ($item->isInsight()) {
            /** @var Insight $insight */
            $insight = $item->model;

            return [
                'type' => $item->type,
                'id' => $insight->id,
                'fingerprint' => $insight->fingerprint,
                'severity' => $insight->severity->value,
                'category' => $insight->category->value,
                'status' => $insight->status->value,
                'title' => $item->title,
                'last_seen_at' => static::dateTime($insight->last_seen_at),
            ];
        }

        return [
            'type' => $item->type,
            'id' => $item->model->id,
            'priority' => $item->priority?->value,
            'title' => $item->title,
            'due_on' => static::date($item->dueOn),
            'days_overdue' => (int) $item->dueOn?->diffInDays(today()),
            'owner' => $item->model->owner,
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function insightCounts(): array
    {
        $counts = Insight::query()->unresolved()->toBase()->selectRaw('severity, count(*) as aggregate')->groupBy('severity')->pluck('aggregate', 'severity');

        return collect(InsightSeverity::cases())
            ->mapWithKeys(fn (InsightSeverity $severity): array => [$severity->value => (int) ($counts[$severity->value] ?? 0)])
            ->all();
    }

    /**
     * @param  Builder<Receivable>  $query
     * @return list<array<string, mixed>>
     */
    protected function receivables(Builder $query): array
    {
        return $query
            ->with(['customer', 'project'])
            ->orderBy('expected_on')
            ->orderBy('id')
            ->get()
            ->map(fn (Receivable $receivable): array => array_intersect_key(static::presentReceivable($receivable), array_flip([
                'id', 'customer', 'project', 'item', 'untaxed', 'taxed', 'expected_on', 'days_overdue', 'confidence', 'status', 'is_recurring',
            ])))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function closingProjects(): array
    {
        /** @var Collection<int, Project> $projects */
        $projects = Project::query()
            ->with('customer')
            ->where('status', ProjectStatus::Closing)
            ->orderByRaw('case when target_close_date is null then 1 else 0 end')
            ->orderBy('target_close_date')
            ->get();

        $openIssues = RedmineIssue::query()
            ->open()
            ->whereIn('project_id', $projects->pluck('redmine_project_id')->filter())
            ->toBase()
            ->selectRaw('project_id, count(*) as aggregate')
            ->groupBy('project_id')
            ->pluck('aggregate', 'project_id');

        return $projects->map(fn (Project $project): array => [
            'id' => $project->id,
            'name' => $project->name,
            'customer' => $project->customer?->short_name ?: $project->customer?->name,
            'target_close_date' => static::date($project->target_close_date),
            'days_left' => $project->target_close_date === null ? null : (int) today()->diffInDays($project->target_close_date, false),
            'open_issues' => $project->redmine_project_id === null ? null : (int) ($openIssues[$project->redmine_project_id] ?? 0),
        ])->all();
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
