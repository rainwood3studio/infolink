<?php

use App\Domain\Work\ActionItemService;
use App\Domain\Work\AttentionItem;
use App\Enums\ActionItemPriority;
use App\Enums\ActionItemStatus;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Enums\Source;
use App\Models\ActionItem;
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
