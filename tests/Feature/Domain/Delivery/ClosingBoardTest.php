<?php

use App\Domain\Delivery\ClosingBoard;
use App\Enums\ProjectStatus;
use App\Enums\ReceivableStatus;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusSnapshot;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['services.redmine.url' => 'http://redmine.test', 'services.redmine.acceptor_name' => '文豪']);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00'));
});

/**
 * A closing project linked to Redmine project 21 (`mall-app`) unless overridden.
 *
 * @param  array<string, mixed>  $attributes
 */
function closingBoardProject(array $attributes = []): Project
{
    return Project::factory()->create([
        'name' => '商城 APP',
        'status' => ProjectStatus::Closing,
        'redmine_project_id' => 21,
        'target_close_date' => '2026-10-05',
        ...$attributes,
    ]);
}

/**
 * Open mirror issues of Redmine project 21 (`mall-app`).
 *
 * @param  array<string, mixed>  $attributes
 */
function closingBoardIssues(int $count, array $attributes = []): void
{
    RedmineIssue::factory()->count($count)->create([
        'project_id' => 21,
        'project_identifier' => 'mall-app',
        'status' => '實作中',
        'assignee_name' => '裕樺',
        ...$attributes,
    ]);
}

function closingBoardSnapshot(string $date, int $count, array $attributes = []): void
{
    RedmineStatusSnapshot::factory()->create([
        'snapshot_date' => $date,
        'project_identifier' => 'mall-app',
        'count' => $count,
        ...$attributes,
    ]);
}

it('splits a project\'s open issues by who they are stuck on', function () {
    closingBoardProject();
    closingBoardIssues(1, ['status' => '新建立', 'assignee_name' => null]);
    closingBoardIssues(1, ['status' => '實作中', 'assignee_name' => '裕樺', 'updated_on' => '2026-08-20 09:00']);
    closingBoardIssues(2, ['status' => '驗證中', 'assignee_name' => '文豪']);
    closingBoardIssues(1, ['status' => '驗證中', 'assignee_name' => '裕樺']);
    closingBoardIssues(1, ['status' => '驗證中', 'assignee_name' => null]);
    closingBoardIssues(1, ['status' => '已結案', 'is_closed' => true]);
    RedmineIssue::factory()->create(['project_id' => 15]);

    $project = (new ClosingBoard)->projects()->sole();

    expect($project)->toMatchArray([
        'name' => '商城 APP',
        'redmine_linked' => true,
        'redmine_identifiers' => ['mall-app'],
        'open' => 6,
        'by_stage' => ['unassigned' => 1, 'in_progress' => 1, 'off_flow' => 2, 'awaiting_acceptance' => 2],
        'by_status' => ['驗證中' => 4, '新建立' => 1, '實作中' => 1],
        'by_assignee' => [
            ['name' => '(未指派)', 'count' => 2, 'is_acceptor' => false],
            ['name' => '文豪', 'count' => 2, 'is_acceptor' => true],
            ['name' => '裕樺', 'count' => 2, 'is_acceptor' => false],
        ],
        'stalled_30d' => 1,
    ]);
});

it('sums only the outstanding receivables of the project and flags the overdue ones', function () {
    $project = closingBoardProject();
    $late = Receivable::factory()->for($project)->create(['item' => '期中款', 'amount_untaxed' => 100_000, 'expected_on' => '2026-09-20']);
    $final = Receivable::factory()->for($project)->create(['item' => '尾款', 'amount_untaxed' => 200_000, 'expected_on' => '2026-10-31', 'status' => ReceivableStatus::Invoiced]);
    Receivable::factory()->for($project)->create(['amount_untaxed' => 500_000, 'status' => ReceivableStatus::Received]);
    Receivable::factory()->create(['amount_untaxed' => 900_000]);

    $row = (new ClosingBoard)->projects()->sole();

    expect($row)->toMatchArray([
        'outstanding_taxed' => 315_000,
        'outstanding_overdue_taxed' => 105_000,
        'next_expected_on' => '2026-09-20',
        'receivables' => [
            ['id' => $late->id, 'item' => '期中款', 'amount_taxed' => 105_000, 'expected_on' => '2026-09-20', 'is_overdue' => true],
            ['id' => $final->id, 'item' => '尾款', 'amount_taxed' => 210_000, 'expected_on' => '2026-10-31', 'is_overdue' => false],
        ],
    ]);
});

