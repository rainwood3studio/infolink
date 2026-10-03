<?php

namespace App\Mcp\Tools;

use App\Domain\Engineering\DevActivityReport;
use App\Domain\Engineering\GithubSync;
use App\Enums\SyncJob;
use App\Mcp\Tools\Concerns\PresentsRecords;
use App\Models\Developer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('dev_activity_summary')]
#[Description(<<<'TEXT'
GitHub developer activity from the app's mirror (every branch of every org repo; see `sync` for freshness). Dates are calendar days in Asia/Taipei; merge commits are excluded everywhere.
- `window`: from/to (default: the 7 days before today) and `retention_start` (older activity is not kept).
- `developers`: everyone tracked, with `is_active` and `notes` (e.g. someone on leave — do not flag their silence).
- `people` / `previous_people`: per person for the window and the same-length window before it: active_days, commits, lines_added/lines_deleted (lock, generated, vendored and test-fixture files excluded; NOT comparable between people — AI-assisted code inflates them), prs_opened, prs_merged, reviews, issues (distinct existing Redmine issues referenced), type_mix (feat/fix/refactor/perf/test/docs/chore/other), ai_assisted_ratio (share of commits with a Co-Authored-By: Claude trailer), repos (commits per repo), last_commit_at.
- `days` (newest first): per person per day the commits as `[time, type, repo, subject, issue ids]`, the Redmine issues touched (id, subject, status, is_closed) and merged PRs.
- `untracked`: per person, commits that reference no Redmine issue (count / total commits).
TEXT)]
class DevActivitySummary extends ReadTool
{
    use PresentsRecords;

    public const int DEFAULT_DAYS = 7;

    /** Per person per day, at most this many commit subjects are listed (the count is always exact). */
    public const int MAX_COMMITS_PER_DAY = 40;

    public function handle(Request $request, DevActivityReport $report): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [
            'from.date_format' => 'from must be YYYY-MM-DD.',
            'to.date_format' => 'to must be YYYY-MM-DD.',
            'to.after_or_equal' => 'to must not be before from.',
        ]);

        $today = CarbonImmutable::today(config('app.timezone'));
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to'], config('app.timezone')) : $today->subDay();
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from'], config('app.timezone')) : $to->subDays(self::DEFAULT_DAYS - 1);
        $to = $to->endOfDay();
        $days = (int) $from->diffInDays($to->startOfDay()) + 1;
        $previousTo = $from->subDay()->endOfDay();
        $previousFrom = $from->subDays($days);

        $untracked = $report->untracked($from, $to, limit: 0);

        return Response::json([
            'generated_at' => now()->toIso8601String(),
            'window' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $days,
                'previous_from' => $previousFrom->toDateString(),
                'previous_to' => $previousTo->toDateString(),
                'retention_start' => GithubSync::windowStart()->toDateString(),
            ],
            'sync' => [SyncJob::GithubActivity->value => static::presentSyncJob(SyncJob::GithubActivity)],
            'developers' => Developer::query()->orderBy('name')->get()
                ->map(fn (Developer $developer): array => [
                    'name' => $developer->name,
                    'is_active' => $developer->is_active,
                    'notes' => $developer->notes,
                ])->all(),
            'people' => $report->people($from, $to)->map(fn (array $person): array => $this->presentPerson($person))->all(),
            'previous_people' => $report->people($previousFrom, $previousTo)->map(fn (array $person): array => $this->presentPerson($person))->all(),
            'days' => $report->dailyLog($from, $to)->map(fn (array $day): array => [
                'date' => $day['date'],
                'people' => array_map(fn (array $person): array => $this->presentPersonDay($person), $day['people']),
            ])->all(),
            'untracked' => array_map(fn (array $row): array => [
                'name' => $row['name'],
                'untracked' => $row['count'],
                'commits' => $row['commits'],
            ], $untracked['people']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $person
     * @return array<string, mixed>
     */
    protected function presentPerson(array $person): array
    {
        return [
            'name' => $person['name'],
            'is_unmapped' => $person['is_unmapped'],
            'active_days' => $person['active_days'],
            'commits' => $person['commits'],
            'lines_added' => $person['lines_added'],
            'lines_deleted' => $person['lines_deleted'],
            'prs_opened' => $person['prs_opened'],
            'prs_merged' => $person['prs_merged'],
            'reviews' => $person['reviews'],
            'issues' => $person['issues'],
            'type_mix' => $person['type_mix'],
            'ai_assisted_ratio' => $person['ai_assisted_ratio'],
            'repos' => $person['repos'],
            'last_commit_at' => $person['last_commit_at']?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $person
     * @return array<string, mixed>
     */
    protected function presentPersonDay(array $person): array
    {
        $commits = collect($person['repos'])
            ->flatMap(fn (array $repo): array => array_map(fn (array $commit): array => [
                $commit['time'],
                $commit['type']->value,
                $repo['name'],
                $commit['subject'],
                array_column($commit['issues'], 'id'),
            ], $repo['commits']))
            ->sortBy(0)
            ->values();

        return [
            'name' => $person['name'],
            'commits' => $person['commits'],
            'lines_added' => $person['lines_added'],
            'lines_deleted' => $person['lines_deleted'],
            'commit_list' => $commits->take(self::MAX_COMMITS_PER_DAY)->all(),
            'commit_list_truncated' => $commits->count() > self::MAX_COMMITS_PER_DAY,
            'issues' => array_map(fn (array $issue): array => [
                'id' => $issue['id'],
                'subject' => $issue['subject'],
                'status' => $issue['status'],
                'is_closed' => $issue['is_closed'],
            ], $person['issues']),
            'merged_prs' => array_map(fn (array $pr): array => [
                'repo' => $pr['repo'],
                'number' => $pr['number'],
                'title' => $pr['title'],
            ], $person['merged_prs']),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->format('date')->description('YYYY-MM-DD, default: `to` − 6 days.'),
            'to' => $schema->string()->format('date')->description('YYYY-MM-DD, default: yesterday.'),
        ];
    }
}
