<?php

use App\Domain\Engineering\DevActivityReport;
use App\Domain\Engineering\RedmineActivity;
use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Models\Developer;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusChange;
use App\Models\RedmineTimeEntry;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['services.redmine.url' => 'http://redmine.test', 'services.redmine.acceptor_name' => '文豪']);
    $this->travelTo(CarbonImmutable::parse('2026-10-02 15:00'));

    $this->from = CarbonImmutable::parse('2026-09-28 00:00');
    $this->to = CarbonImmutable::parse('2026-10-02 23:59:59');

    $this->steven = Developer::factory()->create(['name' => 'Steven', 'redmine_name' => '鈺文']);
    $this->howl = Developer::factory()->create(['name' => 'Howl', 'redmine_name' => '文豪']);
    $this->roy = Developer::factory()->create(['name' => 'Roy']);

    RedmineIssue::factory()->create(['id' => 2810, 'subject' => '彈窗公告', 'status' => '驗證中', 'assignee_name' => '文豪']);
    RedmineIssue::factory()->create(['id' => 2742, 'subject' => '舊單', 'status' => '完成', 'is_closed' => true, 'assignee_name' => '鈺文']);
    RedmineIssue::factory()->create(['id' => 2700, 'subject' => '驗收過的', 'status' => '完成', 'is_closed' => true, 'assignee_name' => '文豪']);
    RedmineIssue::factory()->create(['id' => 2190, 'subject' => '雲端櫃', 'assignee_name' => '鈺文', 'updated_on' => '2026-08-18 10:00']);

    RedmineTimeEntry::factory()->create(['user_name' => '鈺文', 'issue_id' => 2810, 'hours' => 1.5, 'spent_on' => '2026-09-29']);
    RedmineTimeEntry::factory()->create(['user_name' => '鈺文', 'issue_id' => 2742, 'hours' => 0.5, 'spent_on' => '2026-09-29']);
    RedmineTimeEntry::factory()->create(['user_name' => '鈺文', 'issue_id' => 2742, 'hours' => 8, 'spent_on' => '2026-09-20']);
    RedmineTimeEntry::factory()->create(['user_name' => '妤欣', 'issue_id' => 2810, 'hours' => 3, 'spent_on' => '2026-09-29']);

    $change = fn (int $issue, string $from, string $to, string $previous, string $assignee, string $at) => RedmineStatusChange::factory()->create([
        'issue_id' => $issue, 'from_status' => $from, 'to_status' => $to, 'previous_assignee_name' => $previous, 'assignee_name' => $assignee, 'changed_at' => $at,
    ]);
    $change(2810, '新建立', '驗證中', '鈺文', '文豪', '2026-09-29 18:00');
    $change(2742, '新建立', '完成', '鈺文', '鈺文', '2026-09-30 09:00');
    $change(2700, '驗證中', '完成', '文豪', '文豪', '2026-10-01 11:00');
    $change(2999, '驗證中', '拒絕', '文豪', '文豪', '2026-10-01 12:00');
});

it('attributes hours, handovers, unverified closings and acceptance to developers by Redmine name', function () {
    $activity = (new RedmineActivity)->forDevelopers(Developer::all(), $this->from, $this->to);

    expect(array_keys($activity))->toEqualCanonicalizing([$this->steven->id, $this->howl->id])
        ->and($activity[$this->steven->id])->toMatchArray([
            'redmine_name' => '鈺文',
            'is_acceptor' => false,
            'hours' => 2.0,
            'hours_days' => 1,
            'advanced_to_verify' => 1,
            'closed_without_verify' => 1,
            'accepted' => null,
            'open_assigned' => 1,
            'verifying_assigned' => 0,
            'stalled_30d' => 1,
        ])
        ->and($activity[$this->steven->id]['issue_ids'])->toEqualCanonicalizing([2810, 2742])
        ->and($activity[$this->steven->id]['by_day'])->toBe([
            '2026-09-29' => ['hours' => 2.0, 'issue_ids' => [2742, 2810]],
            '2026-09-30' => ['hours' => 0.0, 'issue_ids' => [2742]],
        ])
        ->and($activity[$this->howl->id])->toMatchArray([
            'is_acceptor' => true,
            'advanced_to_verify' => 0,
            'closed_without_verify' => 0,
            'accepted' => 1,
            'open_assigned' => 1,
            'verifying_assigned' => 1,
        ]);
});

it('adds Redmine work to the people cards and the daily log, even without commits', function () {
    $report = new DevActivityReport;

    $people = $report->people($this->from, $this->to)->keyBy('name');

    expect($people['Steven'])->toMatchArray(['commits' => 0, 'issues' => 2])
        ->and($people['Steven']['redmine'])->toMatchArray(['hours' => 2.0, 'issues' => 2, 'advanced_to_verify' => 1, 'closed_without_verify' => 1])
        ->and($people['Steven']['redmine'])->not->toHaveKeys(['issue_ids', 'by_day'])
        ->and($people['Roy']['redmine'])->toBeNull();

    $log = $report->dailyLog($this->from, $this->to)->keyBy('date');

    expect($log->keys()->all())->toBe(['2026-10-01', '2026-09-30', '2026-09-29'])
        ->and($log['2026-09-29']['people'][0])->toMatchArray(['name' => 'Steven', 'commits' => 0, 'redmine_hours' => 2.0, 'repos' => []])
        ->and(array_column($log['2026-09-29']['people'][0]['issues'], 'id'))->toBe([2742, 2810])
        ->and($log['2026-10-01']['people'][0]['name'])->toBe('Howl');
});

it('knows since when status changes are tracked', function () {
    expect((new RedmineActivity)->statusTrackedSince())->toBeNull();

    SyncRun::factory()->create(['job' => SyncJob::RedmineIssues, 'status' => SyncStatus::Failed, 'started_at' => '2026-09-25 09:00']);
    SyncRun::factory()->create(['job' => SyncJob::RedmineIssues, 'status' => SyncStatus::Ok, 'started_at' => '2026-09-26 13:27']);

    expect((new RedmineActivity)->statusTrackedSince()->format('Y-m-d H:i'))->toBe('2026-09-26 13:27');
});