it('projects the close date from the net burn since the snapshot seven days ago', function () {
    closingBoardProject(['target_close_date' => '2026-10-05']);
    closingBoardIssues(6);
    closingBoardSnapshot('2026-09-26', 12, ['status' => '實作中', 'assignee_name' => '裕樺']);
    closingBoardSnapshot('2026-09-26', 8, ['status' => '驗證中', 'assignee_name' => '文豪']);
    closingBoardSnapshot('2026-09-26', 50, ['project_identifier' => 'other-project']);
    closingBoardSnapshot('2026-10-01', 9, ['status' => '(重建)', 'is_reconstructed' => true]);
    closingBoardSnapshot('2026-10-03', 99);
    closingBoardSnapshot('2026-09-01', 40);

    $project = (new ClosingBoard)->projects()->sole();

    expect($project)->toMatchArray([
        'trend' => [
            ['date' => '2026-09-26', 'open' => 20],
            ['date' => '2026-10-01', 'open' => 9],
            ['date' => '2026-10-03', 'open' => 6],
        ],
        'burn_from' => ['date' => '2026-09-26', 'open' => 20, 'days_ago' => 7],
        'burn_per_day' => 2.0,
        'projection' => 'projected',
        'projected_close_date' => '2026-10-06',
        'days_late' => 1,
    ]);
});

it('measures the burn from the nearest snapshot before the window when that day has none', function () {
    closingBoardProject(['target_close_date' => '2026-10-31']);
    closingBoardIssues(6);
    closingBoardSnapshot('2026-09-24', 24);
    closingBoardSnapshot('2026-09-30', 10);

    $project = (new ClosingBoard)->projects()->sole();

    expect($project)->toMatchArray([
        'burn_from' => ['date' => '2026-09-24', 'open' => 24, 'days_ago' => 9],
        'burn_per_day' => 2.0,
        'projected_close_date' => '2026-10-06',
        'days_late' => -25,
    ]);
});

it('gives no close date when the open count is not shrinking', function () {
    closingBoardProject();
    closingBoardIssues(6);
    closingBoardSnapshot('2026-09-26', 4);

    $project = (new ClosingBoard)->projects()->sole();

    expect($project)->toMatchArray([
        'burn_per_day' => -0.29,
        'projection' => 'not_converging',
        'projected_close_date' => null,
        'days_late' => null,
    ]);
});

it('gives no close date when no snapshot is at least seven days old', function () {
    closingBoardProject();
    closingBoardIssues(6);
    closingBoardSnapshot('2026-09-30', 20);

    $project = (new ClosingBoard)->projects()->sole();

    expect($project)->toMatchArray([
        'burn_from' => null,
        'burn_per_day' => null,
        'projection' => 'no_history',
        'projected_close_date' => null,
    ]);
});

it('counts a snapshot day without rows for the project as zero open', function () {
    closingBoardProject();
    closingBoardIssues(3);
    closingBoardSnapshot('2026-09-26', 50, ['project_identifier' => 'other-project']);

    $project = (new ClosingBoard)->projects()->sole();

    expect($project)->toMatchArray([
        'trend' => [['date' => '2026-09-26', 'open' => 0], ['date' => '2026-10-03', 'open' => 3]],
        'projection' => 'not_converging',
    ]);
});

it('marks projects without open issues or without a Redmine link instead of projecting', function () {
    closingBoardProject(['name' => '已清空']);
    closingBoardIssues(1, ['status' => '已結案', 'is_closed' => true]);
    closingBoardSnapshot('2026-09-26', 5);
    closingBoardProject(['name' => '未連結', 'redmine_project_id' => null]);

    $projects = (new ClosingBoard)->projects()->keyBy('name');

    expect($projects['已清空'])->toMatchArray(['redmine_linked' => true, 'open' => 0, 'projection' => 'cleared', 'projected_close_date' => null])
        ->and($projects['未連結'])->toMatchArray(['redmine_linked' => false, 'open' => 0, 'projection' => 'not_linked', 'trend' => [['date' => '2026-10-03', 'open' => 0]]]);
});

