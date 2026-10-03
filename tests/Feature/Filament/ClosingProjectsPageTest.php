<?php

use App\Enums\ProjectStatus;
use App\Filament\Pages\ClosingProjects;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    config(['services.redmine.url' => 'http://redmine.test', 'services.redmine.acceptor_name' => '文豪']);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00'));
    $this->actingAs(User::factory()->create());
});

/**
 * 商城 APP (past target, 3 open, burning 1 a day) and HR 系統 (28 days left, 1 open, same as a week ago).
 *
 * @return array{mall: Project, hr: Project}
 */
function seedClosingPage(): array
{
    $mall = Project::factory()->for(Customer::factory()->create(['short_name' => '墊腳石']))->create([
        'name' => '商城 APP', 'status' => ProjectStatus::Closing, 'redmine_project_id' => 21, 'target_close_date' => '2026-10-01',
    ]);
    $hr = Project::factory()->create([
        'name' => 'HR 系統', 'status' => ProjectStatus::Closing, 'redmine_project_id' => 22, 'target_close_date' => '2026-10-31',
    ]);
    Receivable::factory()->for($mall)->create(['item' => '尾款', 'amount_untaxed' => 800_000, 'expected_on' => '2026-10-31']);

    $mallIssue = ['project_id' => 21, 'project_identifier' => 'mall-app'];
    RedmineIssue::factory()->create([...$mallIssue, 'id' => 3001, 'subject' => '結帳金額錯誤', 'status' => '實作中', 'assignee_name' => '裕樺', 'updated_on' => '2026-08-24 09:00']);
    RedmineIssue::factory()->create([...$mallIssue, 'id' => 3002, 'subject' => '推播沒收到', 'status' => '新建立', 'assignee_name' => null]);
    RedmineIssue::factory()->create([...$mallIssue, 'id' => 3003, 'subject' => '首頁輪播', 'status' => '驗證中', 'assignee_name' => '文豪']);
    RedmineIssue::factory()->create(['project_id' => 22, 'project_identifier' => 'hr', 'id' => 4001, 'subject' => '請假單簽核', 'status' => '新建立', 'assignee_name' => null]);
    RedmineStatusSnapshot::factory()->create(['snapshot_date' => '2026-09-26', 'project_identifier' => 'mall-app', 'count' => 10]);
    RedmineStatusSnapshot::factory()->create(['snapshot_date' => '2026-09-26', 'project_identifier' => 'hr', 'count' => 1]);

    return ['mall' => $mall, 'hr' => $hr];
}

it('shows an empty state when no project is closing', function () {
    Project::factory()->create(['name' => '進行中的專案']);

    $this->get(ClosingProjects::getUrl())
        ->assertOk()
        ->assertSee('目前沒有結案中的專案')
        ->assertDontSee('進行中的專案');
});

it('shows the totals, a card per closing project and the most urgent project\'s issues', function () {
    seedClosingPage();

    $this->get(ClosingProjects::getUrl())->assertOk();

    Livewire::test(ClosingProjects::class)
        ->assertSet('project', null)
        ->assertSeeInOrder(['掛著的未收應收（含稅）', 'NT$840,000', '未結議題', '4 張', '已過目標日', '1 個專案'])
        ->assertSeeInOrder(['商城 APP', '墊腳石', '已過 2 天', 'NT$840,000', '尾款', '預計 10/31', 'HR 系統', '剩 28 天'])
        ->assertSee('照近 7 天速度（每天淨消化 1 張）預計 10/06 結完，比目標晚 5 天')
        ->assertSee('近 7 天未結議題沒有減少，照這樣結不完')
        ->assertSeeInOrder(['卡在誰', '裕樺', '文豪', '驗收'])
        ->assertSeeInOrder(['未指派', '1 張', '#3002', '推播沒收到', '開發中', '1 張', '#3001', '結帳金額錯誤', '40 天', '等文豪驗收', '1 張', '#3003', '首頁輪播'])
        ->assertSeeHtml('href="http://redmine.test/issues/3001"')
        ->assertDontSee('請假單簽核');
});

it('switches the issue list to the clicked project and reads the project from the query string', function () {
    ['mall' => $mall, 'hr' => $hr] = seedClosingPage();

    Livewire::test(ClosingProjects::class)
        ->call('selectProject', $hr->id)
        ->assertSet('project', (string) $hr->id)
        ->assertSee(['#4001', '請假單簽核'])
        ->assertDontSee('結帳金額錯誤');

    Livewire::withQueryParams(['project' => (string) $hr->id])
        ->test(ClosingProjects::class)
        ->assertSee('請假單簽核')
        ->assertDontSee('結帳金額錯誤');

    Livewire::withQueryParams(['project' => '999999'])
        ->test(ClosingProjects::class)
        ->assertSet('project', null)
        ->assertSee('結帳金額錯誤');
});

it('says so when there is no history to project from and hints at a missing Redmine link or target date', function () {
    Project::factory()->create(['name' => '商城 APP', 'status' => ProjectStatus::Closing, 'redmine_project_id' => 21, 'target_close_date' => '2026-10-31']);
    RedmineIssue::factory()->count(2)->create(['project_id' => 21, 'project_identifier' => 'mall-app']);
    Project::factory()->create(['name' => '舊官網', 'status' => ProjectStatus::Closing, 'redmine_project_id' => null, 'target_close_date' => null]);

    Livewire::test(ClosingProjects::class)
        ->assertSee('歷史資料不足，還無法推估')
        ->assertSeeInOrder(['舊官網', '未設定目標日', '未連結 Redmine 專案', '沒有目標結案日']);
});

it('caps a long stage and links to the issue mirror filtered to the project', function () {
    Project::factory()->create(['name' => '商城 APP', 'status' => ProjectStatus::Closing, 'redmine_project_id' => 21]);
    RedmineIssue::factory()->count(ClosingProjects::ISSUES_PER_STAGE + 2)->create(['project_id' => 21, 'project_identifier' => 'mall-app', 'assignee_name' => '裕樺']);

    Livewire::test(ClosingProjects::class)
        ->assertSee('還有 2 張')
        ->assertSeeHtml('filters%5Bproject_identifier%5D%5Bvalues%5D%5B0%5D=mall-app');
});
