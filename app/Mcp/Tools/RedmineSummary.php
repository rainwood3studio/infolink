<?php

namespace App\Mcp\Tools;

use App\Domain\Delivery\DeliveryMetrics;
use App\Domain\Delivery\DeliverySummary;
use App\Enums\SyncJob;
use App\Mcp\Tools\Concerns\PresentsRecords;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('redmine_summary')]
#[Description(<<<'TEXT'
Delivery health from the app's Redmine mirror (does not call Redmine; see `sync` for how fresh the mirror is).
IMPORTANT: all final acceptance is done by one person (the `acceptor`, 文豪). `closed` and `wenhao_throughput` measure that person's acceptance, NOT team throughput; `advanced_to_verify` (issues handed over to 驗證中, by who handed them over) is the real throughput signal.
- `current`: open-issue stock now. `open`; `by_status`; `verifying` = 驗證中 split into `acceptor` (normal queue waiting for acceptance), `others` (驗證中 but assigned to someone else — off the acceptance flow, usually stalled) and `unassigned`; `stalled_30d`/`stalled_90d` (open, not updated for 30/90 days); `overdue` (past due_date); `unassigned`; `projects` sorted by open count with per-project verifying split, stalled_90d and median_age_days (since creation).
- `this_week` / `last_week`: Monday–Sunday flow. created, closed, net_flow (created − closed; positive = backlog growing), created/closed_by_project, wenhao_throughput (closed and assigned to the acceptor), advanced_to_verify (+ by assignee), hours_logged (+ by user; acceptance work is not logged, reference only), inflow_to_wenhao_ratio (share of new issues assigned to the acceptor; null when none created). `wenhao` in a field or metric key always means the acceptor.
- `trend_30d`: daily snapshot totals as `columns` + `rows` (oldest first). Reconstructed days (rebuilt from issue dates) only know `open`; their other values are 0.
TEXT)]
class RedmineSummary extends ReadTool
{
    use PresentsRecords;

    public const int TREND_DAYS = 30;

    public function handle(Request $request, DeliverySummary $summary, DeliveryMetrics $metrics): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $thisWeek = CarbonImmutable::today()->startOfWeek(CarbonInterface::MONDAY);
        $columns = ['date', 'open', 'verifying_acceptor', 'verifying_others', 'stalled_90d', 'reconstructed'];

        return Response::json([
            'generated_at' => now()->toIso8601String(),
            'acceptor' => (string) config('services.redmine.acceptor_name'),
            'sync' => [
                SyncJob::RedmineIssues->value => static::presentSyncJob(SyncJob::RedmineIssues),
                SyncJob::RedmineTime->value => static::presentSyncJob(SyncJob::RedmineTime),
                SyncJob::RedmineSnapshot->value => static::presentSyncJob(SyncJob::RedmineSnapshot),
            ],
            'current' => $summary->current(),
            'this_week' => $metrics->weekStats($thisWeek),
            'last_week' => $metrics->weekStats($thisWeek->subWeek()),
            'trend_30d' => [
                'columns' => $columns,
                'rows' => array_map(fn (array $day): array => array_map(fn (string $column): mixed => $day[$column], $columns), $summary->trend(self::TREND_DAYS)),
            ],
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
