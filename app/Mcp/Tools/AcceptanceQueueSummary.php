<?php

namespace App\Mcp\Tools;

use App\Domain\Delivery\AcceptanceQueue;
use App\Enums\SyncJob;
use App\Mcp\Tools\Concerns\PresentsRecords;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('acceptance_queue')]
#[Description(<<<'TEXT'
The acceptance (驗收) queue from the app's Redmine mirror (does not call Redmine; see `sync` for how fresh the mirror is). All final acceptance is done by one person (`summary.acceptor`, 文豪), who also writes code — so this queue is the delivery bottleneck and what holds back the final payments of closing projects.
- Queue = open issues in 驗證中 assigned to the acceptor. Off-flow = 驗證中 assigned to anyone else or to nobody: nobody will accept those until they are reassigned to the acceptor or closed.
- `summary`: `queue` size; `oldest_waiting_days` / `median_waiting_days`; `closing` = queue issues that belong to company projects in `closing` status (`issues`, `projects`, `outstanding_taxed` = tax-included receivables still to collect on those projects); `age_buckets` by waiting days (le_7, d8_30, d31_90, gt_90); `by_project` (closing projects first by target date, then largest; `days_left` to the target close date, negative = past it); `off_flow` (`total` including `unassigned`, and `by_assignee`; total − unassigned equals the `delivery.verifying.others` metric).
- Waiting days are counted from the latest observed status change to 驗證中. Status changes are only recorded since `status_tracked_since`; for issues handed over before that, waiting starts at the issue's last update (`updated_on`), which understates the real wait. `summary.waiting_observed` = how many queue issues have an observed handover.
- `flow.weeks`: Monday–Sunday weeks, oldest first, the last one is the current partial week (`is_current`). `accepted` = issues closed that week and assigned to the acceptor (attributed by assignee, not by who clicked close). `handed_over` = issues advanced to 驗證中 that week (any assignee); null before `status_tracked_since` — missing, NOT zero — and `handed_over_partial` marks the week tracking began. `acceptor_commits` = the acceptor's non-merge GitHub commits that week (acceptance competes with coding); null when the week starts before `flow.commits_retention_start` or no developer is mapped to the acceptor.
- `flow.avg_accepted_per_week` = mean of the 4 complete weeks before the current one; `weeks_to_clear` = queue ÷ that mean, assuming NO new handovers (null when nothing was accepted); `projected_clear_date` follows from it. `recent` compares handovers and acceptances over the same window (`since`); `is_growing` = at least as many handed over as accepted (null while handovers are untracked).
- `queue`: the first rows in suggested acceptance order — closing projects first (earliest target date), then longest waiting — as `columns` + `rows`; `total` is the full queue size. `handed_over_by` is who the issue was assigned to before it moved to 驗證中 (null if not observed).
- `off_flow`: per assignee ('(未指派)' = unassigned) the count and the issues untouched the longest as `[id, subject, project, days_since_update]` (capped; `count` is exact).
TEXT)]
class AcceptanceQueueSummary extends ReadTool
{
    use PresentsRecords;

    public const int QUEUE_ROWS = 30;

    public const int OFF_FLOW_ROWS_PER_ASSIGNEE = 10;

    public const array QUEUE_COLUMNS = ['id', 'subject', 'project_name', 'is_closing', 'target_close_date', 'priority', 'waiting_days', 'waiting_observed', 'handed_over_by', 'url'];

    public function handle(Request $request, AcceptanceQueue $acceptance): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $queue = $acceptance->queue();
        $flow = $acceptance->flow();

        return Response::json([
            'generated_at' => now()->toIso8601String(),
            'sync' => [
                SyncJob::RedmineIssues->value => static::presentSyncJob(SyncJob::RedmineIssues),
            ],
            'status_tracked_since' => $flow['status_tracked_since'],
            'summary' => $acceptance->summary(),
            'flow' => $flow,
            'queue' => [
                'total' => $queue->count(),
                'columns' => self::QUEUE_COLUMNS,
                'rows' => $queue
                    ->take(self::QUEUE_ROWS)
                    ->map(fn (array $row): array => array_map(fn (string $column): mixed => $row[$column], self::QUEUE_COLUMNS))
                    ->all(),
            ],
            'off_flow' => $acceptance->offFlow()
                ->map(fn (array $group): array => [
                    'assignee' => $group['assignee'],
                    'count' => $group['count'],
                    'issues' => array_map(
                        fn (array $row): array => [$row['id'], $row['subject'], $row['project_name'], $row['days_since_update']],
                        array_slice($group['issues'], 0, self::OFF_FLOW_ROWS_PER_ASSIGNEE),
                    ),
                ])
                ->all(),
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
