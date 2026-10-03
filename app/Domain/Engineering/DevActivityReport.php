<?php

namespace App\Domain\Engineering;

use App\Enums\CommitType;
use App\Models\Developer;
use App\Models\GithubCommit;
use App\Models\GithubIdentity;
use App\Models\GithubPullRequest;
use App\Models\GithubReview;
use App\Models\RedmineIssue;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Read model behind 開發活動: GitHub commits, pull requests and reviews grouped by person (a Developer, or an
 * identity not mapped to one yet), joined with what the developer did in Redmine ({@see RedmineActivity}, matched
 * by `developers.redmine_name`). Merge commits are excluded everywhere; lines use the effective counts when
 * present. Everything is aggregated in PHP so it behaves the same on sqlite and Postgres (volumes are small).
 * Dates are calendar days in the app timezone.
 */
class DevActivityReport
{
    /** @var array<string, string> Period keys of the page filter, in display order. */
    public const array PERIODS = [
        'today' => '今天',
        'yesterday' => '昨天',
        'this_week' => '本週',
        'last_week' => '上週',
        'this_month' => '本月',
        'last_30_days' => '近 30 天',
    ];

    public const string DEFAULT_PERIOD = 'this_week';

    /** Unmapped identities are shown with this prefix until they are assigned to a developer. */
    public const string UNMAPPED_PREFIX = '未對應：';

    /** @var array<int, array{id:int, subject:string, status:string, is_closed:bool, url:string}> */
    protected array $issueCache = [];

    protected RedmineActivity $redmine;

    public function __construct(?RedmineActivity $redmine = null)
    {
        $this->redmine = $redmine ?? new RedmineActivity;
    }

    /**
     * Inclusive start/end of a period key (unknown keys fall back to 本週). Weeks start on Monday.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function periodRange(string $period, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now(config('app.timezone'));
        $today = $now->startOfDay();

        return match ($period) {
            'today' => [$today, $today->endOfDay()],
            'yesterday' => [$today->subDay(), $today->subDay()->endOfDay()],
            'last_week' => [$today->startOfWeek(CarbonInterface::MONDAY)->subWeek(), $today->startOfWeek(CarbonInterface::MONDAY)->subWeek()->endOfWeek(CarbonInterface::SUNDAY)],
            'this_month' => [$today->startOfMonth(), $today->endOfDay()],
            'last_30_days' => [$today->subDays(29), $today->endOfDay()],
            default => [$today->startOfWeek(CarbonInterface::MONDAY), $today->endOfDay()],
        };
    }

    /**
     * Whether any GitHub activity has been synced at all (drives the empty state).
     */
    public function hasAnyData(): bool
    {
        return GithubCommit::query()->exists();
    }

