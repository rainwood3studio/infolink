<?php

use App\Domain\Delivery\AcceptanceQueue;
use App\Enums\ProjectStatus;
use App\Enums\ReceivableStatus;
use App\Enums\SyncJob;
use App\Models\Developer;
use App\Models\GithubCommit;
use App\Models\GithubIdentity;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusChange;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['services.redmine.url' => 'http://redmine.test', 'services.redmine.acceptor_name' => '文豪', 'services.github.retention_months' => 1]);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00'));
});

/**
 * An open 驗證中 issue; assigned to the acceptor unless overridden.
 *
 * @param  array<string, mixed>  $attributes
 */
function verifyingIssue(array $attributes = []): RedmineIssue
{
    return RedmineIssue::factory()->create(['status' => RedmineIssue::STATUS_VERIFYING, 'assignee_name' => '文豪', ...$attributes]);
}

function acceptedIssue(string $closedOn, string $assignee = '文豪'): RedmineIssue
{
    return RedmineIssue::factory()->create(['status' => '完成', 'is_closed' => true, 'assignee_name' => $assignee, 'closed_on' => $closedOn]);
}

it('splits 驗證中 into the acceptor queue and off-flow issues grouped by assignee', function () {
    verifyingIssue(['id' => 101]);
    verifyingIssue(['id' => 102, 'assignee_name' => '文豪 陳']);
    verifyingIssue(['id' => 201, 'assignee_name' => '裕樺', 'updated_on' => '2026-09-23 09:00']);
    verifyingIssue(['id' => 202, 'assignee_name' => '裕樺', 'updated_on' => '2026-08-04 09:00']);
    verifyingIssue(['id' => 203, 'assignee_name' => '妤欣', 'updated_on' => '2026-10-01 09:00']);
    verifyingIssue(['id' => 204, 'assignee_name' => null, 'updated_on' => '2026-10-02 09:00']);
    verifyingIssue(['id' => 301, 'status' => '完成', 'is_closed' => true]);
    RedmineIssue::factory()->create(['id' => 302, 'status' => '實作中']);

    $queue = app(AcceptanceQueue::class);
    $offFlow = $queue->offFlow();

    expect($queue->queue()->pluck('id')->all())->toBe([101, 102])
        ->and($offFlow->pluck('count', 'assignee')->all())->toBe(['裕樺' => 2, AcceptanceQueue::UNASSIGNED => 1, '妤欣' => 1])
        ->and(collect($offFlow[0]['issues'])->pluck('days_since_update', 'id')->all())->toBe([202 => 60, 201 => 10])
        ->and($queue->summary())->toMatchArray(['acceptor' => '文豪', 'queue' => 2])
        ->and($queue->summary()['off_flow'])->toBe([
            'total' => 4,
            'unassigned' => 1,
            'by_assignee' => [
                ['assignee' => '裕樺', 'count' => 2, 'oldest_days_since_update' => 60],
                ['assignee' => AcceptanceQueue::UNASSIGNED, 'count' => 1, 'oldest_days_since_update' => 1],
                ['assignee' => '妤欣', 'count' => 1, 'oldest_days_since_update' => 2],
            ],
        ]);
});

it('counts waiting days from the latest observed handover, else from the last update', function () {
    verifyingIssue(['id' => 1, 'updated_on' => '2026-10-02 18:00']);
    RedmineStatusChange::factory()->create(['issue_id' => 1, 'previous_assignee_name' => '鈺文', 'changed_at' => '2026-08-01 10:00']);
    RedmineStatusChange::factory()->create(['issue_id' => 1, 'previous_assignee_name' => '裕樺', 'changed_at' => '2026-09-13 10:00']);
    RedmineStatusChange::factory()->create(['issue_id' => 1, 'from_status' => '驗證中', 'to_status' => '實作中', 'changed_at' => '2026-09-20 10:00']);
    verifyingIssue(['id' => 2, 'updated_on' => '2026-09-26 23:00']);
    verifyingIssue(['id' => 3, 'updated_on' => '2026-09-25 09:00']);
    verifyingIssue(['id' => 4, 'updated_on' => '2026-07-04 09:00']);
    verifyingIssue(['id' => 5, 'updated_on' => '2026-01-05 09:00']);

    $queue = app(AcceptanceQueue::class);
    $rows = $queue->queue()->keyBy('id');

    expect($rows[1])->toMatchArray(['waiting_since' => '2026-09-13', 'waiting_days' => 20, 'waiting_observed' => true, 'handed_over_by' => '裕樺', 'days_since_update' => 1])
        ->and($rows[2])->toMatchArray(['waiting_since' => '2026-09-26', 'waiting_days' => 7, 'waiting_observed' => false, 'handed_over_by' => null])
        ->and($queue->summary())->toMatchArray([
            'age_buckets' => ['le_7' => 1, 'd8_30' => 2, 'd31_90' => 0, 'gt_90' => 2],
            'oldest_waiting_days' => 271,
            'median_waiting_days' => 20,
            'waiting_observed' => 1,
        ]);
});

