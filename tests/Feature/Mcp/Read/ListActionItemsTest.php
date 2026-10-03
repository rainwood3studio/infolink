<?php

use App\Enums\ActionItemPriority;
use App\Enums\ActionItemStatus;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\ListActionItems;
use App\Models\ActionItem;
use App\Models\Insight;

beforeEach(function () {
    $this->travelTo('2026-09-26 10:00:00');

    $this->insight = Insight::factory()->create();

    ActionItem::factory()->create([
        'title' => '寄出我識尾款發票',
        'priority' => ActionItemPriority::P1,
        'due_on' => '2026-09-24',
        'owner' => 'Kenneth',
        'related_type' => $this->insight->getMorphClass(),
        'related_id' => $this->insight->id,
        'external_key' => 'invoice-wushi',
    ]);
    ActionItem::factory()->create(['title' => '整理驗證中議題', 'status' => ActionItemStatus::Doing, 'due_on' => '2026-10-10', 'owner' => '裕樺']);
    ActionItem::factory()->create(['title' => '沒有期限的事', 'due_on' => null]);
    ActionItem::factory()->create(['title' => '已完成的事', 'status' => ActionItemStatus::Done, 'completed_at' => now()]);
});

test('list_action_items lists pending items by due date with related records', function () {
    $response = InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(ListActionItems::class)
        ->assertOk()
        ->assertSee([
            '"total":3',
            '"title":"寄出我識尾款發票"',
            '"priority":"p1","status":"todo","due_on":"2026-09-24","days_overdue":2,"owner":"Kenneth","is_mine":true,"related_type":"insight","related_id":'.$this->insight->id,
            '"owner":"裕樺","is_mine":false',
            '"owner":null,"is_mine":true',
            '"external_key":"invoice-wushi"',
        ])
        ->assertDontSee('已完成的事');

    expect(implode('', invade($response)->content()))->toMatch('/寄出我識尾款發票.*整理驗證中議題.*沒有期限的事/s');
});

test('list_action_items filters by status, due date, owner and insight', function () {
    $user = mcpUser(['read']);

    InfolinkServer::actingAs($user)->tool(ListActionItems::class, ['status' => 'done'])
        ->assertSee(['已完成的事', '"total":1']);

    InfolinkServer::actingAs($user)->tool(ListActionItems::class, ['status' => 'all'])
        ->assertSee('"total":4');

    InfolinkServer::actingAs($user)->tool(ListActionItems::class, ['due_before' => '2026-09-30'])
        ->assertSee(['寄出我識尾款發票', '"total":1'])
        ->assertDontSee('沒有期限的事');

    InfolinkServer::actingAs($user)->tool(ListActionItems::class, ['owner' => '裕'])
        ->assertSee(['整理驗證中議題', '"total":1']);

    InfolinkServer::actingAs($user)->tool(ListActionItems::class, ['insight_id' => $this->insight->id])
        ->assertSee(['寄出我識尾款發票', '"total":1']);
});

test('list_action_items separates mine from delegated', function () {
    $user = mcpUser(['read']);

    InfolinkServer::actingAs($user)->tool(ListActionItems::class, ['ownership' => 'mine'])
        ->assertSee(['"ownership":"mine"', '"total":2', '寄出我識尾款發票', '沒有期限的事'])
        ->assertDontSee('整理驗證中議題');

    InfolinkServer::actingAs($user)->tool(ListActionItems::class, ['ownership' => 'delegated'])
        ->assertSee(['"total":1', '整理驗證中議題'])
        ->assertDontSee('寄出我識尾款發票');

    InfolinkServer::actingAs($user)->tool(ListActionItems::class, ['ownership' => 'everyone'])
        ->assertHasErrors();
});

test('list_action_items is empty without items', function () {
    ActionItem::query()->delete();

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(ListActionItems::class)
        ->assertOk()
        ->assertSee('"total":0,"action_items":[]');
});

test('list_action_items needs the read ability', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(ListActionItems::class)
        ->assertHasErrors(['not found']);
});
