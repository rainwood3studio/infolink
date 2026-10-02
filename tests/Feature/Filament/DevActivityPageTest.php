<?php

use App\Domain\Engineering\GithubSync;
use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Filament\Pages\DevActivity;
use App\Filament\Widgets\DataFreshnessWidget;
use App\Models\Developer;
use App\Models\GithubCommit;
use App\Models\GithubIdentity;
use App\Models\GithubRepo;
use App\Models\RedmineIssue;
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
        ->assertSee('這段期間沒有 commit 或 merge 的 PR');

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
