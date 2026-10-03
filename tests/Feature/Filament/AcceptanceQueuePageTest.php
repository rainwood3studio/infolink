<?php

use App\Enums\ProjectStatus;
use App\Enums\SyncJob;
use App\Filament\Pages\AcceptanceQueue;
use App\Models\Project;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusChange;
use App\Models\SyncRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    config(['services.redmine.url' => 'http://redmine.test', 'services.redmine.acceptor_name' => '文豪']);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00'));
    $this->actingAs(User::factory()->create());
});

/**
 * Queue: #11 (商城 APP, closing, handed over by 裕樺 on 09/29), #12 and #13 (墊腳石, waiting 32 and 3 days).
 * Off-flow: #21 with 妤欣. Accepted: 2 in the week of 09/21, so 0.5 per week on average.
 */
function seedAcceptanceQueue(): void
{
    SyncRun::factory()->create(['job' => SyncJob::RedmineIssues, 'started_at' => '2026-09-26 09:00', 'finished_at' => '2026-09-26 09:01']);
    Project::factory()->create(['name' => '商城 APP', 'status' => ProjectStatus::Closing, 'redmine_project_id' => 21, 'target_close_date' => '2026-10-01']);

    $verifying = ['status' => RedmineIssue::STATUS_VERIFYING, 'assignee_name' => '文豪'];
    RedmineIssue::factory()->create([...$verifying, 'id' => 11, 'subject' => '購物車金額錯誤', 'project_id' => 21, 'project_identifier' => 'mall-app', 'project_name' => '商城 APP', 'priority' => '高']);
    RedmineIssue::factory()->create([...$verifying, 'id' => 12, 'subject' => '報表匯出', 'updated_on' => '2026-09-01 09:00']);
    RedmineIssue::factory()->create([...$verifying, 'id' => 13, 'subject' => '會員條碼', 'updated_on' => '2026-09-30 09:00']);
    RedmineIssue::factory()->create([...$verifying, 'id' => 21, 'subject' => '掛在別人名下', 'assignee_name' => '妤欣', 'updated_on' => '2026-08-04 09:00']);
    RedmineIssue::factory()->count(2)->create(['status' => '完成', 'is_closed' => true, 'assignee_name' => '文豪', 'closed_on' => '2026-09-23 10:00']);

    RedmineStatusChange::factory()->create(['issue_id' => 11, 'previous_assignee_name' => '裕樺', 'changed_at' => '2026-09-29 15:00']);
}

it('shows an empty state before any Redmine issue is mirrored', function () {
    $this->get(AcceptanceQueue::getUrl())
        ->assertOk()
        ->assertSee('還沒有 Redmine 議題');
});

it('renders the tiles, weekly flow, suggested order and off-flow issues', function () {
    seedAcceptanceQueue();

    $this->get(AcceptanceQueue::getUrl())->assertOk();

    Livewire::test(AcceptanceQueue::class)
        ->assertSeeInOrder(['待驗收', '3', '其中擋著結案專案', '1', '平均每週驗收', '0.5', '照這速度清空需要', '6', '預計 2026-11-14 清空', '流程外', '1'])
        ->assertSee('隊列還在變長：09/26 以來送驗 1 張、驗收 0 張')
        ->assertSeeInOrder(['每週流量', '08/24–08/30', '09/21–09/27', '09/28–10/04', '本週至今', '送驗從 09/26 開始記錄'])
        ->assertSeeInOrder(['依專案', '商城 APP', '結案中 · 已過 2 天', '墊腳石 | 5F | B2C'])
        ->assertSeeInOrder(['建議驗收順序', '#11', '購物車金額錯誤', '擋尾款', '4 天', '裕樺', '高', '#12', '報表匯出', '32 天', '#13', '會員條碼'])
        ->assertSeeInOrder(['流程外：驗證中但不在文豪名下', '妤欣', '1 張', '#21', '掛在別人名下', '60 天沒更新'])
        ->assertSeeHtml('http://redmine.test/issues/11');
});

it('filters the suggested order to one project', function () {
    seedAcceptanceQueue();

    Livewire::test(AcceptanceQueue::class)
        ->set('project', 'mall-app')
        ->assertSee('購物車金額錯誤')
        ->assertDontSee('報表匯出')
        ->assertSee('掛在別人名下')
        ->set('project', '')
        ->assertSet('project', null)
        ->assertSee('報表匯出');

    Livewire::withQueryParams(['project' => 'tcsb-5f-b2c'])
        ->test(AcceptanceQueue::class)
        ->assertSee('報表匯出')
        ->assertDontSee('購物車金額錯誤');

    Livewire::withQueryParams(['project' => 'bogus'])
        ->test(AcceptanceQueue::class)
        ->assertSet('project', null)
        ->assertSee('購物車金額錯誤');
});

it('says so when the pace cannot be estimated and nothing is off-flow', function () {
    RedmineIssue::factory()->create(['status' => RedmineIssue::STATUS_VERIFYING, 'assignee_name' => '文豪']);

    Livewire::test(AcceptanceQueue::class)
        ->assertSee(['近四週沒有驗收，無法推估', '沒有流程外的議題', '驗證中的議題都在文豪名下'])
        ->assertDontSee('隊列還在變長');
});

it('caps the suggested order and links to the issue mirror for the rest', function () {
    RedmineIssue::factory()->count(AcceptanceQueue::MAX_QUEUE_ROWS + 2)->create(['status' => RedmineIssue::STATUS_VERIFYING, 'assignee_name' => '文豪']);

    Livewire::test(AcceptanceQueue::class)
        ->assertSee('只列前 100 張，共 102 張')
        ->assertSee('在議題（鏡像）看全部驗證中');
});
