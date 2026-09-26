<?php

use App\Domain\Delivery\DeliverySummary;
use App\Domain\Delivery\RedmineSnapshotter;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusSnapshot;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['services.redmine.acceptor_name' => '文豪']);
    $this->travelTo(CarbonImmutable::parse('2026-09-07 10:00'));
    $this->summary = app(DeliverySummary::class);
});

test('current summarises the open stock with the 驗證中 split and per-project rows sorted by open', function () {
    $create = fn (array $attributes) => RedmineIssue::factory()->create([
        'project_identifier' => 'tcsb-5f-b2c',
        'project_name' => '墊腳石 | 5F | B2C',
        'status' => '驗證中',
        'updated_on' => now()->subDay(),
        ...$attributes,
    ]);

    $create(['assignee_name' => '裕樺', 'created_on' => now()->subDays(240), 'updated_on' => now()->subDays(100)]);
    $create(['assignee_name' => '妤欣', 'created_on' => now()->subDays(250), 'updated_on' => now()->subDays(100), 'due_date' => '2026-01-01']);
    $create(['assignee_name' => '文豪', 'created_on' => now()->subDays(10)]);
    $create(['assignee_name' => null, 'status' => '新建立', 'created_on' => now()->subDays(20), 'updated_on' => now()->subDays(40)]);
    $create(['project_identifier' => 'iam', 'project_name' => '我識', 'assignee_name' => '文豪', 'created_on' => now()->subDays(15)]);
    $create(['project_identifier' => 'iam', 'project_name' => '我識', 'assignee_name' => null, 'created_on' => now()->subDays(5)]);
    $create(['is_closed' => true, 'status' => '已結案']);
    $create([])->delete();

    $current = $this->summary->current();

    expect($current)->toMatchArray([
        'open' => 6,
        'by_status' => ['驗證中' => 5, '新建立' => 1],
        'verifying' => ['acceptor' => 2, 'others' => 2, 'unassigned' => 1],
        'stalled_30d' => 3,
        'stalled_90d' => 2,
        'overdue' => 1,
        'unassigned' => 2,
    ])->and($current['projects'])->toBe([
        [
            'identifier' => 'tcsb-5f-b2c',
            'name' => '墊腳石 | 5F | B2C',
            'open' => 4,
            'verifying_acceptor' => 1,
            'verifying_others' => 2,
            'stalled_90d' => 2,
            'median_age_days' => 130, // 10, 20, 240, 250 → (20 + 240) / 2
        ],
        [
            'identifier' => 'iam',
            'name' => '我識',
            'open' => 2,
            'verifying_acceptor' => 1,
            'verifying_others' => 0,
            'stalled_90d' => 0,
            'median_age_days' => 10,
        ],
    ]);
});

test('current is empty-safe', function () {
    expect($this->summary->current())->toMatchArray([
        'open' => 0,
        'by_status' => [],
        'verifying' => ['acceptor' => 0, 'others' => 0, 'unassigned' => 0],
        'projects' => [],
    ]);
});

test('trend reads daily totals from snapshots, flagging reconstructed days', function () {
    RedmineStatusSnapshot::factory()->create(['snapshot_date' => '2026-06-01', 'count' => 999]); // outside 90 days
    RedmineStatusSnapshot::factory()->create(['snapshot_date' => '2026-09-01', 'project_identifier' => 'a', 'status' => RedmineSnapshotter::RECONSTRUCTED_STATUS, 'count' => 10, 'is_reconstructed' => true]);
    RedmineStatusSnapshot::factory()->create(['snapshot_date' => '2026-09-01', 'project_identifier' => 'b', 'status' => RedmineSnapshotter::RECONSTRUCTED_STATUS, 'count' => 5, 'is_reconstructed' => true]);
    RedmineStatusSnapshot::factory()->create(['snapshot_date' => '2026-09-06', 'status' => '驗證中', 'assignee_name' => '文豪', 'count' => 7, 'stalled_90d' => 1]);
    RedmineStatusSnapshot::factory()->create(['snapshot_date' => '2026-09-06', 'status' => '驗證中', 'assignee_name' => '裕樺', 'count' => 3, 'stalled_90d' => 3]);
    RedmineStatusSnapshot::factory()->create(['snapshot_date' => '2026-09-06', 'status' => '驗證中', 'assignee_name' => '', 'count' => 2]);
    RedmineStatusSnapshot::factory()->create(['snapshot_date' => '2026-09-06', 'status' => '新建立', 'assignee_name' => '', 'count' => 4]);

    expect($this->summary->trend())->toBe([
        ['date' => '2026-09-01', 'open' => 15, 'verifying_acceptor' => 0, 'verifying_others' => 0, 'stalled_90d' => 0, 'reconstructed' => true],
        ['date' => '2026-09-06', 'open' => 16, 'verifying_acceptor' => 7, 'verifying_others' => 3, 'stalled_90d' => 4, 'reconstructed' => false],
    ])->and($this->summary->trend(3))->toHaveCount(1);
});
