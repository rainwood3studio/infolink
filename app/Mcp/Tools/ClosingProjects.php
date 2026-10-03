<?php

namespace App\Mcp\Tools;

use App\Domain\Delivery\ClosingBoard;
use App\Enums\SyncJob;
use App\Mcp\Tools\Concerns\PresentsRecords;
use App\Models\Project;
use App\Models\RedmineIssue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('closing_projects')]
#[Description(<<<'TEXT'
The closing battle board: every project in `closing` status with what is still open in Redmine, who it is stuck on, how fast the open stock shrinks and the uncollected money hanging on it. Read from the app's Redmine mirror (see `sync` for freshness); a project's issues are those of its linked Redmine project only (no subprojects).
- `summary`: totals — `open`, `outstanding_taxed` / `outstanding_overdue_taxed` (NTD, tax included), `overdue` (target date already passed), `projected_late` and `not_converging` (both exclude overdue projects), `at_risk` (the three added up).
- `projects` (most urgent first: target date ascending, no target last): `target_close_date`, `days_left` (negative = past target), `is_overdue`, `redmine_linked` (false = no Redmine project linked, every issue field is empty), `open`, `by_status`, `stalled_30d` (open, not updated for 30 days), `by_assignee` (who holds the open issues, `(未指派)` = nobody; `is_acceptor` marks the single final acceptor).
- `by_stage` says who each open issue is stuck on: `unassigned` (no assignee, not 驗證中), `in_progress` (assigned to a developer, not yet handed over), `awaiting_acceptance` (驗證中 and assigned to the `acceptor` — waiting for final acceptance, the developers are done), `off_flow` (驗證中 but NOT assigned to the acceptor, unassigned included — nobody will accept it until it is reassigned).
- Money: `outstanding_taxed`, `outstanding_overdue_taxed`, `next_expected_on` and `receivables` (planned or invoiced: item, amount_taxed, expected_on, is_overdue). Final payments usually depend on closing.
- `trend`: daily open counts for the last 14 days as `[date, open]`, oldest first, last entry = today live. Days before the first real snapshot were rebuilt from issue dates.
- Projection: `burn_per_day` = NET issues cleared per day since `burn_from` (the snapshot about 7 days ago); new issues offset closed ones. `projection` is `projected` (then `projected_close_date` = today + open / burn, and `days_late` vs the target: positive = late, negative = early), `not_converging` (open count did not shrink), `no_history` (no snapshot that old), `cleared` (nothing open) or `not_linked`. It is a straight line from one week of data, not a commitment: quote it as an estimate, and remember everything in 驗證中 still has to pass one person.
- `most_stalled`: per project up to 10 open issues NOT in 驗證中, longest without an update first, as `[id, subject, status, assignee, days_since_update]` (assignee null = unassigned).
TEXT)]
class ClosingProjects extends ReadTool
{
    use PresentsRecords;

    /** Per project, at most this many stalled issues are listed. */
    public const int MOST_STALLED = 10;

    public function handle(Request $request, ClosingBoard $board): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $projects = $board->projects();
        $models = Project::query()->findMany($projects->pluck('id'))->keyBy('id');

        return Response::json([
            'generated_at' => now()->toIso8601String(),
            'acceptor' => (string) config('services.redmine.acceptor_name'),
            'sync' => [
                SyncJob::RedmineIssues->value => static::presentSyncJob(SyncJob::RedmineIssues),
                SyncJob::RedmineSnapshot->value => static::presentSyncJob(SyncJob::RedmineSnapshot),
            ],
            'summary' => $board->summary($projects),
            'projects' => $projects->map(fn (array $project): array => [
                ...$project,
                'trend' => array_map(fn (array $day): array => [$day['date'], $day['open']], $project['trend']),
                'most_stalled' => $board->issues($models[$project['id']])
                    ->filter(fn (array $issue): bool => $issue['status'] !== RedmineIssue::STATUS_VERIFYING)
                    ->sortByDesc('days_since_update')
                    ->take(self::MOST_STALLED)
                    ->map(fn (array $issue): array => [$issue['id'], $issue['subject'], $issue['status'], $issue['assignee'], $issue['days_since_update']])
                    ->values()
                    ->all(),
            ])->all(),
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
