<?php

use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Filament\Pages\DeliveryOverview;
use App\Filament\Resources\RedmineIssues\Pages\ListRedmineIssues;
use App\Filament\Resources\RedmineIssues\RedmineIssueResource;
use App\Filament\Resources\RedmineTimeEntries\Pages\ListRedmineTimeEntries;
use App\Filament\Resources\RedmineTimeEntries\RedmineTimeEntryResource;
use App\Filament\Resources\SyncRuns\Pages\ListSyncRuns;
use App\Filament\Resources\SyncRuns\SyncRunResource;
use App\Filament\Widgets\DataFreshnessWidget;
use App\Filament\Widgets\DeliveryStatsWidget;
use App\Filament\Widgets\DeliveryTrendChart;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusSnapshot;
use App\Models\RedmineTimeEntry;
use App\Models\SyncRun;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    config(['services.redmine.url' => 'http://redmine.test', 'services.redmine.acceptor_name' => '文豪']);
    $this->actingAs(User::factory()->create());
});

/**
 * Open issues: 3 in 驗證中 with 文豪, 2 in 驗證中 with others, 1 unassigned 驗證中, 1 stalled + overdue in another project.
 */
function seedDeliveryIssues(): void
{
    RedmineIssue::factory()->count(3)->create(['status' => RedmineIssue::STATUS_VERIFYING, 'assignee_name' => '文豪']);
    RedmineIssue::factory()->create(['status' => RedmineIssue::STATUS_VERIFYING, 'assignee_name' => '永彬']);
    RedmineIssue::factory()->create(['status' => RedmineIssue::STATUS_VERIFYING, 'assignee_name' => '鈺文']);
    RedmineIssue::factory()->create(['status' => RedmineIssue::STATUS_VERIFYING, 'assignee_name' => null]);
    RedmineIssue::factory()->create([
        'project_identifier' => 'longcare',
        'project_name' => '長照平台',
        'assignee_name' => '裕樺',
        'updated_on' => now()->subDays(120),
        'due_date' => today()->subDays(3),
    ]);
    RedmineIssue::factory()->create(['is_closed' => true, 'status' => '已結案', 'assignee_name' => '妤欣']);
}

it('shows the verifying split on the overview cards', function () {
    seedDeliveryIssues();

    Livewire::test(DeliveryStatsWidget::class)
        ->assertSeeInOrder(['未結案', '7'])
        ->assertSeeInOrder(['驗證中－文豪隊列', '3'])
        ->assertSeeInOrder(['驗證中－非文豪', '2', '另有 1 筆未指派'])
        ->assertSeeInOrder(['停滯 > 90 天', '1'])
        ->assertSeeInOrder(['逾期', '1'])
        ->assertSeeInOrder(['未指派', '1']);
});

it('renders the overview page with the per-project table sorted by open issues', function () {
    seedDeliveryIssues();

    $this->get(DeliveryOverview::getUrl())
        ->assertOk()
        ->assertSee('結案數不是產能指標')
        ->assertSee('交付總覽');

    Livewire::test(DeliveryOverview::class)
        ->assertOk()
        ->assertSeeInOrder(['墊腳石 | 5F | B2C', '長照平台']);
});

it('renders the trend chart with reconstructed days showing only the open line', function () {
    RedmineStatusSnapshot::factory()->create([
        'snapshot_date' => today()->subDay(),
        'status' => '(重建)',
        'assignee_name' => '',
        'count' => 40,
        'is_reconstructed' => true,
    ]);
    RedmineStatusSnapshot::factory()->create([
        'snapshot_date' => today(),
        'status' => RedmineIssue::STATUS_VERIFYING,
        'assignee_name' => '永彬',
        'count' => 25,
        'stalled_90d' => 4,
        'is_reconstructed' => false,
    ]);

    $chart = Livewire::test(DeliveryTrendChart::class)->assertOk()->instance();
    $data = (fn (): array => $this->getData())->call($chart);

    expect($data['datasets'][0]['data'])->toBe([40, 25])
        ->and($data['datasets'][1]['data'])->toBe([null, 0])
        ->and($data['datasets'][2]['data'])->toBe([null, 25])
        ->and($data['datasets'][3]['data'])->toBe([null, 4]);
});

