<?php

use App\Domain\Sales\ProjectPnl;
use App\Enums\DealStage;
use App\Enums\ProjectStatus;
use App\Enums\ReceivableStatus;
use App\Models\CostBaseline;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Developer;
use App\Models\GithubCommit;
use App\Models\GithubIdentity;
use App\Models\GithubRepo;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RedmineIssue;
use App\Models\RedmineTimeEntry;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00'));
});

/**
 * 近 30 天 as of 2026-10-03: 2026-09-04 – 2026-10-03.
 *
 * @return array<string, mixed>
 */
function projectPnl(string $period = 'last_30_days'): array
{
    return (new ProjectPnl)->calculate(...ProjectPnl::periodRange($period));
}

function pnlPerson(string $name): GithubIdentity
{
    return GithubIdentity::factory()->for(Developer::factory()->create(['name' => $name]))->create();
}

function pnlRepo(string $name, ?Project $project = null, ?Deal $deal = null): GithubRepo
{
    return GithubRepo::factory()->create(['name' => $name, 'full_name' => 'infolinktw/'.$name, 'project_id' => $project?->id, 'deal_id' => $deal?->id]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function pnlCommit(GithubIdentity $identity, GithubRepo $repo, string $authoredAt, array $attributes = []): GithubCommit
{
    return GithubCommit::factory()->for($repo, 'repo')->for($identity, 'identity')->create(['authored_at' => $authoredAt, ...$attributes]);
}

it('offers the last 30 days, this month and the last 7 days, ending today', function (string $period, string $from) {
    [$start, $end] = ProjectPnl::periodRange($period);

    expect($start->toDateTimeString())->toBe($from)
        ->and($end->toDateTimeString())->toBe('2026-10-03 23:59:59');
})->with([
    'last 30 days' => ['last_30_days', '2026-09-04 00:00:00'],
    'this month' => ['this_month', '2026-10-01 00:00:00'],
    'last 7 days' => ['last_7_days', '2026-09-27 00:00:00'],
    'unknown key falls back to 30 days' => ['all_time', '2026-09-04 00:00:00'],
]);

it('splits a person-day equally among the projects touched that day', function () {
    $apos = Project::factory()->create(['name' => 'APOS 2.0']);
    $hr = Project::factory()->create(['name' => 'HR 系統']);
    $yubin = pnlPerson('永彬');
    $pos = pnlRepo('pos', $apos);
    $care = pnlRepo('care', $hr);

    pnlCommit($yubin, $pos, '2026-10-01 09:00');
    pnlCommit($yubin, $pos, '2026-10-01 11:00');
    pnlCommit($yubin, $pos, '2026-10-01 15:00');
    pnlCommit($yubin, $care, '2026-10-01 20:00');
    pnlCommit($yubin, $pos, '2026-10-02 10:00');

    $pnl = projectPnl();
    $rows = collect($pnl['projects'])->keyBy('name');

    expect($pnl)->toMatchArray(['total_person_days' => 2.0, 'total_commits' => 5])
        ->and($pnl['projects'][0]['name'])->toBe('APOS 2.0')
        ->and($rows['APOS 2.0'])->toMatchArray([
            'person_days' => 1.5,
            'share' => 0.75,
            'commits' => 4,
            'by_person' => [['name' => '永彬', 'person_days' => 1.5, 'commits' => 4]],
            'repos' => [['full_name' => 'infolinktw/pos', 'commits' => 4]],
        ])
        ->and($rows['HR 系統'])->toMatchArray(['person_days' => 0.5, 'share' => 0.25, 'commits' => 1]);
});

it('counts each person separately, groups a developer\'s identities and keeps unmapped identities as their own person', function () {
    $project = Project::factory()->create();
    $repo = pnlRepo('pos', $project);
    $yubin = Developer::factory()->create(['name' => '永彬']);
    $work = GithubIdentity::factory()->for($yubin)->create();
    $home = GithubIdentity::factory()->for($yubin)->create();
    $kenneth = pnlPerson('Kenneth');
    $ghost = GithubIdentity::factory()->create(['login' => 'ghost-dev']);

    pnlCommit($work, $repo, '2026-10-01 09:00');
    pnlCommit($home, $repo, '2026-10-01 22:00');
    pnlCommit($work, $repo, '2026-10-02 09:00');
    pnlCommit($kenneth, $repo, '2026-10-01 09:00');
    pnlCommit($ghost, $repo, '2026-10-01 09:00');

    $pnl = projectPnl();

    expect($pnl['total_person_days'])->toBe(4.0)
        ->and($pnl['projects'][0]['by_person'])->toBe([
            ['name' => '永彬', 'person_days' => 2.0, 'commits' => 3],
            ['name' => 'Kenneth', 'person_days' => 1.0, 'commits' => 1],
            ['name' => '未對應：ghost-dev', 'person_days' => 1.0, 'commits' => 1],
        ]);
});

it('ignores merge commits and commits outside the period, and uses the calendar day of the app timezone', function () {
    $project = Project::factory()->create();
    $repo = pnlRepo('pos', $project);
    $yubin = pnlPerson('永彬');

    pnlCommit($yubin, $repo, '2026-10-01 23:30');
    pnlCommit($yubin, $repo, '2026-10-02 00:30');
    GithubCommit::factory()->merge()->for($repo, 'repo')->for($yubin, 'identity')->create(['authored_at' => '2026-09-30 10:00']);
    pnlCommit($yubin, $repo, '2026-09-03 23:00');

    expect(projectPnl())->toMatchArray(['total_person_days' => 2.0, 'total_commits' => 2]);
});

it('sends the commits of an overridden branch to that branch\'s project', function () {
    $apos = Project::factory()->create(['name' => 'APOS 2.0']);
    $hr = Project::factory()->create(['name' => 'HR 系統']);
    $repo = GithubRepo::factory()->branchProject('dycare-dev', $hr)->create(['project_id' => $apos->id]);
    $yubin = pnlPerson('永彬');

    pnlCommit($yubin, $repo, '2026-10-01 09:00', ['branch' => 'main']);
    pnlCommit($yubin, $repo, '2026-10-02 09:00', ['branch' => 'dycare-dev']);
    pnlCommit($yubin, $repo, '2026-10-03 09:00', ['branch' => 'dycare-dev-hotfix']);

    $rows = collect(projectPnl()['projects'])->pluck('person_days', 'name');

    expect($rows->all())->toBe(['APOS 2.0' => 2.0, 'HR 系統' => 1.0]);
});

it('falls back to the repo project when a branch override points at a deleted project', function () {
    $apos = Project::factory()->create(['name' => 'APOS 2.0']);
    $repo = GithubRepo::factory()->create(['project_id' => $apos->id, 'branch_projects' => [['branch' => 'dycare-dev', 'project_id' => 9_999]]]);

    pnlCommit(pnlPerson('永彬'), $repo, '2026-10-02 09:00', ['branch' => 'dycare-dev']);

    $pnl = projectPnl();

    expect($pnl['projects'][0])->toMatchArray(['name' => 'APOS 2.0', 'person_days' => 1.0])
        ->and($pnl['unmapped'])->toBe([]);
});

it('lists the effort on a repo tied to a deal as presales, and the project wins when a repo has both', function () {
    $deal = Deal::factory()->create(['prospect_name' => '多羅滿賞鯨', 'title' => '賞鯨訂位中樞', 'stage' => DealStage::Proposal]);
    $project = Project::factory()->create(['name' => 'APOS 2.0']);
    $ocean = pnlRepo('ocean-deep', deal: $deal);
    $both = pnlRepo('pos', $project, $deal);
    $kenneth = pnlPerson('Kenneth');

    pnlCommit($kenneth, $ocean, '2026-10-01 09:00');
    pnlCommit($kenneth, $ocean, '2026-10-02 09:00');
    pnlCommit($kenneth, $both, '2026-10-02 10:00');

    $pnl = projectPnl();

    expect($pnl['presales'])->toHaveCount(1)
        ->and($pnl['presales'][0])->toMatchArray([
            'deal_id' => $deal->id,
            'party' => '多羅滿賞鯨',
            'title' => '賞鯨訂位中樞',
            'stage' => 'proposal',
            'person_days' => 1.5,
            'share' => 0.75,
            'commits' => 2,
            'by_person' => [['name' => 'Kenneth', 'person_days' => 1.5, 'commits' => 2]],
            'repos' => [['full_name' => 'infolinktw/ocean-deep', 'commits' => 2]],
        ])
        ->and($pnl)->toMatchArray(['presales_person_days' => 1.5, 'presales_share' => 0.75])
        ->and($pnl['projects'][0])->toMatchArray(['name' => 'APOS 2.0', 'person_days' => 0.5]);
});

it('lists repos tied to nothing as unmapped, each as its own target, with their branches', function () {
    $project = Project::factory()->create();
    $mapped = pnlRepo('pos', $project);
    $tools = pnlRepo('tools');
    $lab = pnlRepo('lab');
    $yubin = pnlPerson('永彬');

    pnlCommit($yubin, $mapped, '2026-10-01 09:00');
    pnlCommit($yubin, $tools, '2026-10-01 10:00', ['branch' => 'main']);
    pnlCommit($yubin, $tools, '2026-10-01 11:00', ['branch' => 'next']);
    pnlCommit($yubin, $tools, '2026-10-01 12:00', ['branch' => 'next']);
    pnlCommit($yubin, $tools, '2026-10-02 12:00', ['branch' => 'next']);
    pnlCommit($yubin, $lab, '2026-10-01 13:00', ['branch' => 'main']);

    $pnl = projectPnl();

    expect($pnl['unmapped'])->toHaveCount(2)
        ->and($pnl['unmapped'][0])->toMatchArray([
            'repo_id' => $tools->id,
            'full_name' => 'infolinktw/tools',
            'url' => 'https://github.com/infolinktw/tools',
            'person_days' => 1.3,
            'commits' => 4,
            'branches' => ['next' => 3, 'main' => 1],
        ])
        ->and($pnl['unmapped'][1])->toMatchArray(['full_name' => 'infolinktw/lab', 'person_days' => 0.3, 'commits' => 1])
        ->and($pnl)->toMatchArray([
            'total_person_days' => 2.0,
            'unmapped_person_days' => 1.7,
            'unmapped_share' => 0.8333,
            'unmapped_commits' => 5,
            'unmapped_commit_share' => 0.8333,
        ]);
});

it('spreads the prorated monthly cost by person-days so the parts add up to it', function () {
    CostBaseline::factory()->create(['effective_from' => '2026-01-01', 'monthly_cost' => 100_000]);
    CostBaseline::factory()->create(['effective_from' => '2026-09-01', 'monthly_cost' => 229_666]);
    CostBaseline::factory()->create(['effective_from' => '2026-11-01', 'monthly_cost' => 999_999]);
    $deal = Deal::factory()->create();
    $yubin = pnlPerson('永彬');

    pnlCommit($yubin, pnlRepo('pos', Project::factory()->create(['name' => 'APOS 2.0'])), '2026-10-01 09:00');
    pnlCommit($yubin, pnlRepo('ocean-deep', deal: $deal), '2026-10-01 10:00');
    pnlCommit($yubin, pnlRepo('tools'), '2026-10-01 11:00');

    $month = projectPnl();
    $week = projectPnl('last_7_days');

    expect($month)->toMatchArray(['monthly_cost' => 229_666, 'period_cost' => 229_666, 'presales_estimated_cost' => 76_555])
        ->and($month['projects'][0]['estimated_cost'] + $month['presales'][0]['estimated_cost'] + $month['unmapped'][0]['estimated_cost'])->toBe(229_666)
        ->and($week['period'])->toBe(['from' => '2026-09-27', 'to' => '2026-10-03', 'days' => 7])
        ->and($week['period_cost'])->toBe(53_589)
        ->and($week['projects'][0]['estimated_cost'] + $week['presales'][0]['estimated_cost'] + $week['unmapped'][0]['estimated_cost'])->toBe(53_589);
});

it('leaves the cost and margin empty and says so when there is no cost baseline', function () {
    $project = Project::factory()->create();
    Receivable::factory()->for($project)->for($project->customer)->create(['amount_untaxed' => 100_000, 'expected_on' => '2026-10-01']);
    pnlCommit(pnlPerson('永彬'), pnlRepo('pos', $project), '2026-10-01 09:00');

    $pnl = projectPnl();

    expect($pnl)->toMatchArray(['monthly_cost' => null, 'period_cost' => null])
        ->and($pnl['missing']['cost_baseline'])->toBeTrue()
        ->and($pnl['projects'][0])->toMatchArray(['estimated_cost' => null, 'revenue_in_period' => 100_000, 'margin_estimate' => null]);
});

it('reports the money of a project: revenue expected in the period, received, outstanding and the recurring fee', function () {
    CostBaseline::factory()->create(['effective_from' => '2026-01-01', 'monthly_cost' => 240_000]);
    $customer = Customer::factory()->create(['short_name' => '墊腳石']);
    $project = Project::factory()->for($customer)->create(['name' => 'APOS 2.0', 'contract_amount_untaxed' => 1_200_000, 'status' => ProjectStatus::Closing]);
    $receivable = fn (array $attributes): Receivable => Receivable::factory()->for($customer)->for($project)->create(['tax_rate' => 0.05, ...$attributes]);

    $receivable(['item' => '簽約款', 'amount_untaxed' => 400_000, 'expected_on' => '2026-03-01', 'status' => ReceivableStatus::Received, 'received_on' => '2026-03-10']);
    $receivable(['item' => '維運費 9 月', 'amount_untaxed' => 100_000, 'expected_on' => '2026-09-05', 'status' => ReceivableStatus::Received, 'received_on' => '2026-09-20', 'is_recurring' => true]);
    $receivable(['item' => '維運費 10 月', 'amount_untaxed' => 100_000, 'expected_on' => '2026-10-05', 'is_recurring' => true]);
    $receivable(['item' => '維運費 11 月', 'amount_untaxed' => 120_000, 'expected_on' => '2026-11-05', 'is_recurring' => true]);
    $receivable(['item' => '尾款', 'amount_untaxed' => 300_000, 'expected_on' => '2026-09-30', 'status' => ReceivableStatus::Invoiced]);
    $receivable(['item' => '取消的變更單', 'amount_untaxed' => 50_000, 'expected_on' => '2026-09-30', 'status' => ReceivableStatus::Cancelled]);

    $yubin = pnlPerson('永彬');
    pnlCommit($yubin, pnlRepo('pos', $project), '2026-10-01 09:00');
    pnlCommit($yubin, pnlRepo('tools'), '2026-10-02 09:00');

    expect(projectPnl()['projects'][0])->toMatchArray([
        'name' => 'APOS 2.0',
        'customer' => '墊腳石',
        'status' => 'closing',
        'status_label' => '結案中',
        'contract_amount_untaxed' => 1_200_000,
        'estimated_cost' => 120_000,
        'revenue_in_period' => 400_000,
        'recurring_monthly' => 120_000,
        'margin_estimate' => 280_000,
        'received_in_period' => 100_000,
        'received_total' => 500_000,
        'outstanding_untaxed' => 520_000,
        'outstanding_taxed' => 546_000,
    ]);
});

it('keeps a project without a contract amount or anything expected in the period, and names what is missing', function () {
    CostBaseline::factory()->create(['effective_from' => '2026-01-01', 'monthly_cost' => 240_000]);
    $project = Project::factory()->create(['name' => 'HR 系統', 'contract_amount_untaxed' => null, 'redmine_project_id' => null]);
    $linked = Project::factory()->create(['name' => 'APOS 2.0', 'contract_amount_untaxed' => 500_000, 'redmine_project_id' => 21]);
    $yubin = pnlPerson('永彬');

    pnlCommit($yubin, pnlRepo('care', $project), '2026-10-01 09:00');
    pnlCommit($yubin, pnlRepo('pos', $linked), '2026-10-02 09:00');

    $pnl = projectPnl();
    $row = collect($pnl['projects'])->firstWhere('name', 'HR 系統');

    expect($row)->toMatchArray([
        'contract_amount_untaxed' => null,
        'estimated_cost' => 120_000,
        'revenue_in_period' => 0,
        'recurring_monthly' => null,
        'margin_estimate' => null,
        'redmine_linked' => false,
        'redmine_hours' => null,
    ])
        ->and($pnl['missing'])->toBe([
            'cost_baseline' => false,
            'contract_amount' => [['id' => $project->id, 'name' => 'HR 系統']],
            'redmine_link' => [['id' => $project->id, 'name' => 'HR 系統']],
        ]);
});

it('sums the Redmine hours logged in the period on the linked Redmine project', function () {
    $project = Project::factory()->create(['name' => 'APOS 2.0', 'redmine_project_id' => 21]);
    $quiet = Project::factory()->create(['name' => '商城', 'redmine_project_id' => 22]);
    RedmineIssue::factory()->create(['project_id' => 21, 'project_identifier' => 'apos']);
    RedmineIssue::factory()->create(['project_id' => 22, 'project_identifier' => 'mall']);
    RedmineTimeEntry::factory()->create(['project_identifier' => 'apos', 'hours' => 3.5, 'spent_on' => '2026-10-01']);
    RedmineTimeEntry::factory()->create(['project_identifier' => 'apos', 'hours' => 2.25, 'spent_on' => '2026-09-04']);
    RedmineTimeEntry::factory()->create(['project_identifier' => 'apos', 'hours' => 8, 'spent_on' => '2026-09-03']);
    RedmineTimeEntry::factory()->create(['project_identifier' => 'other', 'hours' => 4, 'spent_on' => '2026-10-01']);
    pnlCommit(pnlPerson('永彬'), pnlRepo('mall', $quiet), '2026-10-01 09:00');

    $rows = collect(projectPnl()['projects'])->keyBy('name');

    expect($rows['APOS 2.0'])->toMatchArray(['person_days' => 0.0, 'commits' => 0, 'redmine_linked' => true, 'redmine_hours' => 5.75])
        ->and($rows['商城'])->toMatchArray(['redmine_linked' => true, 'redmine_hours' => 0.0]);
});

it('leaves out projects with neither effort nor money in the period and counts them', function () {
    $active = Project::factory()->create(['name' => 'APOS 2.0']);
    $paid = Project::factory()->create(['name' => '商城']);
    Project::factory()->count(2)->create();
    Receivable::factory()->for($paid)->for($paid->customer)->create(['expected_on' => '2026-09-10', 'status' => ReceivableStatus::Received, 'received_on' => '2026-09-12']);
    pnlCommit(pnlPerson('永彬'), pnlRepo('pos', $active), '2026-10-01 09:00');

    $pnl = projectPnl();

    expect(array_column($pnl['projects'], 'name'))->toBe(['APOS 2.0', '商城'])
        ->and($pnl['omitted_projects'])->toBe(2);
});

it('rolls effort and received money up per customer, most effort first', function () {
    $stone = Customer::factory()->create(['short_name' => '墊腳石']);
    $care = Customer::factory()->create(['short_name' => '長照']);
    $old = Customer::factory()->create(['short_name' => '舊客戶']);
    Customer::factory()->create(['short_name' => '沒往來']);
    $apos = Project::factory()->for($stone)->create();
    $mall = Project::factory()->for($stone)->create();
    $hr = Project::factory()->for($care)->create();

    Receivable::factory()->for($stone)->for($apos)->create(['amount_untaxed' => 500_000, 'tax_rate' => 0.05, 'status' => ReceivableStatus::Received, 'received_on' => '2026-05-01', 'expected_on' => '2026-05-01']);
    Receivable::factory()->for($stone)->create(['amount_untaxed' => 100_000, 'tax_rate' => 0.05, 'status' => ReceivableStatus::Received, 'received_on' => '2026-06-01', 'expected_on' => '2026-06-01']);
    Receivable::factory()->for($care)->for($hr)->create(['amount_untaxed' => 200_000, 'tax_rate' => 0.05, 'expected_on' => '2026-12-01']);
    Receivable::factory()->for($old)->create(['amount_untaxed' => 400_000, 'tax_rate' => 0.05, 'status' => ReceivableStatus::Received, 'received_on' => '2025-12-01', 'expected_on' => '2025-12-01']);

    $yubin = pnlPerson('永彬');
    pnlCommit($yubin, pnlRepo('care', $hr), '2026-10-01 09:00');
    pnlCommit($yubin, pnlRepo('care-web', $hr), '2026-10-02 09:00');
    pnlCommit($yubin, pnlRepo('pos', $apos), '2026-10-03 09:00');
    pnlCommit($yubin, pnlRepo('mall', $mall), '2026-10-03 10:00');
    pnlCommit($yubin, pnlRepo('tools'), '2026-09-30 10:00');

    $pnl = projectPnl();

    expect($pnl['received_total'])->toBe(1_000_000)
        ->and($pnl['customers'])->toBe([
            ['id' => $care->id, 'name' => '長照', 'person_days' => 2.0, 'effort_share' => 0.5, 'commits' => 2, 'estimated_cost' => null, 'received_total' => 0, 'received_share' => 0.0, 'outstanding_taxed' => 210_000],
            ['id' => $stone->id, 'name' => '墊腳石', 'person_days' => 1.0, 'effort_share' => 0.25, 'commits' => 2, 'estimated_cost' => null, 'received_total' => 600_000, 'received_share' => 0.6, 'outstanding_taxed' => 0],
            ['id' => $old->id, 'name' => '舊客戶', 'person_days' => 0.0, 'effort_share' => 0.0, 'commits' => 0, 'estimated_cost' => null, 'received_total' => 400_000, 'received_share' => 0.4, 'outstanding_taxed' => 0],
        ]);
});

it('returns empty sections when nothing was committed in the period', function () {
    Project::factory()->create();

    expect(projectPnl())->toMatchArray([
        'total_person_days' => 0.0,
        'total_commits' => 0,
        'projects' => [],
        'omitted_projects' => 1,
        'customers' => [],
        'presales' => [],
        'presales_share' => null,
        'unmapped' => [],
        'unmapped_share' => null,
        'unmapped_commit_share' => null,
    ]);
});