it('lists only closing projects, most urgent target first and projects without a target last', function () {
    $customer = Customer::factory()->create(['short_name' => '墊腳石']);
    closingBoardProject(['name' => '沒目標', 'redmine_project_id' => null, 'target_close_date' => null]);
    closingBoardProject(['name' => '月底', 'redmine_project_id' => null, 'target_close_date' => '2026-10-31']);
    closingBoardProject(['name' => '已過期', 'redmine_project_id' => null, 'target_close_date' => '2026-10-01', 'customer_id' => $customer->id]);
    closingBoardProject(['name' => '進行中', 'status' => ProjectStatus::Active]);

    $projects = (new ClosingBoard)->projects();

    expect($projects->pluck('name')->all())->toBe(['已過期', '月底', '沒目標'])
        ->and($projects[0])->toMatchArray(['customer' => '墊腳石', 'target_close_date' => '2026-10-01', 'days_left' => -2, 'is_overdue' => true])
        ->and($projects[1])->toMatchArray(['days_left' => 28, 'is_overdue' => false])
        ->and($projects[2])->toMatchArray(['target_close_date' => null, 'days_left' => null, 'is_overdue' => false]);
});

it('totals money, open issues and the projects at risk', function () {
    $overdue = closingBoardProject(['name' => '已過期', 'target_close_date' => '2026-10-01']);
    closingBoardIssues(4);
    closingBoardSnapshot('2026-09-26', 4);
    Receivable::factory()->for($overdue)->create(['amount_untaxed' => 100_000, 'expected_on' => '2026-09-20']);

    closingBoardProject(['name' => '會晚', 'redmine_project_id' => 22, 'target_close_date' => '2026-10-04']);
    closingBoardIssues(6, ['project_id' => 22, 'project_identifier' => 'apos']);
    closingBoardSnapshot('2026-09-26', 13, ['project_identifier' => 'apos']);

    $stuck = closingBoardProject(['name' => '沒在減少', 'redmine_project_id' => 23, 'target_close_date' => '2026-10-31']);
    closingBoardIssues(2, ['project_id' => 23, 'project_identifier' => 'hr']);
    closingBoardSnapshot('2026-09-26', 2, ['project_identifier' => 'hr']);
    Receivable::factory()->for($stuck)->create(['amount_untaxed' => 200_000, 'expected_on' => '2026-10-31']);

    $summary = (new ClosingBoard)->summary();

    expect($summary)->toBe([
        'projects' => 3,
        'open' => 12,
        'outstanding_taxed' => 315_000,
        'outstanding_overdue_taxed' => 105_000,
        'overdue' => 1,
        'projected_late' => 1,
        'not_converging' => 1,
        'at_risk' => 3,
    ]);
});

it('lists a project\'s open issues by stage, longest without an update first', function () {
    $project = closingBoardProject();
    closingBoardIssues(1, ['id' => 101, 'status' => '驗證中', 'assignee_name' => '文豪', 'updated_on' => '2026-07-01 09:00']);
    closingBoardIssues(1, ['id' => 102, 'status' => '驗證中', 'assignee_name' => '裕樺', 'updated_on' => '2026-09-30 09:00']);
    closingBoardIssues(1, ['id' => 103, 'status' => '實作中', 'assignee_name' => '裕樺', 'updated_on' => '2026-10-02 09:00']);
    closingBoardIssues(1, [
        'id' => 104, 'subject' => '結帳金額錯誤', 'status' => '研擬中', 'assignee_name' => '妤欣', 'priority' => '高', 'tracker' => 'Bug',
        'created_on' => '2026-08-04 09:00', 'updated_on' => '2026-08-24 09:00', 'due_date' => '2026-09-30',
    ]);
    closingBoardIssues(1, ['id' => 105, 'status' => '新建立', 'assignee_name' => null, 'updated_on' => '2026-10-03 08:00']);
    closingBoardIssues(1, ['id' => 106, 'status' => '已結案', 'is_closed' => true]);

    $issues = (new ClosingBoard)->issues($project);

    expect($issues->pluck('stage', 'id')->all())->toBe([
        105 => 'unassigned',
        104 => 'in_progress',
        103 => 'in_progress',
        102 => 'off_flow',
        101 => 'awaiting_acceptance',
    ])->and($issues[1])->toBe([
        'id' => 104,
        'subject' => '結帳金額錯誤',
        'status' => '研擬中',
        'stage' => 'in_progress',
        'assignee' => '妤欣',
        'priority' => '高',
        'tracker' => 'Bug',
        'days_since_update' => 40,
        'days_since_created' => 60,
        'due_date' => '2026-09-30',
        'is_overdue' => true,
        'url' => 'http://redmine.test/issues/104',
    ])->and($issues[0])->toMatchArray(['assignee' => null, 'days_since_update' => 0, 'due_date' => null, 'is_overdue' => false]);
});

it('has no issues for a project without a Redmine link', function () {
    $project = closingBoardProject(['redmine_project_id' => null]);
    RedmineIssue::factory()->create();

    expect((new ClosingBoard)->issues($project))->toBeEmpty();
});
