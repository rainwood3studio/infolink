<?php

use App\Domain\Work\ActionItemService;
use App\Domain\Work\AttentionItem;
use App\Enums\ActionItemPostpone;
use App\Enums\ActionItemPriority;
use App\Enums\ActionItemStatus;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Enums\Source;
use App\Models\ActionItem;
use App\Models\Developer;
use App\Models\Insight;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00'));
    $this->service = app(ActionItemService::class);
});

test('create is idempotent on source and external key', function () {
    $attributes = ['title' => '寄出我識尾款發票', 'due_on' => '2026-10-15', 'external_key' => 'vault:abc'];

    $this->service->create($attributes, Source::Vault);
    $item = $this->service->create([...$attributes, 'title' => '寄出我識尾款發票（含稅）'], Source::Vault);

    expect(ActionItem::count())->toBe(1)
        ->and($item->title)->toBe('寄出我識尾款發票（含稅）')
        ->and($item->source)->toBe(Source::Vault);
});

test('complete and reopen manage completed_at', function () {
    $item = $this->service->create(['title' => '打電話給長照']);

    $this->service->complete($item);
    expect($item->fresh())->status->toBe(ActionItemStatus::Done)->completed_at->not->toBeNull();

    $this->service->reopen($item);
    expect($item->fresh())->status->toBe(ActionItemStatus::Todo)->completed_at->toBeNull();
});

test('updating the status to done via update sets completed_at', function () {
    $item = $this->service->create(['title' => '對帳']);

    $this->service->update($item, ['status' => 'done']);

    expect($item->fresh()->completed_at)->not->toBeNull();
});

test('the attention list orders insights by severity then recency, then due action items', function () {
    $olderCritical = Insight::factory()->create(['severity' => InsightSeverity::Critical, 'last_seen_at' => now()->subDays(2)]);
    $warning = Insight::factory()->create(['severity' => InsightSeverity::Warning, 'last_seen_at' => now()]);
    $newerCritical = Insight::factory()->create(['severity' => InsightSeverity::Critical, 'last_seen_at' => now()->subHour(), 'status' => InsightStatus::Acknowledged]);

    $dueTodayP1 = ActionItem::factory()->create(['due_on' => '2026-09-24', 'priority' => ActionItemPriority::P1]);
    $overdue = ActionItem::factory()->create(['due_on' => '2026-09-20', 'priority' => ActionItemPriority::P3, 'status' => ActionItemStatus::Waiting]);
    $dueTodayP2 = ActionItem::factory()->create(['due_on' => '2026-09-24', 'priority' => ActionItemPriority::P2]);

    $list = $this->service->attentionList();

    expect($list->map(fn (AttentionItem $item) => [$item->type, $item->model->id])->all())->toBe([
        ['insight', $newerCritical->id],
        ['insight', $olderCritical->id],
        ['insight', $warning->id],
        ['action_item', $overdue->id],
        ['action_item', $dueTodayP1->id],
        ['action_item', $dueTodayP2->id],
    ]);

    expect($list[0])->color->toBe('danger')->severity->toBe(InsightSeverity::Critical)->priority->toBeNull()
        ->and($list[3])->color->toBe('danger')->badge->toBe('逾期 4 天')->priority->toBe(ActionItemPriority::P3)
        ->and($list[4])->color->toBe('warning')->badge->toBe('今天到期');
});

test('the attention list excludes info, resolved, future, undated and finished items', function () {
    Insight::factory()->create(['severity' => InsightSeverity::Info]);
    Insight::factory()->create(['severity' => InsightSeverity::Critical, 'status' => InsightStatus::Resolved]);
    Insight::factory()->create(['severity' => InsightSeverity::Warning, 'status' => InsightStatus::Dismissed]);

    ActionItem::factory()->create(['due_on' => '2026-09-25']);
    ActionItem::factory()->create(['due_on' => null]);
    ActionItem::factory()->create(['due_on' => '2026-09-20', 'status' => ActionItemStatus::Done]);
    ActionItem::factory()->create(['due_on' => '2026-09-20', 'status' => ActionItemStatus::Dropped]);

    expect($this->service->attentionList())->toBeEmpty();
});

test('the attention list can be evaluated for another day', function () {
    ActionItem::factory()->create(['due_on' => '2026-09-25']);

    expect($this->service->attentionList(CarbonImmutable::parse('2026-09-25')))->toHaveCount(1);
});

test('postpone choices resolve to the next weekday, next Monday and a week later', function (string $today, string $tomorrow, string $nextMonday, string $inAWeek) {
    $today = CarbonImmutable::parse($today);

    expect(ActionItemPostpone::Tomorrow->dueOn($today)->toDateString())->toBe($tomorrow)
        ->and(ActionItemPostpone::NextMonday->dueOn($today)->toDateString())->toBe($nextMonday)
        ->and(ActionItemPostpone::InAWeek->dueOn($today)->toDateString())->toBe($inAWeek);
})->with([
    'thursday' => ['2026-09-24 15:30', '2026-09-25', '2026-09-28', '2026-10-01'],
    'friday' => ['2026-09-25', '2026-09-28', '2026-09-28', '2026-10-02'],
    'saturday' => ['2026-09-26', '2026-09-28', '2026-09-28', '2026-10-03'],
    'monday' => ['2026-09-28', '2026-09-29', '2026-10-05', '2026-10-05'],
]);