    /**
     * Per-person summary for the period, most commits first. Active developers are always listed (possibly with
     * zeros); inactive developers and unmapped identities only when they did something. `issues` counts distinct
     * Redmine issues referenced by their commits/PRs or worked on in Redmine (hours, handed to 驗證中, closed);
     * `redmine` is null for people without a `redmine_name`.
     *
     * @return Collection<int, array{key:string, name:string, is_unmapped:bool, active_days:int, commits:int, lines_added:int, lines_deleted:int, prs_opened:int, prs_merged:int, reviews:int, issues:int, type_mix:array<string, int>, ai_assisted_ratio:?float, repos:array<string, int>, last_commit_at:?CarbonInterface, redmine:?array{redmine_name:string, is_acceptor:bool, hours:float, hours_days:int, issues:int, advanced_to_verify:int, closed_without_verify:int, accepted:?int, open_assigned:int, verifying_assigned:int, stalled_30d:int}}>
     */
    public function people(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $commits = $this->commits($from, $to);
        $openedPrs = $this->pullRequests('opened_at', $from, $to);
        $mergedPrs = $this->pullRequests('merged_at', $from, $to);
        $reviews = GithubReview::query()
            ->with('identity.developer')
            ->whereBetween('submitted_at', [$from, $to])
            ->get();

        $developers = Developer::query()->get();
        $redmine = $this->redmine->forDevelopers($developers, $from, $to);

        $existingIssues = $this->existingIssueIds(
            $commits->pluck('redmine_issue_ids')
                ->merge($openedPrs->pluck('redmine_issue_ids'))
                ->merge($mergedPrs->pluck('redmine_issue_ids'))
                ->merge(array_column($redmine, 'issue_ids'))
                ->flatten()
        );

        /** @var array<string, array<string, mixed>> $rows */
        $rows = [];
        $row = function (?GithubIdentity $identity) use (&$rows): string {
            $person = $this->person($identity);
            $rows[$person['key']] ??= [...$person, 'commit_list' => collect(), 'prs_opened' => 0, 'prs_merged' => 0, 'reviews' => 0, 'issue_ids' => []];

            return $person['key'];
        };

        foreach ($commits as $commit) {
            $key = $row($commit->identity);
            $rows[$key]['commit_list']->push($commit);
            $rows[$key]['issue_ids'] = [...$rows[$key]['issue_ids'], ...($commit->redmine_issue_ids ?? [])];
        }

        foreach ($openedPrs as $pr) {
            $key = $row($pr->identity);
            $rows[$key]['prs_opened']++;
            $rows[$key]['issue_ids'] = [...$rows[$key]['issue_ids'], ...($pr->redmine_issue_ids ?? [])];
        }

        foreach ($mergedPrs as $pr) {
            $key = $row($pr->identity);
            $rows[$key]['prs_merged']++;
            $rows[$key]['issue_ids'] = [...$rows[$key]['issue_ids'], ...($pr->redmine_issue_ids ?? [])];
        }

        foreach ($reviews as $review) {
            $rows[$row($review->identity)]['reviews']++;
        }

        $developers->each(function (Developer $developer) use (&$rows, $redmine, $existingIssues): void {
            $key = 'dev:'.$developer->id;
            $activity = $redmine[$developer->id] ?? null;

            if ($developer->is_active || ($activity !== null && $activity['by_day'] !== [])) {
                $rows[$key] ??= ['key' => $key, 'name' => $developer->name, 'is_unmapped' => false, 'commit_list' => collect(), 'prs_opened' => 0, 'prs_merged' => 0, 'reviews' => 0, 'issue_ids' => []];
            }

            if (isset($rows[$key]) && $activity !== null) {
                $rows[$key]['issue_ids'] = [...$rows[$key]['issue_ids'], ...$activity['issue_ids']];
                $rows[$key]['redmine'] = [
                    ...Arr::except($activity, ['issue_ids', 'by_day']),
                    'issues' => count(array_intersect($activity['issue_ids'], $existingIssues)),
                ];
            }
        });

        return collect($rows)
            ->map(function (array $row) use ($existingIssues): array {
                /** @var Collection<int, GithubCommit> $list */
                $list = $row['commit_list'];
                $count = $list->count();

                return [
                    'key' => $row['key'],
                    'name' => $row['name'],
                    'is_unmapped' => $row['is_unmapped'],
                    'active_days' => $list->map(fn (GithubCommit $commit): string => $this->day($commit->authored_at))->unique()->count(),
                    'commits' => $count,
                    'lines_added' => (int) $list->sum(fn (GithubCommit $commit): int => $commit->linesAdded()),
                    'lines_deleted' => (int) $list->sum(fn (GithubCommit $commit): int => $commit->linesDeleted()),
                    'prs_opened' => $row['prs_opened'],
                    'prs_merged' => $row['prs_merged'],
                    'reviews' => $row['reviews'],
                    'issues' => count(array_intersect(array_unique($row['issue_ids']), $existingIssues)),
                    'type_mix' => $this->typeMix($list),
                    'ai_assisted_ratio' => $count > 0 ? $list->where('is_ai_assisted', true)->count() / $count : null,
                    'repos' => $list->countBy(fn (GithubCommit $commit): string => $commit->repo->name)->sortDesc()->take(3)->all(),
                    'last_commit_at' => $list->max('authored_at'),
                    'redmine' => $row['redmine'] ?? null,
                ];
            })
            ->sort(fn (array $a, array $b): int => [$b['commits'], $b['prs_merged'] + $b['reviews'], $a['is_unmapped'], $a['name']]
                <=> [$a['commits'], $a['prs_merged'] + $a['reviews'], $b['is_unmapped'], $b['name']])
            ->values();
    }

