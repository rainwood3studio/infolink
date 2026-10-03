<?php

use App\Domain\Engineering\GithubSync;
use App\Enums\ReportType;
use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Filament\Pages\DevActivity;
use App\Filament\Widgets\DataFreshnessWidget;
use App\Models\Developer;
use App\Models\GithubCommit;
use App\Models\GithubIdentity;
use App\Models\GithubRepo;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusChange;
use App\Models\RedmineTimeEntry;
use App\Models\Report;
use App\Models\SyncRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    config(['services.redmine.url' => 'http://redmine.test']);
    $this->travelTo(CarbonImmutable::parse('2026-10-02 15:00'));
    $this->actingAs(User::factory()->create());
});

function seedDevActivity(): array
{
    $repo = GithubRepo::factory()->create(['name' => 'pos', 'full_name' => 'infolinktw/pos']);
    $yubin = Developer::factory()->create(['name' => '永彬']);
    $yuwen = Developer::factory()->create(['name' => '鈺文']);
    $yubinIdentity = GithubIdentity::factory()->for($yubin)->create();
    $yuwenIdentity = GithubIdentity::factory()->for($yuwen)->create();
    RedmineIssue::factory()->create(['id' => 2881, 'subject' => '作廢要選原因', 'status' => '處理中']);

    GithubCommit::factory()->for($repo, 'repo')->for($yubinIdentity, 'identity')->create([
        'authored_at' => '2026-10-02 10:00',
        'subject' => 'feat(pos): 交易作廢選原因',
        'redmine_issue_ids' => [2881],
        'is_ai_assisted' => true,
    ]);
    GithubCommit::factory()->for($repo, 'repo')->for($yuwenIdentity, 'identity')->create([
        'authored_at' => '2026-09-29 09:00',
        'subject' => 'chore: 升級套件',
    ]);
    GithubCommit::factory()->for($repo, 'repo')->for($yuwenIdentity, 'identity')->create([
        'authored_at' => '2026-09-10 09:00',
        'subject' => 'fix: 上個月的修正',
    ]);

    return ['yubin' => $yubin, 'yuwen' => $yuwen];
}

it('shows an empty state before anything has been synced', function () {
    $this->get(DevActivity::getUrl())
        ->assertOk()
        ->assertSee('尚未同步 GitHub');
});

it('shows cards, heatmap, daily log and untracked commits for this week by default', function () {
    seedDevActivity();

    $this->get(DevActivity::getUrl())->assertOk();

    Livewire::test(DevActivity::class)
        ->assertSet('period', 'this_week')
        ->assertSee(['永彬', '鈺文', '碰過的議題', '每日 commit 熱度', '每日工作日誌'])
        ->assertSeeInOrder(['10/02（五）', '永彬', '#2881', '作廢要選原因', '處理中', '交易作廢選原因', '09/29（二）', '鈺文', '升級套件'])
        ->assertSeeInOrder(['沒掛 Redmine 議題的 commit', '1 / 2 筆'])
        ->assertDontSee('上個月的修正');
});

it('switches period and filters to one person', function () {
    ['yuwen' => $yuwen] = seedDevActivity();

    Livewire::test(DevActivity::class)
        ->call('setPeriod', 'this_month')
        ->assertDontSee('升級套件')
        ->call('setPeriod', 'last_30_days')
        ->assertSee('上個月的修正')
        ->call('togglePerson', "dev:{$yuwen->id}")
        ->assertSet('person', "dev:{$yuwen->id}")
        ->assertDontSee('交易作廢選原因')
        ->assertSee('升級套件')
        ->call('togglePerson', "dev:{$yuwen->id}")
        ->assertSet('person', null)
        ->assertSee('交易作廢選原因');
});

it('reads the period and person from the query string', function () {
    ['yuwen' => $yuwen] = seedDevActivity();

    Livewire::withQueryParams(['period' => 'last_week', 'person' => "dev:{$yuwen->id}"])
        ->test(DevActivity::class)
        ->assertSet('period', 'last_week')
        ->assertSee('這段期間沒有 commit、merge 的 PR 或 Redmine 活動');

    Livewire::withQueryParams(['period' => 'bogus'])
        ->test(DevActivity::class)
        ->assertSet('period', 'this_week');
});

