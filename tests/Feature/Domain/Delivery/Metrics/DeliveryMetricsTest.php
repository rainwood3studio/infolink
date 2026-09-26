<?php

use App\Domain\Delivery\DeliveryMetrics;
use App\Models\MetricValue;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusChange;
use App\Models\RedmineTimeEntry;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;
use Database\Seeders\MetricDefinitionSeeder;

beforeEach(function () {
    config(['services.redmine.acceptor_name' => '文豪']);
    $this->seed(MetricDefinitionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-09-07 10:00')); // Monday after W36
    $this->metrics = app(DeliveryMetrics::class);
    // Status changes are only tracked from the first successful issue sync; fixtures are in W36 (2026-08-31).
    SyncRun::factory()->create(['started_at' => '2026-08-01 00:00']);
});

function metricValue(string $key, string $period, string $dimension = ''): ?float
{
    $value = MetricValue::where('metric_key', $key)->whereDate('period_start', $period)->where('dimension', $dimension)->value('value');

    return $value === null ? null : (float) $value;
}

/**
 * A scaled-down W36 (2026-08-31 ~ 2026-09-06): 5 created (4 to the acceptor), 8 closed (7 by the acceptor, one since reopened).
 */
function seedW36(): void
{
    $week = CarbonImmutable::parse('2026-08-31');

    foreach (['文豪', '文豪', '文豪', '文豪', '鈺文'] as $day => $assignee) {
        RedmineIssue::factory()->create(['project_identifier' => 'iam-monster-one', 'assignee_name' => $assignee, 'created_on' => $week->addDays($day)->setTime(9, 0)]);
    }

    foreach (range(0, 6) as $index) {
        RedmineIssue::factory()->create([
            'project_identifier' => $index < 5 ? 'iam' : 'tcsb',
            'assignee_name' => $index === 6 ? '永彬' : '文豪',
            'status' => '已結案',
            'is_closed' => true,
            'created_on' => '2026-07-01',
            'closed_on' => $week->addDays($index)->setTime(23, 59, 59),
        ]);
    }

    // Boundaries: Sunday before and Monday after are outside the week.
    RedmineIssue::factory()->create(['created_on' => '2026-08-30 23:59:59', 'is_closed' => true, 'closed_on' => '2026-09-07 00:00:00']);
    // A closure that happened in the week still counts after the issue is reopened (as the weekly report counted it).
    RedmineIssue::factory()->create(['project_identifier' => 'tcsb', 'assignee_name' => '文豪', 'created_on' => '2026-07-01', 'is_closed' => false, 'closed_on' => '2026-09-02 10:00']);
    // Soft-deleted issues are ignored.
    RedmineIssue::factory()->create(['created_on' => '2026-09-01 10:00'])->delete();
}

test('weekly stats count created, closed, net flow, acceptor throughput and inflow ratio (Monday–Sunday)', function () {
    seedW36();

    $stats = $this->metrics->weekStats(CarbonImmutable::parse('2026-09-03')); // any day in the week

    expect($stats)->toMatchArray([
        'week_start' => '2026-08-31',
        'week_end' => '2026-09-06',
        'created' => 5,
        'closed' => 8,
        'net_flow' => -3,
        'wenhao_throughput' => 7,
        'created_assigned_to_acceptor' => 4,
        'inflow_to_wenhao_ratio' => 0.8,
        'created_by_project' => ['iam-monster-one' => 5],
        'closed_by_project' => ['iam' => 5, 'tcsb' => 3],
    ]);
});

test('advanced to verify counts distinct issues moved to 驗證中 in the week, attributed to who handed it over', function () {
    RedmineStatusChange::factory()->create(['issue_id' => 1, 'to_status' => '驗證中', 'assignee_name' => '文豪', 'previous_assignee_name' => '永彬', 'changed_at' => '2026-09-01 10:00']);
    RedmineStatusChange::factory()->create(['issue_id' => 1, 'to_status' => '驗證中', 'assignee_name' => '文豪', 'previous_assignee_name' => '鈺文', 'changed_at' => '2026-09-03 10:00']);
    RedmineStatusChange::factory()->create(['issue_id' => 2, 'to_status' => '驗證中', 'assignee_name' => '裕樺', 'previous_assignee_name' => null, 'changed_at' => '2026-09-06 23:00']);
    RedmineStatusChange::factory()->create(['issue_id' => 3, 'to_status' => '驗證中', 'assignee_name' => null, 'changed_at' => '2026-09-02 10:00']);
    RedmineStatusChange::factory()->create(['issue_id' => 4, 'to_status' => '實作中', 'changed_at' => '2026-09-02 10:00']);
    RedmineStatusChange::factory()->create(['issue_id' => 5, 'to_status' => '驗證中', 'changed_at' => '2026-09-07 00:00']);

    $stats = $this->metrics->weekStats(CarbonImmutable::parse('2026-08-31'));

    expect($stats['advanced_to_verify'])->toBe(3)
        ->and($stats['advanced_to_verify_by_assignee'])->toEqualCanonicalizing(['永彬' => 1, '裕樺' => 1, '(未指派)' => 1]);
});

test('hours logged sum time entries by spent_on in the week, per user', function () {
    RedmineTimeEntry::factory()->create(['user_name' => '永彬', 'hours' => 6.5, 'spent_on' => '2026-08-31']);
    RedmineTimeEntry::factory()->create(['user_name' => '永彬', 'hours' => 15, 'spent_on' => '2026-09-06']);
    RedmineTimeEntry::factory()->create(['user_name' => '鈺文', 'hours' => 3, 'spent_on' => '2026-09-02']);
    RedmineTimeEntry::factory()->create(['user_name' => '文豪', 'hours' => 1, 'spent_on' => '2026-09-04']);
    RedmineTimeEntry::factory()->create(['user_name' => '文豪', 'hours' => 9, 'spent_on' => '2026-09-07']);

    $stats = $this->metrics->weekStats(CarbonImmutable::parse('2026-08-31'));

    expect($stats['hours_logged'])->toBe(25.5)
        ->and($stats['hours_by_user'])->toBe(['永彬' => 21.5, '鈺文' => 3.0, '文豪' => 1.0]);
});

test('recordWeekly writes the weekly metrics on Monday, idempotently', function () {
    seedW36();
    RedmineStatusChange::factory()->create(['to_status' => '驗證中', 'previous_assignee_name' => '永彬', 'changed_at' => '2026-09-02 10:00']);
    RedmineTimeEntry::factory()->create(['user_name' => '永彬', 'hours' => 2.5, 'spent_on' => '2026-09-01']);

    $this->metrics->recordWeekly(CarbonImmutable::parse('2026-09-05'));
    $this->metrics->recordWeekly(CarbonImmutable::parse('2026-08-31'));

    expect(metricValue('delivery.created', '2026-08-31'))->toBe(5.0)
        ->and(metricValue('delivery.closed', '2026-08-31'))->toBe(8.0)
        ->and(metricValue('delivery.net_flow', '2026-08-31'))->toBe(-3.0)
        ->and(metricValue('delivery.wenhao_throughput', '2026-08-31'))->toBe(7.0)
        ->and(metricValue('delivery.inflow_to_wenhao_ratio', '2026-08-31'))->toBe(0.8)
        ->and(metricValue('delivery.advanced_to_verify', '2026-08-31'))->toBe(1.0)
        ->and(metricValue('delivery.advanced_to_verify', '2026-08-31', 'assignee:永彬'))->toBe(1.0)
        ->and(metricValue('delivery.hours_logged', '2026-08-31'))->toBe(2.5)
        ->and(metricValue('delivery.hours_logged', '2026-08-31', 'user:永彬'))->toBe(2.5)
        ->and(MetricValue::count())->toBe(9);
});

test('the inflow ratio is not recorded for a week without new issues', function () {
    $this->metrics->recordWeekly(CarbonImmutable::parse('2026-08-31'));

    expect(metricValue('delivery.created', '2026-08-31'))->toBe(0.0)
        ->and(metricValue('delivery.inflow_to_wenhao_ratio', '2026-08-31'))->toBeNull();
});

test('recordDaily splits 驗證中 into the acceptor queue and other named assignees', function () {
    $create = fn (int $count, array $attributes) => RedmineIssue::factory()->count($count)->create([
        'project_identifier' => 'tcsb-5f-b2c',
        'status' => '驗證中',
        'updated_on' => now()->subDays(3),
        ...$attributes,
    ]);

    $create(4, ['assignee_name' => '文豪', 'project_identifier' => 'tcsb']);
    $create(3, ['assignee_name' => '裕樺', 'updated_on' => now()->subDays(120)]);
    $create(2, ['assignee_name' => '妤欣']);
    $create(1, ['assignee_name' => '鈺文']);   // part of the "remainder": still others
    $create(1, ['assignee_name' => null]);     // unassigned 驗證中: neither bucket
    $create(2, ['assignee_name' => null, 'status' => '新建立', 'due_date' => '2026-09-01', 'updated_on' => now()->subDays(40)]);
    $create(1, ['assignee_name' => '文豪', 'status' => '已結案', 'is_closed' => true]);

    $this->metrics->recordDaily();

    expect(metricValue('delivery.open', '2026-09-07'))->toBe(13.0)
        ->and(metricValue('delivery.open', '2026-09-07', 'project:tcsb'))->toBe(4.0)
        ->and(metricValue('delivery.open', '2026-09-07', 'project:tcsb-5f-b2c'))->toBe(9.0)
        ->and(metricValue('delivery.verifying.wenhao', '2026-09-07'))->toBe(4.0)
        ->and(metricValue('delivery.verifying.others', '2026-09-07'))->toBe(6.0)
        ->and(metricValue('delivery.verifying.others', '2026-09-07', 'project:tcsb-5f-b2c'))->toBe(6.0)
        ->and(metricValue('delivery.stalled_30d', '2026-09-07'))->toBe(5.0)
        ->and(metricValue('delivery.stalled_90d', '2026-09-07'))->toBe(3.0)
        ->and(metricValue('delivery.stalled_90d', '2026-09-07', 'project:tcsb'))->toBe(0.0)
        ->and(metricValue('delivery.overdue', '2026-09-07'))->toBe(2.0)
        ->and(metricValue('delivery.unassigned', '2026-09-07'))->toBe(3.0);
});

test('recordDaily is idempotent and zeroes project dimensions that disappeared', function () {
    $gone = RedmineIssue::factory()->create(['project_identifier' => 'gone']);
    RedmineIssue::factory()->create(['project_identifier' => 'kept']);
    $this->metrics->recordDaily();

    $gone->update(['is_closed' => true, 'status' => '已結案']);
    $this->metrics->recordDaily();

    expect(metricValue('delivery.open', '2026-09-07'))->toBe(1.0)
        ->and(metricValue('delivery.open', '2026-09-07', 'project:gone'))->toBe(0.0)
        ->and(MetricValue::where('metric_key', 'delivery.open')->count())->toBe(3);
});

test('weeks that ended before status tracking began have no advanced-to-verify data', function () {
    SyncRun::query()->delete();
    SyncRun::factory()->create(['started_at' => '2026-09-26 13:00']);

    expect($this->metrics->weekStats(CarbonImmutable::parse('2026-09-14'))['advanced_to_verify'])->toBeNull()
        ->and($this->metrics->weekStats(CarbonImmutable::parse('2026-09-21'))['advanced_to_verify'])->toBe(0);

    $this->metrics->recordWeekly(CarbonImmutable::parse('2026-09-14'));

    expect(MetricValue::where('metric_key', 'delivery.advanced_to_verify')->exists())->toBeFalse();
});
