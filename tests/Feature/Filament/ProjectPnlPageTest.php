<?php

use App\Enums\ReceivableStatus;
use App\Filament\Pages\ProjectPnl;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\GithubRepos\GithubRepoResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\CostBaseline;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Developer;
use App\Models\GithubCommit;
use App\Models\GithubIdentity;
use App\Models\GithubRepo;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00'));
    $this->actingAs(User::factory()->create());
});

/**
 * Monthly cost 240,000. 永彬 commits on four days: 10/01 to APOS 2.0 (墊腳石, maintenance 100,000 a month), 10/02 to
 * HR 系統 (長照, on the `dycare-dev` branch of the same repo, no contract amount), 09/10 to the 賞鯨訂位中樞 deal and
 * 09/11 to an unmapped repo. So every target gets one person-day and 60,000 of estimated cost over 30 days.
 *
 * @return array{apos: Project, hr: Project, deal: Deal}
 */
function seedProjectPnlPage(): array
{
    CostBaseline::factory()->create(['effective_from' => '2026-01-01', 'monthly_cost' => 240_000]);

    $stone = Customer::factory()->create(['short_name' => '墊腳石']);
    $apos = Project::factory()->for($stone)->create(['name' => 'APOS 2.0', 'contract_amount_untaxed' => 1_200_000]);
    $hr = Project::factory()->for(Customer::factory()->create(['short_name' => '長照']))->create(['name' => 'HR 系統', 'contract_amount_untaxed' => null]);
    $deal = Deal::factory()->create(['prospect_name' => '多羅滿賞鯨', 'title' => '賞鯨訂位中樞']);

    Receivable::factory()->for($stone)->for($apos)->create(['item' => '簽約款', 'amount_untaxed' => 400_000, 'tax_rate' => 0.05, 'expected_on' => '2026-03-01', 'status' => ReceivableStatus::Received, 'received_on' => '2026-03-10']);
    Receivable::factory()->for($stone)->for($apos)->create(['item' => '維運費', 'amount_untaxed' => 100_000, 'tax_rate' => 0.05, 'expected_on' => '2026-10-01', 'is_recurring' => true]);

    $yubin = GithubIdentity::factory()->for(Developer::factory()->create(['name' => '永彬']))->create();
    $api = GithubRepo::factory()->branchProject('dycare-dev', $hr)->create(['full_name' => 'infolinktw/excalibur-zero-api', 'project_id' => $apos->id]);
    $ocean = GithubRepo::factory()->create(['full_name' => 'infolinktw/ocean-deep', 'deal_id' => $deal->id]);
    $tools = GithubRepo::factory()->create(['full_name' => 'infolinktw/tools']);

    $commit = fn (GithubRepo $repo, string $authoredAt, string $branch = 'main'): GithubCommit => GithubCommit::factory()
        ->for($repo, 'repo')->for($yubin, 'identity')->create(['authored_at' => $authoredAt, 'branch' => $branch]);

    $commit($api, '2026-10-01 09:00');
    $commit($api, '2026-10-02 09:00', 'dycare-dev');
    $commit($ocean, '2026-09-10 09:00');
    $commit($tools, '2026-09-11 09:00', 'next');

    return ['apos' => $apos, 'hr' => $hr, 'deal' => $deal];
}

