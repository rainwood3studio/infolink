<?php

use App\Enums\Confidence;
use App\Enums\ReceivableStatus;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\ListReceivables;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Receivable;

beforeEach(function () {
    $this->travelTo('2026-09-26 10:00:00');

    $longTermCare = Customer::factory()->create(['name' => '長照協會', 'short_name' => '長照']);
    $bookstore = Customer::factory()->create(['name' => '墊腳石圖書', 'short_name' => '墊腳石']);

    Receivable::factory()->for($longTermCare)->for(Project::factory()->for($longTermCare)->state(['name' => '長照平台']))->create([
        'item' => '期中款',
        'amount_untaxed' => 450_000,
        'expected_on' => '2026-09-20',
        'status' => ReceivableStatus::Invoiced,
        'external_key' => 'ltc-mid',
        'vault_ref' => '財務/應收.md',
        'notes' => '已催款',
    ]);
    Receivable::factory()->for($bookstore)->create(['item' => '尾款', 'amount_untaxed' => 100_000, 'expected_on' => '2026-10-15', 'confidence' => Confidence::Low]);
    Receivable::factory()->for($bookstore)->create(['item' => '維運費', 'amount_untaxed' => 20_000, 'expected_on' => '2026-10-05', 'is_recurring' => true]);
    Receivable::factory()->for($bookstore)->create(['item' => '頭期款', 'amount_untaxed' => 200_000, 'expected_on' => '2026-08-01', 'status' => ReceivableStatus::Received, 'received_on' => '2026-08-03']);
});

test('list_receivables lists outstanding non-recurring receivables with totals by default', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(ListReceivables::class)
        ->assertOk()
        ->assertSee([
            '"customer":"長照","project":"長照平台","item":"期中款","untaxed":450000,"taxed":472500,"tax_rate":0.05,"expected_on":"2026-09-20","days_overdue":6,"confidence":"high","status":"invoiced"',
            '"external_key":"ltc-mid","notes":"已催款","vault_ref":"財務/應收.md"',
            '"item":"尾款"',
            '"totals":{"count":2,"untaxed":550000,"taxed":577500,"high_confidence_taxed":472500,"low_confidence_taxed":105000,"overdue_taxed":472500}',
        ])
        ->assertDontSee(['維運費', '頭期款']);
});

test('list_receivables filters by status, customer, overdue and recurring', function () {
    $user = mcpUser(['read']);

    InfolinkServer::actingAs($user)->tool(ListReceivables::class, ['status' => 'received'])
        ->assertSee(['頭期款', '"days_overdue":0', '"received_on":"2026-08-03"'])
        ->assertDontSee('期中款');

    InfolinkServer::actingAs($user)->tool(ListReceivables::class, ['customer' => '墊腳'])
        ->assertSee('尾款')
        ->assertDontSee('期中款');

    InfolinkServer::actingAs($user)->tool(ListReceivables::class, ['customer' => '協會'])
        ->assertSee('期中款')
        ->assertDontSee('尾款');

    InfolinkServer::actingAs($user)->tool(ListReceivables::class, ['overdue_only' => true])
        ->assertSee(['期中款', '"count":1'])
        ->assertDontSee('尾款');

    InfolinkServer::actingAs($user)->tool(ListReceivables::class, ['include_recurring' => true])
        ->assertSee(['維運費', '"is_recurring":true', '"count":3']);

    InfolinkServer::actingAs($user)->tool(ListReceivables::class, ['status' => 'all', 'include_recurring' => true])
        ->assertSee('"count":4');
});

test('list_receivables rejects an unknown status', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(ListReceivables::class, ['status' => 'paid'])
        ->assertHasErrors();
});

test('list_receivables returns zero totals when nothing matches', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(ListReceivables::class, ['customer' => '不存在'])
        ->assertOk()
        ->assertSee(['"totals":{"count":0,"untaxed":0,"taxed":0', '"receivables":[]']);
});

test('list_receivables needs the read ability', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(ListReceivables::class)
        ->assertHasErrors(['not found']);
});