it('lists mirrored issues with open-only on by default', function () {
    $open = RedmineIssue::factory()->create(['assignee_name' => '文豪']);
    $closed = RedmineIssue::factory()->create(['is_closed' => true, 'status' => '已結案']);

    Livewire::test(ListRedmineIssues::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$closed])
        ->assertSee('http://redmine.test/issues/'.$open->id)
        ->removeTableFilter('open')
        ->assertCanSeeTableRecords([$open, $closed]);
});

it('filters issues by stalled age and off-flow verifying', function () {
    $fresh = RedmineIssue::factory()->create(['updated_on' => now()->subDays(5)]);
    $stalled = RedmineIssue::factory()->create(['updated_on' => now()->subDays(100)]);
    $withAcceptor = RedmineIssue::factory()->create(['status' => RedmineIssue::STATUS_VERIFYING, 'assignee_name' => '文豪']);
    $withOther = RedmineIssue::factory()->create(['status' => RedmineIssue::STATUS_VERIFYING, 'assignee_name' => '永彬']);

    Livewire::test(ListRedmineIssues::class)
        ->filterTable('stalled', '90')
        ->assertCanSeeTableRecords([$stalled])
        ->assertCanNotSeeTableRecords([$fresh, $withAcceptor, $withOther])
        ->resetTableFilters()
        ->filterTable('verifying_others')
        ->assertCanSeeTableRecords([$withOther])
        ->assertCanNotSeeTableRecords([$fresh, $stalled, $withAcceptor]);
});

it('searches issues by subject and id', function () {
    $login = RedmineIssue::factory()->create(['subject' => '登入頁面錯誤']);
    $other = RedmineIssue::factory()->create(['subject' => '報表匯出']);

    Livewire::test(ListRedmineIssues::class)
        ->searchTable('登入')
        ->assertCanSeeTableRecords([$login])
        ->assertCanNotSeeTableRecords([$other])
        ->searchTable((string) $other->id)
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$login]);
});

it('lists time entries with filters and an hours total', function () {
    $mine = RedmineTimeEntry::factory()->create(['user_name' => '永彬', 'hours' => 2.5, 'spent_on' => '2026-09-01']);
    $theirs = RedmineTimeEntry::factory()->create(['user_name' => '鈺文', 'hours' => 1.25, 'spent_on' => '2026-09-20']);

    Livewire::test(ListRedmineTimeEntries::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$mine, $theirs])
        ->assertSee('3.75')
        ->filterTable('user_name', ['永彬'])
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs])
        ->resetTableFilters()
        ->filterTable('spent_on', ['from' => '2026-09-10', 'until' => '2026-09-30'])
        ->assertCanSeeTableRecords([$theirs])
        ->assertCanNotSeeTableRecords([$mine]);
});

it('lists sync runs with status and error', function () {
    $ok = SyncRun::factory()->create(['stats' => ['fetched' => 12]]);
    $failed = SyncRun::factory()->create(['job' => SyncJob::RedmineTime, 'status' => SyncStatus::Failed, 'error' => 'Connection refused']);

    Livewire::test(ListSyncRuns::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$ok, $failed])
        ->assertSee('fetched: 12')
        ->assertSee('Connection refused');
});

it('exposes the read-only resources without create or edit pages', function (string $resource) {
    expect($resource::getPages())->toHaveKeys(['index'])
        ->not->toHaveKey('create')
        ->not->toHaveKey('edit')
        ->and($resource::canCreate())->toBeFalse()
        ->and($resource::hasPage('create'))->toBeFalse();
})->with([
    '議題' => RedmineIssueResource::class,
    '工時' => RedmineTimeEntryResource::class,
    '同步紀錄' => SyncRunResource::class,
]);

it('serves the delivery pages over http', function (string $url) {
    RedmineIssue::factory()->create(['assignee_name' => '文豪']);
    RedmineTimeEntry::factory()->create();
    SyncRun::factory()->create();

    $this->get($url)->assertOk();
})->with([
    '交付總覽' => fn (): string => DeliveryOverview::getUrl(),
    '議題' => fn (): string => RedmineIssueResource::getUrl(),
    '工時' => fn (): string => RedmineTimeEntryResource::getUrl(),
    '同步紀錄' => fn (): string => SyncRunResource::getUrl(),
]);

it('adds the freshness bar and delivery trend to the dashboard but keeps the delivery cards on their own page', function () {
    $this->get('/admin')
        ->assertOk()
        ->assertSeeLivewire(DataFreshnessWidget::class)
        ->assertSeeLivewire(DeliveryTrendChart::class)
        ->assertDontSeeLivewire(DeliveryStatsWidget::class);
});