test('postpone moves the due date and leaves the rest alone', function () {
    $item = ActionItem::factory()->create(['due_on' => '2026-09-20', 'owner' => '裕樺', 'status' => ActionItemStatus::Doing]);

    $this->service->postpone($item, ActionItemPostpone::NextMonday->dueOn());

    expect($item->fresh())
        ->due_on->toDateString()->toBe('2026-09-28')
        ->owner->toBe('裕樺')
        ->status->toBe(ActionItemStatus::Doing);
});

test('delegate hands the item to a colleague and take back returns it to the owner', function () {
    $item = ActionItem::factory()->create(['owner' => 'Kenneth']);

    $this->service->delegate($item, ' 裕樺 ');
    expect($item->fresh())->owner->toBe('裕樺')->isMine()->toBeFalse();

    $this->service->takeBack($item);
    expect($item->fresh())->owner->toBe('Kenneth')->isMine()->toBeTrue();
});

test('delegate refuses a blank owner', function () {
    $this->service->delegate(ActionItem::factory()->create(), '  ');
})->throws(InvalidArgumentException::class);

test('drop marks the item dropped without a completion time', function () {
    $item = ActionItem::factory()->create(['status' => ActionItemStatus::Doing]);

    $this->service->drop($item);

    expect($item->fresh())->status->toBe(ActionItemStatus::Dropped)->completed_at->toBeNull();
});

test('an item is mine when the owner is blank or the configured owner name', function (?string $owner, bool $mine) {
    $item = ActionItem::factory()->create(['owner' => $owner]);

    expect($item->isMine())->toBe($mine)
        ->and(ActionItem::query()->mine()->exists())->toBe($mine)
        ->and(ActionItem::query()->delegated()->exists())->toBe(! $mine);
})->with([
    'no owner' => [null, true],
    'empty' => ['', true],
    'owner name' => ['Kenneth', true],
    'other case and spaces' => [' kenneth ', true],
    'colleague' => ['裕樺', false],
]);

test('the owner name comes from config', function () {
    config(['infolink.owner_name' => '文豪']);

    expect(ActionItem::factory()->create(['owner' => '文豪'])->isMine())->toBeTrue()
        ->and(ActionItem::factory()->create(['owner' => 'Kenneth'])->isMine())->toBeFalse();
});

test('my attention list leaves out delegated items while the full list keeps them', function () {
    $insight = Insight::factory()->create(['severity' => InsightSeverity::Critical]);
    $mine = ActionItem::factory()->create(['due_on' => '2026-09-22', 'owner' => 'Kenneth']);
    $unowned = ActionItem::factory()->create(['due_on' => '2026-09-24', 'owner' => null]);
    $delegated = ActionItem::factory()->create(['due_on' => '2026-09-20', 'owner' => '裕樺']);

    $ids = fn ($list) => $list->map(fn (AttentionItem $item) => [$item->type, $item->model->id])->all();

    expect($ids($this->service->myAttentionList()))->toBe([['insight', $insight->id], ['action_item', $mine->id], ['action_item', $unowned->id]])
        ->and($ids($this->service->myDueItems()))->toBe([['action_item', $mine->id], ['action_item', $unowned->id]])
        ->and($ids($this->service->attentionList()))->toBe([['insight', $insight->id], ['action_item', $delegated->id], ['action_item', $mine->id], ['action_item', $unowned->id]]);
});

test('the delegated list holds every pending item of a colleague, overdue first and undated last', function () {
    $undated = ActionItem::factory()->create(['due_on' => null, 'owner' => '文豪']);
    $future = ActionItem::factory()->create(['due_on' => '2026-10-10', 'owner' => '裕樺']);
    $overdue = ActionItem::factory()->create(['due_on' => '2026-09-21', 'owner' => '裕樺', 'status' => ActionItemStatus::Waiting]);
    $dueToday = ActionItem::factory()->create(['due_on' => '2026-09-24', 'owner' => '文豪']);
    ActionItem::factory()->create(['due_on' => '2026-09-21', 'owner' => 'Kenneth']);
    ActionItem::factory()->create(['due_on' => '2026-09-21', 'owner' => '裕樺', 'status' => ActionItemStatus::Done]);

    $list = $this->service->delegatedList();

    expect($list->map(fn (AttentionItem $item) => [$item->model->id, $item->badge, $item->isOverdue()])->all())->toBe([
        [$overdue->id, '逾期 3 天', true],
        [$dueToday->id, '今天到期', false],
        [$future->id, '未到期', false],
        [$undated->id, '未排期', false],
    ]);
});

test('owners are the owner name followed by the active developers, without duplicates', function () {
    Developer::factory()->create(['name' => '曾裕樺']);
    Developer::factory()->create(['name' => 'Kenneth']);
    Developer::factory()->create(['name' => 'Howl']);
    Developer::factory()->create(['name' => '離職的人', 'is_active' => false]);

    expect($this->service->owners())->toBe(['Kenneth', 'Howl', '曾裕樺'])
        ->and($this->service->delegates())->toBe(['Howl', '曾裕樺']);
});