    /**
     * Commit counts per person per day. Only people with at least one commit in the range are listed.
     *
     * @return array{dates: list<string>, rows: list<array{key:string, name:string, is_unmapped:bool, counts:array<string, int>, total:int}>, max:int}
     */
    public function heatmap(CarbonImmutable $from, CarbonImmutable $to, ?string $personKey = null): array
    {
        $dates = [];
        for ($day = $from->startOfDay(); $day->lte($to); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        $rows = $this->commits($from, $to)
            ->groupBy(fn (GithubCommit $commit): string => $this->person($commit->identity)['key'])
            ->when($personKey !== null, fn (Collection $groups): Collection => $groups->only([$personKey]))
            ->map(function (Collection $list): array {
                $person = $this->person($list->first()->identity);
                $counts = $list->countBy(fn (GithubCommit $commit): string => $this->day($commit->authored_at))->all();

                return [...$person, 'counts' => $counts, 'total' => $list->count()];
            })
            ->sortByDesc('total')
            ->values()
            ->all();

        $max = (int) collect($rows)->flatMap(fn (array $row): array => array_values($row['counts']))->max();

        return ['dates' => $dates, 'rows' => $rows, 'max' => $max];
    }

    /**
     * Day by day (newest first), person by person: the commits grouped by repo, the Redmine issues they touched
     * (referenced by commits/PRs, or worked on in Redmine that day) and the pull requests that got merged. A
     * developer with only Redmine activity on a day is listed too.
     *
     * @return Collection<int, array{date:string, commits:int, people:list<array{key:string, name:string, is_unmapped:bool, commits:int, redmine_hours:float, lines_added:int, lines_deleted:int, repos:list<array{name:string, full_name:string, url:string, commits:list<array<string, mixed>>}>, issues:list<array{id:int, subject:string, status:string, is_closed:bool, url:string}>, merged_prs:list<array<string, mixed>>}>}>
     */
    public function dailyLog(CarbonImmutable $from, CarbonImmutable $to, ?string $personKey = null): Collection
    {
        $commits = $this->commits($from, $to);
        $mergedPrs = $this->pullRequests('merged_at', $from, $to);
        $developers = Developer::query()->get()->keyBy('id');
        $redmine = $this->redmine->forDevelopers($developers->values(), $from, $to);
        $this->loadIssues(
            $commits->pluck('redmine_issue_ids')
                ->merge($mergedPrs->pluck('redmine_issue_ids'))
                ->merge(array_column($redmine, 'issue_ids'))
                ->flatten()
        );

        /** @var array<string, array<string, array<string, mixed>>> $days */
        $days = [];
        $entry = function (string $date, ?GithubIdentity $identity) use (&$days): string {
            $person = $this->person($identity);
            $days[$date][$person['key']] ??= [...$person, 'commit_list' => [], 'merged_prs' => [], 'issue_ids' => [], 'redmine_hours' => 0.0];

            return $person['key'];
        };

        foreach ($redmine as $developerId => $activity) {
            $key = 'dev:'.$developerId;

            foreach ($activity['by_day'] as $date => $day) {
                $days[$date][$key] ??= ['key' => $key, 'name' => $developers[$developerId]->name, 'is_unmapped' => false, 'commit_list' => [], 'merged_prs' => [], 'issue_ids' => [], 'redmine_hours' => 0.0];
                $days[$date][$key]['issue_ids'] = $day['issue_ids'];
                $days[$date][$key]['redmine_hours'] = $day['hours'];
            }
        }

        foreach ($commits->sortBy('authored_at') as $commit) {
            $date = $this->day($commit->authored_at);
            $key = $entry($date, $commit->identity);
            $days[$date][$key]['commit_list'][] = $commit;
            $days[$date][$key]['issue_ids'] = [...$days[$date][$key]['issue_ids'], ...($commit->redmine_issue_ids ?? [])];
        }

        foreach ($mergedPrs->sortBy('merged_at') as $pr) {
            $date = $this->day($pr->merged_at);
            $key = $entry($date, $pr->identity);
            $days[$date][$key]['merged_prs'][] = $this->prRow($pr);
            $days[$date][$key]['issue_ids'] = [...$days[$date][$key]['issue_ids'], ...($pr->redmine_issue_ids ?? [])];
        }

        krsort($days);

        return collect($days)
            ->map(function (array $people, string $date) use ($personKey): ?array {
                $rows = collect($people)
                    ->when($personKey !== null, fn (Collection $rows): Collection => $rows->only([$personKey]))
                    ->map(fn (array $person): array => $this->personDay($person))
                    ->sortByDesc('commits')
                    ->values()
                    ->all();

                if ($rows === []) {
                    return null;
                }

                return ['date' => $date, 'commits' => array_sum(array_column($rows, 'commits')), 'people' => $rows];
            })
            ->filter()
            ->values();
    }

    /**
     * Non-merge commits that reference no existing Redmine issue: a per-person count (with their total commits for
     * context) and the newest commits.
     *
     * @return array{total:int, commits_total:int, people:list<array{key:string, name:string, is_unmapped:bool, count:int, commits:int}>, commits:list<array<string, mixed>>}
     */
    public function untracked(CarbonImmutable $from, CarbonImmutable $to, ?string $personKey = null, int $limit = 50): array
    {
        $commits = $this->commits($from, $to)
            ->when($personKey !== null, fn (Collection $commits): Collection => $commits->filter(fn (GithubCommit $commit): bool => $this->person($commit->identity)['key'] === $personKey));
        $existing = $this->existingIssueIds($commits->pluck('redmine_issue_ids')->flatten());

        $untracked = $commits->filter(fn (GithubCommit $commit): bool => array_intersect($commit->redmine_issue_ids ?? [], $existing) === []);
        $totals = $commits->countBy(fn (GithubCommit $commit): string => $this->person($commit->identity)['key']);

        return [
            'total' => $untracked->count(),
            'commits_total' => $commits->count(),
            'people' => $untracked
                ->groupBy(fn (GithubCommit $commit): string => $this->person($commit->identity)['key'])
                ->map(fn (Collection $list, string $key): array => [
                    ...$this->person($list->first()->identity),
                    'count' => $list->count(),
                    'commits' => (int) $totals->get($key, 0),
                ])
                ->sortByDesc('count')
                ->values()
                ->all(),
            'commits' => $untracked
                ->sortByDesc('authored_at')
                ->take($limit)
                ->map(fn (GithubCommit $commit): array => [
                    ...$this->commitRow($commit),
                    'date' => $this->day($commit->authored_at),
                    'person' => $this->person($commit->identity)['name'],
                    'repo' => $commit->repo->name,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Options for the person filter: developers (active first), then unmapped identities that have commits.
     *
     * @return array<string, string>
     */
    public function personOptions(): array
    {
        $developers = Developer::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Developer $developer): array => ['dev:'.$developer->id => $developer->name]);

        $unmapped = GithubIdentity::query()
            ->whereNull('developer_id')
            ->whereHas('commits')
            ->get()
            ->mapWithKeys(fn (GithubIdentity $identity): array => ['id:'.$identity->id => self::UNMAPPED_PREFIX.$identity->label()]);

        return $developers->merge($unmapped)->all();
    }

    /**
     * Who a commit/PR/review belongs to: its identity's developer, else the identity itself.
     *
     * @return array{key:string, name:string, is_unmapped:bool}
     */
    public function person(?GithubIdentity $identity): array
    {
        if ($identity === null) {
            return ['key' => 'unknown', 'name' => self::UNMAPPED_PREFIX.'（未知作者）', 'is_unmapped' => true];
        }

        if ($identity->developer !== null) {
            return ['key' => 'dev:'.$identity->developer->id, 'name' => $identity->developer->name, 'is_unmapped' => false];
        }

        return ['key' => 'id:'.$identity->id, 'name' => self::UNMAPPED_PREFIX.$identity->label(), 'is_unmapped' => true];
    }

    /**
     * Non-merge commits authored in the range, with what the report needs eager loaded.
     *
     * @return Collection<int, GithubCommit>
     */
    protected function commits(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return GithubCommit::query()
            ->authored()
            ->with(['identity.developer', 'repo'])
            ->whereBetween('authored_at', [$from, $to])
            ->get()
            ->toBase();
    }

    /**
     * @return Collection<int, GithubPullRequest>
     */
    protected function pullRequests(string $column, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return GithubPullRequest::query()
            ->with(['identity.developer', 'repo'])
            ->whereBetween($column, [$from, $to])
            ->get();
    }

    /**
     * The subset of the given ids that are real Redmine issues (soft-deleted ones included).
     *
     * @param  Collection<int, mixed>  $ids
     * @return list<int>
     */
    protected function existingIssueIds(Collection $ids): array
    {
        $this->loadIssues($ids);

        return array_values(array_intersect($ids->map(fn (mixed $id): int => (int) $id)->unique()->all(), array_keys($this->issueCache)));
    }

    /**
     * @param  Collection<int, mixed>  $ids
     */
    protected function loadIssues(Collection $ids): void
    {
        $missing = $ids->map(fn (mixed $id): int => (int) $id)->unique()->diff(array_keys($this->issueCache))->values();

        if ($missing->isEmpty()) {
            return;
        }

        foreach ($missing->chunk(500) as $chunk) {
            RedmineIssue::withTrashed()
                ->whereIn('id', $chunk->all())
                ->get(['id', 'subject', 'status', 'is_closed'])
                ->each(function (RedmineIssue $issue): void {
                    $this->issueCache[$issue->id] = [
                        'id' => $issue->id,
                        'subject' => $issue->subject,
                        'status' => (string) $issue->status,
                        'is_closed' => (bool) $issue->is_closed,
                        'url' => $issue->url(),
                    ];
                });
        }
    }

    /**
     * @param  list<int>|null  $ids
     * @return list<array{id:int, subject:string, status:string, is_closed:bool, url:string}>
     */
    protected function issues(?array $ids): array
    {
        return collect($ids ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->map(fn (int $id): ?array => $this->issueCache[$id] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $person
     * @return array{key:string, name:string, is_unmapped:bool, commits:int, redmine_hours:float, lines_added:int, lines_deleted:int, repos:list<array{name:string, full_name:string, url:string, commits:list<array<string, mixed>>}>, issues:list<array{id:int, subject:string, status:string, is_closed:bool, url:string}>, merged_prs:list<array<string, mixed>>}
     */
    protected function personDay(array $person): array
    {
        /** @var Collection<int, GithubCommit> $list */
        $list = collect($person['commit_list']);

        return [
            'key' => $person['key'],
            'name' => $person['name'],
            'is_unmapped' => $person['is_unmapped'],
            'commits' => $list->count(),
            'redmine_hours' => (float) $person['redmine_hours'],
            'lines_added' => (int) $list->sum(fn (GithubCommit $commit): int => $commit->linesAdded()),
            'lines_deleted' => (int) $list->sum(fn (GithubCommit $commit): int => $commit->linesDeleted()),
            'repos' => $list
                ->groupBy('github_repo_id')
                ->map(fn (Collection $repoCommits): array => [
                    'name' => $repoCommits->first()->repo->name,
                    'full_name' => $repoCommits->first()->repo->full_name,
                    'url' => $repoCommits->first()->repo->url(),
                    'commits' => $repoCommits->map(fn (GithubCommit $commit): array => $this->commitRow($commit))->values()->all(),
                ])
                ->sortByDesc(fn (array $repo): int => count($repo['commits']))
                ->values()
                ->all(),
            'issues' => $this->issues($person['issue_ids']),
            'merged_prs' => $person['merged_prs'],
        ];
    }

    /**
     * @return array{time:string, subject:string, title:string, type:CommitType, scope:?string, sha:string, url:string, is_ai_assisted:bool, lines_added:int, lines_deleted:int, issues:list<array{id:int, subject:string, status:string, is_closed:bool, url:string}>}
     */
    protected function commitRow(GithubCommit $commit): array
    {
        return [
            'time' => $commit->authored_at->format('H:i'),
            'subject' => $commit->subject,
            'title' => self::stripPrefix($commit->subject),
            'type' => $commit->type ?? CommitType::Other,
            'scope' => $commit->scope,
            'sha' => substr($commit->sha, 0, 7),
            'url' => $commit->url(),
            'is_ai_assisted' => $commit->is_ai_assisted,
            'lines_added' => $commit->linesAdded(),
            'lines_deleted' => $commit->linesDeleted(),
            'issues' => $this->issues($commit->redmine_issue_ids),
        ];
    }

    /**
     * @return array{number:int, title:string, url:string, repo:string, time:string, lines_added:int, lines_deleted:int, issues:list<array{id:int, subject:string, status:string, is_closed:bool, url:string}>}
     */
    protected function prRow(GithubPullRequest $pr): array
    {
        return [
            'number' => $pr->number,
            'title' => $pr->title,
            'url' => $pr->url(),
            'repo' => $pr->repo->name,
            'time' => $pr->merged_at->format('H:i'),
            'lines_added' => $pr->additions,
            'lines_deleted' => $pr->deletions,
            'issues' => $this->issues($pr->redmine_issue_ids),
        ];
    }

    /**
     * Commit counts by type in CommitType order, zero types left out.
     *
     * @param  Collection<int, GithubCommit>  $commits
     * @return array<string, int>
     */
    protected function typeMix(Collection $commits): array
    {
        $counts = $commits->countBy(fn (GithubCommit $commit): string => ($commit->type ?? CommitType::Other)->value);

        return collect(CommitType::cases())
            ->mapWithKeys(fn (CommitType $type): array => [$type->value => (int) $counts->get($type->value, 0)])
            ->filter()
            ->all();
    }

    /**
     * The subject without its conventional-commit prefix (`feat(pos): 新增…` → `新增…`); the type and scope are
     * shown as badges instead.
     */
    public static function stripPrefix(string $subject): string
    {
        $stripped = preg_replace('/^[a-zA-Z]+(?:\([^)]{1,64}\))?!?\s*[:：]\s*/u', '', $subject);

        return filled($stripped) ? $stripped : $subject;
    }

    protected function day(CarbonInterface $moment): string
    {
        return $moment->copy()->setTimezone(config('app.timezone'))->toDateString();
    }
}