it('shows where the person-days went per customer and project next to the money', function () {
    ['apos' => $apos, 'hr' => $hr] = seedProjectPnlPage();

    $this->get(ProjectPnl::getUrl())->assertOk();

    Livewire::test(ProjectPnl::class)
        ->assertSee('09/04 – 10/03（30 天）')
        ->assertSeeInOrder(['總投入人天', '4.0 人天', '4 筆 commit', 'NT$240,000'])
        ->assertSeeInOrder(['最大客戶佔投入', '墊腳石 25%', '同一客戶佔歷來已收款 100%'])
        ->assertSeeInOrder(['尚未簽約的投入', '1.0 人天', '佔投入 25%，估算成本 NT$60,000'])
        ->assertSeeInOrder(['沒對應到專案的 commit', '25%', '1 個 repo、1.0 人天'])
        ->assertSeeInOrder(['依客戶', '墊腳石', '未收 NT$105,000（含稅）', '投入 1.0 人天', '100%', '已收 NT$400,000', '長照', '投入 1.0 人天', '沒對應的 repo', '沒有收入'])
        ->assertSeeInOrder(['依專案', 'APOS 2.0', '進行中', '墊腳石', '永彬', 'NT$60,000', 'NT$100,000', '維運費 NT$100,000／月', '+NT$40,000', 'NT$1,200,000', '已收 NT$400,000', '未收 NT$100,000'])
        ->assertSeeInOrder(['HR 系統', '長照', 'NT$60,000', '未填', '沒有應收紀錄'])
        ->assertSeeHtml('href="'.ProjectResource::getUrl('edit', ['record' => $hr->id]).'" class="pp-badge"')
        ->assertSeeHtml('href="'.ProjectResource::getUrl('edit', ['record' => $apos->id]).'"')
        ->assertSee('1 個有投入的專案沒填合約金額：HR 系統')
        ->assertSee('2 個有投入的專案沒連結 Redmine 專案');
});

it('lists the effort without a contract with a link to the deal, and the repos tied to nothing', function () {
    ['deal' => $deal] = seedProjectPnlPage();

    Livewire::test(ProjectPnl::class)
        ->assertSeeInOrder(['尚未簽約的投入', '賞鯨訂位中樞', '多羅滿賞鯨', '提案', '永彬 1.0', 'NT$60,000', 'infolinktw/ocean-deep'])
        ->assertSeeHtml('href="'.DealResource::getUrl('edit', ['record' => $deal->id]).'"')
        ->assertSeeInOrder(['沒對應的 repo', 'infolinktw/tools', '永彬 1.0', 'next'])
        ->assertSeeHtml('href="'.GithubRepoResource::getUrl('index').'"');
});

it('flags a project whose revenue in the period does not cover the estimated cost', function () {
    CostBaseline::factory()->create(['effective_from' => '2026-01-01', 'monthly_cost' => 240_000]);
    $project = Project::factory()->create(['name' => 'APOS 2.0']);
    Receivable::factory()->for($project->customer)->for($project)->create(['amount_untaxed' => 100_000, 'expected_on' => '2026-10-01', 'is_recurring' => true]);
    GithubCommit::factory()->for(GithubRepo::factory()->create(['project_id' => $project->id]), 'repo')->create(['authored_at' => '2026-10-01 09:00']);

    Livewire::test(ProjectPnl::class)
        ->assertSeeInOrder(['APOS 2.0', 'NT$240,000', 'NT$100,000', '−NT$140,000', '收入蓋不過投入'])
        ->assertDontSee('沒對應的 repo');
});

it('recalculates when the period changes and reads it from the query string', function () {
    seedProjectPnlPage();

    Livewire::test(ProjectPnl::class)
        ->assertSet('period', 'last_30_days')
        ->call('setPeriod', 'last_7_days')
        ->assertSee(['09/27 – 10/03（7 天）', '2.0 人天', 'NT$56,000', '這段期間沒有投入在還沒簽約的機會上'])
        ->assertDontSee(['infolinktw/tools', '賞鯨訂位中樞'])
        ->call('setPeriod', 'forever')
        ->assertSet('period', 'last_30_days');

    Livewire::withQueryParams(['period' => 'this_month'])
        ->test(ProjectPnl::class)
        ->assertSet('period', 'this_month')
        ->assertSee('10/01 – 10/03（3 天）');
});

it('shows an empty state when nothing was committed in the period', function () {
    Project::factory()->create(['name' => 'APOS 2.0']);

    Livewire::test(ProjectPnl::class)
        ->assertSee('這段期間沒有 commit')
        ->assertDontSee('依專案');
});

it('says the cost cannot be estimated when there is no cost baseline', function () {
    $project = Project::factory()->create();
    GithubCommit::factory()->for(GithubRepo::factory()->create(['project_id' => $project->id]), 'repo')->create(['authored_at' => '2026-10-01 09:00']);

    Livewire::test(ProjectPnl::class)
        ->assertSee('還沒設定月成本，無法估算成本')
        ->assertSee('還沒有月成本基準，所以估算成本和估算差額都是空的。');
});
