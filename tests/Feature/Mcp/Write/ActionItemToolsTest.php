<?php

use App\Enums\ActionItemPriority;
use App\Enums\ActionItemStatus;
use App\Enums\InsightSeverity;
use App\Enums\Source;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\CreateActionItem;
use App\Mcp\Tools\UpdateActionItem;
use App\Models\ActionItem;
use App\Models\Insight;

test('create_action_item creates an item and is idempotent without an explicit key', function () {
    $arguments = ['title' => '催收長照期中款', 'priority' => 'p1', 'due_on' => '2026-10-01', 'owner' => 'Kenneth'];

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(CreateActionItem::class, $arguments)
        ->assertOk()
        ->assertSee(['"result":"created"', '"status":"todo"', '"priority":"p1"']);

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(CreateActionItem::class, [...$arguments, 'due_on' => '2026-10-02'])
        ->assertOk()
        ->assertSee(['"result":"updated"', '"due_on":"2026-10-02"']);

    $item = ActionItem::query()->sole();

    expect($item->source)->toBe(Source::Claude)
        ->and($item->external_key)->toStartWith('auto:');
});

test('create_action_item honours an explicit external key', function () {
    foreach (['催收', '催收長照期中款'] as $title) {
        InfolinkServer::actingAs(mcpUser(['write']))
            ->tool(CreateActionItem::class, ['title' => $title, 'external_key' => 'collect:長照-期中款'])
            ->assertOk();
    }

    expect(ActionItem::query()->sole()->title)->toBe('催收長照期中款');
});

test('create_action_item links an insight and inherits its title and priority', function () {
    $insight = Insight::factory()->create(['severity' => InsightSeverity::Critical, 'title' => '長照期中款逾期', 'fingerprint' => 'receivable-overdue:長照-期中款']);

    foreach ([['insight_id' => $insight->id], ['insight_fingerprint' => 'receivable-overdue:長照-期中款']] as $link) {
        InfolinkServer::actingAs(mcpUser(['write']))
            ->tool(CreateActionItem::class, $link)
            ->assertOk();
    }

    $item = ActionItem::query()->sole();

    expect($item->title)->toBe('長照期中款逾期')
        ->and($item->priority)->toBe(ActionItemPriority::P1)
        ->and($item->related_id)->toBe($insight->id)
        ->and($item->related_type)->toBe($insight->getMorphClass());
});

test('create_action_item does not reopen an item that was completed', function () {
    InfolinkServer::actingAs(mcpUser(['write']))->tool(CreateActionItem::class, ['title' => '寄發票'])->assertOk();
    ActionItem::query()->sole()->update(['status' => ActionItemStatus::Done]);

    InfolinkServer::actingAs(mcpUser(['write']))->tool(CreateActionItem::class, ['title' => '寄發票'])->assertOk();

    expect(ActionItem::query()->sole()->status)->toBe(ActionItemStatus::Done);
});

test('create_action_item validates input', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(CreateActionItem::class, ['priority' => 'urgent'])
        ->assertHasErrors(['Pass a `title`', 'priority must be p1, p2 or p3.']);

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(CreateActionItem::class, ['insight_id' => 999])
        ->assertHasErrors(['No insight with id 999.']);
});

test('update_action_item marks an item done and is idempotent', function () {
    $item = ActionItem::factory()->create(['due_on' => '2026-10-01']);

    foreach (range(1, 2) as $attempt) {
        InfolinkServer::actingAs(mcpUser(['write']))
            ->tool(UpdateActionItem::class, ['id' => $item->id, 'status' => 'done', 'due_on' => null])
            ->assertOk()
            ->assertSee(['"status":"done"', '"due_on":null']);
    }

    $item->refresh();

    expect($item->status)->toBe(ActionItemStatus::Done)
        ->and($item->completed_at)->not->toBeNull()
        ->and($item->due_on)->toBeNull()
        ->and(ActionItem::query()->count())->toBe(1);
});

test('update_action_item finds items by external key and validates', function () {
    ActionItem::factory()->create(['external_key' => 'collect:長照', 'source' => Source::Claude]);

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(UpdateActionItem::class, ['external_key' => 'collect:長照', 'status' => 'waiting'])
        ->assertOk()
        ->assertSee('"status":"waiting"');

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(UpdateActionItem::class, ['external_key' => 'collect:長照', 'status' => 'closed'])
        ->assertHasErrors(['status must be one of: todo, doing, waiting, done, dropped.']);

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(UpdateActionItem::class, ['external_key' => 'collect:長照'])
        ->assertHasErrors(['Nothing to update']);
});