it('orders the queue with closing projects first, then the longest waiting', function () {
    $appProject = Project::factory()->create(['name' => '商城 APP', 'status' => ProjectStatus::Closing, 'redmine_project_id' => 21, 'target_close_date' => '2026-10-01']);
    Project::factory()->create(['name' => '我識', 'status' => ProjectStatus::Closing, 'redmine_project_id' => 22, 'target_close_date' => '2026-10-31']);
    Project::factory()->create(['name' => '維運', 'status' => ProjectStatus::Active, 'redmine_project_id' => 15, 'target_close_date' => '2026-09-01']);
    Receivable::factory()->for($appProject)->create(['amount_untaxed' => 150_000, 'status' => ReceivableStatus::Planned]);
    Receivable::factory()->for($appProject)->create(['amount_untaxed' => 100_000, 'status' => ReceivableStatus::Received]);

    $app = ['project_id' => 21, 'project_identifier' => 'mall-app', 'project_name' => '商城 APP'];
    $wushi = ['project_id' => 22, 'project_identifier' => 'wushi', 'project_name' => '我識'];
    verifyingIssue(['id' => 1, 'updated_on' => '2026-06-01 09:00']);
    verifyingIssue(['id' => 2, 'updated_on' => '2026-09-01 09:00']);
    verifyingIssue(['id' => 3, 'updated_on' => '2026-09-30 09:00']);
    verifyingIssue(['id' => 4, 'updated_on' => '2026-07-01 09:00', ...$wushi]);
    verifyingIssue(['id' => 5, 'updated_on' => '2026-09-30 09:00', ...$app]);
    verifyingIssue(['id' => 6, 'updated_on' => '2026-09-20 09:00', ...$app]);

    $queue = app(AcceptanceQueue::class);
    $summary = $queue->summary();

    expect($queue->queue()->pluck('id')->all())->toBe([6, 5, 4, 1, 2, 3])
        ->and($queue->queue('mall-app')->pluck('id')->all())->toBe([6, 5])
        ->and($queue->queue()->first())->toMatchArray(['is_closing' => true, 'target_close_date' => '2026-10-01', 'url' => 'http://redmine.test/issues/6'])
        ->and($summary['closing'])->toBe(['issues' => 3, 'projects' => 2, 'outstanding_taxed' => 157_500])
        ->and($summary['by_project'])->toBe([
            ['identifier' => 'mall-app', 'name' => '商城 APP', 'count' => 2, 'is_closing' => true, 'closing_project' => '商城 APP', 'target_close_date' => '2026-10-01', 'days_left' => -2, 'outstanding_taxed' => 157_500, 'oldest_waiting_days' => 13],
            ['identifier' => 'wushi', 'name' => '我識', 'count' => 1, 'is_closing' => true, 'closing_project' => '我識', 'target_close_date' => '2026-10-31', 'days_left' => 28, 'outstanding_taxed' => 0, 'oldest_waiting_days' => 94],
            ['identifier' => 'tcsb-5f-b2c', 'name' => '墊腳石 | 5F | B2C', 'count' => 3, 'is_closing' => false, 'closing_project' => null, 'target_close_date' => null, 'days_left' => null, 'outstanding_taxed' => null, 'oldest_waiting_days' => 124],
        ]);
});

