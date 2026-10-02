<?php

use App\Domain\Engineering\DevActivityReport;
use App\Enums\CommitType;
use App\Models\Developer;
use App\Models\GithubCommit;
use App\Models\GithubIdentity;
use App\Models\GithubPullRequest;
use App\Models\GithubRepo;
use App\Models\GithubReview;
use App\Models\RedmineIssue;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['services.redmine.url' => 'http://redmine.test']);
    $this->travelTo(CarbonImmutable::parse('2026-10-02 15:00'));

    $this->report = new DevActivityReport;
    $this->from = CarbonImmutable::parse('2026-09-28 00:00');
    $this->to = CarbonImmutable::parse('2026-10-02 23:59:59');

    $this->repo = GithubRepo::factory()->create(['name' => 'pos', 'full_name' => 'infolinktw/pos']);
    $this->yubin = Developer::factory()->create(['name' => '永彬']);
    $this->work = GithubIdentity::factory()->for($this->yubin)->create(['login' => 'yubin']);
    $this->home = GithubIdentity::factory()->for($this->yubin)->create(['login' => null, 'email' => 'yubin@home.test']);
    $this->stranger = GithubIdentity::factory()->create(['login' => 'ghost-dev']);

    RedmineIssue::factory()->create(['id' => 2881, 'subject' => '作廢要選原因', 'status' => '處理中']);
    RedmineIssue::factory()->create(['id' => 2876, 'subject' => '報表匯出', 'status' => '已結案', 'is_closed' => true]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function devCommit(GithubIdentity $identity, GithubRepo $repo, string $authoredAt, array $attributes = []): GithubCommit
{
    return GithubCommit::factory()->for($repo, 'repo')->for($identity, 'identity')->create([
        'authored_at' => $authoredAt,
        ...$attributes,
    ]);
}

it('groups identities into developers and leaves unmapped identities on their own', function () {
    devCommit($this->work, $this->repo, '2026-10-01 10:00', ['additions' => 900, 'deletions' => 10, 'effective_additions' => 40, 'effective_deletions' => 5, 'redmine_issue_ids' => [2881, 42]]);
    devCommit($this->home, $this->repo, '2026-10-02 09:00', ['additions' => 10, 'deletions' => 2, 'type' => CommitType::Fix, 'is_ai_assisted' => true, 'redmine_issue_ids' => [2876]]);
    devCommit($this->work, $this->repo, '2026-10-02 11:00', ['additions' => 5000, 'deletions' => 5000])->forceFill(['is_merge' => true])->save();
    devCommit($this->stranger, $this->repo, '2026-09-29 14:00', ['additions' => 3, 'deletions' => 1]);
    devCommit($this->work, $this->repo, '2026-09-20 10:00');

    $pr = GithubPullRequest::factory()->for($this->repo, 'repo')->for($this->work, 'identity')->merged()->create(['opened_at' => '2026-09-30 10:00', 'merged_at' => '2026-10-01 18:00', 'redmine_issue_ids' => [2881]]);
    GithubReview::factory()->for($pr, 'pullRequest')->for($this->stranger, 'identity')->create(['submitted_at' => '2026-10-01 12:00']);
    Developer::factory()->create(['name' => '離職者', 'is_active' => false]);
    Developer::factory()->create(['name' => '新人']);

    $people = $this->report->people($this->from, $this->to)->keyBy('key');

    expect($people->keys()->all())->toBe(["dev:{$this->yubin->id}", "id:{$this->stranger->id}", $people->keys()->last()])
        ->and($people->last()['name'])->toBe('新人')
        ->and($people["dev:{$this->yubin->id}"])->toMatchArray([
            'name' => '永彬',
            'is_unmapped' => false,
            'active_days' => 2,
            'commits' => 2,
            'lines_added' => 50,
            'lines_deleted' => 7,
            'prs_opened' => 1,
            'prs_merged' => 1,
            'reviews' => 0,
            'issues' => 2,
            'type_mix' => ['feat' => 1, 'fix' => 1],
            'ai_assisted_ratio' => 0.5,
            'repos' => ['pos' => 2],
        ])
        ->and($people["id:{$this->stranger->id}"])->toMatchArray([
            'name' => '未對應：ghost-dev',
            'is_unmapped' => true,
            'commits' => 1,
            'reviews' => 1,
            'issues' => 0,
        ]);
});

it('counts commits per person per day for the heatmap', function () {
    devCommit($this->work, $this->repo, '2026-10-01 10:00');
    devCommit($this->home, $this->repo, '2026-10-01 23:30');
    devCommit($this->stranger, $this->repo, '2026-09-28 08:00');

    $heatmap = $this->report->heatmap($this->from, $this->to);

    expect($heatmap['dates'])->toBe(['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02'])
        ->and($heatmap['max'])->toBe(2)
        ->and($heatmap['rows'][0])->toMatchArray(['name' => '永彬', 'counts' => ['2026-10-01' => 2], 'total' => 2])
        ->and($this->report->heatmap($this->from, $this->to, "id:{$this->stranger->id}")['rows'])->toHaveCount(1);
});

it('builds the daily log newest day first with repos, issues and merged pull requests', function () {
    $other = GithubRepo::factory()->create(['name' => 'admin', 'full_name' => 'infolinktw/admin']);
    devCommit($this->work, $this->repo, '2026-10-01 10:00', ['subject' => 'feat(pos): 作廢原因', 'redmine_issue_ids' => [2881, 42], 'sha' => str_repeat('a', 40)]);
    devCommit($this->home, $other, '2026-10-01 16:00', ['subject' => 'fix: 匯出', 'type' => CommitType::Fix, 'scope' => null, 'redmine_issue_ids' => [2876]]);
    devCommit($this->stranger, $this->repo, '2026-10-02 09:00');
    GithubPullRequest::factory()->for($this->repo, 'repo')->for($this->work, 'identity')->merged()->create(['number' => 77, 'title' => '作廢原因', 'merged_at' => '2026-10-01 18:00']);

    $log = $this->report->dailyLog($this->from, $this->to);

    expect($log->pluck('date')->all())->toBe(['2026-10-02', '2026-10-01']);

    $day = $log[1]['people'][0];

    expect($day['name'])->toBe('永彬')
        ->and($day['commits'])->toBe(2)
        ->and(collect($day['repos'])->pluck('name')->sort()->values()->all())->toBe(['admin', 'pos'])
        ->and(collect($day['issues'])->pluck('id')->all())->toBe([2881, 2876])
        ->and($day['issues'][0])->toMatchArray(['subject' => '作廢要選原因', 'status' => '處理中', 'url' => 'http://redmine.test/issues/2881'])
        ->and($day['merged_prs'][0])->toMatchArray(['number' => 77, 'title' => '作廢原因']);

    $commit = collect($day['repos'])->firstWhere('name', 'pos')['commits'][0];

    expect($commit)->toMatchArray([
        'time' => '10:00',
        'title' => '作廢原因',
        'type' => CommitType::Feat,
        'sha' => 'aaaaaaa',
        'url' => 'https://github.com/infolinktw/pos/commit/'.str_repeat('a', 40),
    ])->and(collect($commit['issues'])->pluck('id')->all())->toBe([2881]);

    expect($this->report->dailyLog($this->from, $this->to, "id:{$this->stranger->id}")->pluck('date')->all())->toBe(['2026-10-02']);
});

it('lists non-merge commits that reference no existing Redmine issue', function () {
    devCommit($this->work, $this->repo, '2026-10-01 10:00', ['redmine_issue_ids' => [2881]]);
    devCommit($this->work, $this->repo, '2026-10-01 11:00', ['redmine_issue_ids' => [42], 'subject' => 'chore: 只引用 PR 編號']);
    devCommit($this->stranger, $this->repo, '2026-10-02 09:00', ['subject' => 'wip']);
    devCommit($this->stranger, $this->repo, '2026-10-02 10:00')->forceFill(['is_merge' => true])->save();

    $untracked = $this->report->untracked($this->from, $this->to);

    expect($untracked['total'])->toBe(2)
        ->and($untracked['commits_total'])->toBe(3)
        ->and(collect($untracked['people'])->pluck('count', 'name')->all())->toBe(['永彬' => 1, '未對應：ghost-dev' => 1])
        ->and(collect($untracked['commits'])->pluck('subject')->all())->toBe(['wip', 'chore: 只引用 PR 編號']);
});

it('resolves period keys to inclusive day ranges with weeks starting on Monday', function (string $period, string $from, string $to) {
    [$start, $end] = DevActivityReport::periodRange($period, CarbonImmutable::parse('2026-10-02 15:00'));

    expect($start->toDateTimeString())->toBe($from)
        ->and($end->toDateTimeString())->toBe($to);
})->with([
    ['today', '2026-10-02 00:00:00', '2026-10-02 23:59:59'],
    ['yesterday', '2026-10-01 00:00:00', '2026-10-01 23:59:59'],
    ['this_week', '2026-09-28 00:00:00', '2026-10-02 23:59:59'],
    ['last_week', '2026-09-21 00:00:00', '2026-09-27 23:59:59'],
    ['this_month', '2026-10-01 00:00:00', '2026-10-02 23:59:59'],
    ['last_30_days', '2026-09-03 00:00:00', '2026-10-02 23:59:59'],
]);
