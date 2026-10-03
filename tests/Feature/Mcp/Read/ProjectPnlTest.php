<?php

use App\Enums\ReceivableStatus;
use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\ProjectPnlSummary;
use App\Models\CostBaseline;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Developer;
use App\Models\GithubCommit;
use App\Models\GithubIdentity;
use App\Models\GithubRepo;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\SyncRun;

beforeEach(function () {
    $this->travelTo('2026-10-03 10:00:00');
});

test('project_pnl returns person-days, estimated cost and money per project, with presales and unmapped repos', function () {
    CostBaseline::factory()->create(['effective_from' => '2026-01-01', 'monthly_cost' => 240_000]);
    SyncRun::factory()->create(['job' => SyncJob::GithubActivity, 'status' => SyncStatus::Ok, 'started_at' => '2026-10-03 09:00:00', 'finished_at' => '2026-10-03 09:01:00']);

    $stone = Customer::factory()->create(['short_name' => '墊腳石']);
    $apos = Project::factory()->for($stone)->create(['name' => 'APOS 2.0', 'contract_amount_untaxed' => null]);
    $deal = Deal::factory()->create(['prospect_name' => '多羅滿賞鯨', 'title' => '賞鯨訂位中樞']);
    Receivable::factory()->for($stone)->for($apos)->create(['amount_untaxed' => 400_000, 'tax_rate' => 0.05, 'expected_on' => '2026-03-01', 'status' => ReceivableStatus::Received, 'received_on' => '2026-03-10']);
    Receivable::factory()->for($stone)->for($apos)->create(['amount_untaxed' => 100_000, 'tax_rate' => 0.05, 'expected_on' => '2026-10-01', 'is_recurring' => true]);

    $yubin = GithubIdentity::factory()->for(Developer::factory()->create(['name' => '永彬']))->create();
    $commit = fn (GithubRepo $repo, string $authoredAt): GithubCommit => GithubCommit::factory()->for($repo, 'repo')->for($yubin, 'identity')->create(['authored_at' => $authoredAt, 'branch' => 'main']);

    $commit(GithubRepo::factory()->create(['full_name' => 'infolinktw/pos', 'project_id' => $apos->id]), '2026-10-01 09:00');
    $commit(GithubRepo::factory()->create(['full_name' => 'infolinktw/ocean-deep', 'deal_id' => $deal->id]), '2026-09-10 09:00');
    $commit(GithubRepo::factory()->create(['full_name' => 'infolinktw/tools']), '2026-09-11 09:00');

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(ProjectPnlSummary::class)
        ->assertOk()
        ->assertSee([
            '"generated_at":"2026-10-03T10:00:00+08:00"',
            '"window":{"from":"2026-09-04","to":"2026-10-03","days":30,"retention_start":"2026-09-03"}',
            '"github_activity":{"last_status":"ok","last_run_at":"2026-10-03T09:00:00+08:00","last_ok_at":"2026-10-03T09:01:00+08:00","last_error":null}',
            '"redmine_time":{"last_status":null',
            '"monthly_cost":240000,"period_cost":240000,"total_person_days":3,"total_commits":3,"omitted_projects":0',
            '"customers":[{"id":'.$stone->id.',"name":"墊腳石","person_days":1,"effort_share":0.3333,"commits":1,"estimated_cost":80000,"received_total":400000,"received_share":1,"outstanding_taxed":105000}]',
            '"presales_person_days":1,"presales_share":0.3333,"presales_estimated_cost":80000',
            '"unmapped_person_days":1,"unmapped_share":0.3333,"unmapped_commits":1,"unmapped_commit_share":0.3333',
            '"missing":{"cost_baseline":false,"contract_amount":[{"id":'.$apos->id.',"name":"APOS 2.0"}],"redmine_link":[{"id":'.$apos->id.',"name":"APOS 2.0"}]}',
            '"name":"APOS 2.0","customer":"墊腳石","status":"active","person_days":1,"share":0.3333,"commits":1,"by_person":[["永彬",1,1]],"repos":{"infolinktw/pos":1},"estimated_cost":80000,"contract_amount_untaxed":null,"revenue_in_period":100000,"recurring_monthly":100000,"margin_estimate":20000,"received_in_period":0,"received_total":400000,"outstanding_untaxed":100000,"outstanding_taxed":105000,"redmine_linked":false,"redmine_hours":null',
            '"presales":[{"deal_id":'.$deal->id.',"party":"多羅滿賞鯨","title":"賞鯨訂位中樞","stage":"proposal","person_days":1,"share":0.3333,"commits":1,"estimated_cost":80000,"by_person":[["永彬",1,1]],"repos":{"infolinktw/ocean-deep":1}}]',
            '"unmapped":[{"full_name":"infolinktw/tools","person_days":1,"share":0.3333,"commits":1,"estimated_cost":80000,"branches":{"main":1},"by_person":[["永彬",1,1]]}]',
        ])
        ->assertDontSee(['status_label', 'stage_label']);
});

test('project_pnl narrows the window to the requested days', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(ProjectPnlSummary::class, ['days' => 7])
        ->assertOk()
        ->assertSee(['"window":{"from":"2026-09-27","to":"2026-10-03","days":7', '"monthly_cost":null,"period_cost":null,"total_person_days":0,"total_commits":0', '"projects":[]']);
});

test('project_pnl rejects a window outside 7–31 days', function (mixed $days) {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(ProjectPnlSummary::class, ['days' => $days])
        ->assertHasErrors();
})->with([
    'shorter than a week' => [6],
    'longer than the GitHub retention' => [32],
    'not a number' => ['month'],
]);

test('project_pnl needs the read ability', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(ProjectPnlSummary::class)
        ->assertHasErrors(['not found']);
});