it('runs the GitHub sync from the header action', function () {
    seedDevActivity();

    $this->mock(GithubSync::class)
        ->shouldReceive('sync')
        ->once()
        ->andReturn(SyncRun::factory()->create([
            'job' => SyncJob::GithubActivity,
            'stats' => ['repos' => 12, 'commits_created' => 30, 'commits_updated' => 2, 'prs' => 4, 'reviews' => 5],
        ]));

    Livewire::test(DevActivity::class)
        ->callAction('sync')
        ->assertNotified('已同步');
});

it('reports a failed GitHub sync', function () {
    $this->mock(GithubSync::class)
        ->shouldReceive('sync')
        ->once()
        ->andReturn(SyncRun::factory()->create(['job' => SyncJob::GithubActivity, 'status' => SyncStatus::Failed, 'error' => 'Bad credentials']));

    Livewire::test(DevActivity::class)
        ->callAction('sync')
        ->assertNotified('同步失敗');
});

it('shows GitHub freshness on the dashboard bar', function () {
    SyncRun::factory()->create(['job' => SyncJob::GithubActivity, 'started_at' => now()->subMinutes(6), 'finished_at' => now()->subMinutes(5)]);

    expect(collect(Livewire::test(DataFreshnessWidget::class)->instance()->getSources())->firstWhere('label', 'GitHub'))
        ->toMatchArray(['value' => '5 分鐘前', 'color' => 'success']);
});

it('shows the latest AI analysis above the cards, escaping raw HTML', function () {
    seedDevActivity();
    Report::factory()->create(['type' => ReportType::DevReview, 'period_start' => '2026-10-01', 'body' => '舊的分析']);
    Report::factory()->create([
        'type' => ReportType::DevReview,
        'period_start' => '2026-10-02',
        'body' => "昨天兩人都有進度。\n\n## 建議\n\n- 請在 commit 加上議題編號 <script>alert(1)</script>",
    ]);

    Livewire::test(DevActivity::class)
        ->assertSeeInOrder(['AI 分析建議', '10/02 產生', '昨天兩人都有進度', '建議', '請在 commit 加上議題編號', '碰過的議題'])
        ->assertDontSee('舊的分析')
        ->assertDontSeeHtml('<script>alert(1)</script>');
});

it('explains when no AI analysis exists yet', function () {
    seedDevActivity();

    Livewire::test(DevActivity::class)->assertSee(['AI 分析建議', '還沒有分析']);
});

it('shows each developer\'s Redmine work on the card and in the daily log', function () {
    ['yuwen' => $yuwen] = seedDevActivity();
    $yuwen->update(['redmine_name' => '鈺文']);
    RedmineIssue::factory()->create(['id' => 2810, 'subject' => '進站彈窗公告', 'status' => '驗證中']);
    RedmineTimeEntry::factory()->create(['user_name' => '鈺文', 'issue_id' => 2810, 'hours' => 2.5, 'spent_on' => '2026-10-01']);
    RedmineStatusChange::factory()->create(['issue_id' => 2810, 'to_status' => '驗證中', 'previous_assignee_name' => '鈺文', 'changed_at' => '2026-10-01 17:00']);
    SyncRun::factory()->create(['job' => SyncJob::RedmineIssues, 'status' => SyncStatus::Ok, 'started_at' => '2026-09-30 09:00']);

    Livewire::test(DevActivity::class)
        ->assertSeeInOrder(['鈺文', 'Redmine', '工時', '2.5h', '送驗', '1', '名下未結'])
        ->assertSee('Redmine 的送驗／結案／驗收從 09/30 開始記錄')
        ->assertSeeInOrder(['10/01（四）', '鈺文', '無 commit', 'Redmine 工時 2.5h', '#2810', '進站彈窗公告']);
});