it('reports weekly acceptances, handovers and the acceptor commits, with missing data as null', function () {
    SyncRun::factory()->create(['job' => SyncJob::RedmineIssues, 'started_at' => '2026-09-26 09:00', 'finished_at' => '2026-09-26 09:01']);

    acceptedIssue('2026-09-08 10:00');
    acceptedIssue('2026-09-23 10:00');
    acceptedIssue('2026-09-24 10:00');
    acceptedIssue('2026-09-24 11:00', assignee: '裕樺');
    acceptedIssue('2026-09-29 10:00');

    RedmineStatusChange::factory()->create(['issue_id' => 9001, 'changed_at' => '2026-09-26 12:00']);
    RedmineStatusChange::factory()->create(['issue_id' => 9002, 'changed_at' => '2026-09-30 12:00']);
    RedmineStatusChange::factory()->create(['issue_id' => 9002, 'changed_at' => '2026-10-01 12:00']);
    RedmineStatusChange::factory()->create(['issue_id' => 9003, 'changed_at' => '2026-10-02 12:00']);

    $howl = Developer::factory()->create(['name' => 'Howl', 'redmine_name' => '文豪']);
    $identity = GithubIdentity::factory()->for($howl)->create();
    GithubCommit::factory()->for($identity, 'identity')->count(3)->create(['authored_at' => '2026-09-30 10:00']);
    GithubCommit::factory()->for($identity, 'identity')->merge()->create(['authored_at' => '2026-09-30 11:00']);
    GithubCommit::factory()->for($identity, 'identity')->create(['authored_at' => '2026-09-22 10:00']);
    GithubCommit::factory()->create(['authored_at' => '2026-09-30 10:00']);

    $flow = app(AcceptanceQueue::class)->flow();

    expect($flow['weeks'])->toBe([
        ['week_start' => '2026-08-24', 'week_end' => '2026-08-30', 'is_current' => false, 'accepted' => 0, 'handed_over' => null, 'handed_over_partial' => false, 'acceptor_commits' => null],
        ['week_start' => '2026-08-31', 'week_end' => '2026-09-06', 'is_current' => false, 'accepted' => 0, 'handed_over' => null, 'handed_over_partial' => false, 'acceptor_commits' => null],
        ['week_start' => '2026-09-07', 'week_end' => '2026-09-13', 'is_current' => false, 'accepted' => 1, 'handed_over' => null, 'handed_over_partial' => false, 'acceptor_commits' => 0],
        ['week_start' => '2026-09-14', 'week_end' => '2026-09-20', 'is_current' => false, 'accepted' => 0, 'handed_over' => null, 'handed_over_partial' => false, 'acceptor_commits' => 0],
        ['week_start' => '2026-09-21', 'week_end' => '2026-09-27', 'is_current' => false, 'accepted' => 2, 'handed_over' => 1, 'handed_over_partial' => true, 'acceptor_commits' => 1],
        ['week_start' => '2026-09-28', 'week_end' => '2026-10-04', 'is_current' => true, 'accepted' => 1, 'handed_over' => 2, 'handed_over_partial' => false, 'acceptor_commits' => 3],
    ])->and($flow)->toMatchArray([
        'status_tracked_since' => '2026-09-26',
        'acceptor_developer' => 'Howl',
        'commits_retention_start' => '2026-09-03',
        'recent' => ['since' => '2026-09-26', 'handed_over' => 3, 'accepted' => 1],
        'is_growing' => true,
    ]);
});

it('projects when the queue clears from the last four complete weeks', function () {
    RedmineIssue::factory()->count(9)->create(['status' => RedmineIssue::STATUS_VERIFYING, 'assignee_name' => '文豪']);

    foreach (['2026-09-01 10:00', '2026-09-09 10:00', '2026-09-10 10:00', '2026-09-16 10:00', '2026-09-22 10:00', '2026-09-23 10:00'] as $closedOn) {
        acceptedIssue($closedOn);
    }
    acceptedIssue('2026-08-25 10:00');
    acceptedIssue('2026-09-29 10:00');

    expect(app(AcceptanceQueue::class)->flow())->toMatchArray([
        'queue' => 9,
        'avg_accepted_per_week' => 1.5,
        'weeks_to_clear' => 6.0,
        'projected_clear_date' => '2026-11-14',
        'recent' => null,
        'is_growing' => null,
    ]);
});

it('gives no projection when nothing was accepted in the last four complete weeks', function () {
    verifyingIssue();
    acceptedIssue('2026-08-25 10:00');
    acceptedIssue('2026-09-29 10:00');

    $flow = app(AcceptanceQueue::class)->flow();

    expect($flow)->toMatchArray(['queue' => 1, 'avg_accepted_per_week' => 0.0, 'weeks_to_clear' => null, 'projected_clear_date' => null])
        ->and(array_column($flow['weeks'], 'acceptor_commits'))->toBe([null, null, null, null, null, null]);
});
