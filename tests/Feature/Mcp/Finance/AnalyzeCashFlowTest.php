<?php

use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\AnalyzeCashFlow;
use App\Models\BankAccount;
use App\Models\BankTransaction;

beforeEach(function () {
    $account = BankAccount::factory()->create();

    BankTransaction::factory()->create(['bank_account_id' => $account->id, 'txn_date' => '2026-07-06', 'category' => 'revenue', 'counterparty' => '墊腳石國際', 'deposit' => 319_217, 'withdrawal' => 0, 'balance' => 348_673]);
    BankTransaction::factory()->create(['bank_account_id' => $account->id, 'txn_date' => '2026-07-07', 'category' => 'salary', 'deposit' => 0, 'withdrawal' => 168_096, 'balance' => 180_577]);
    BankTransaction::factory()->create(['bank_account_id' => $account->id, 'txn_date' => '2026-09-15', 'category' => 'tax', 'deposit' => 0, 'withdrawal' => 20_577, 'balance' => 160_000]);
});

test('analyze_cash_flow is hidden and refused without the read ability', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(AnalyzeCashFlow::class)
        ->assertHasErrors(['not found']);
});

test('analyze_cash_flow returns the default sections and omits months without lines', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(AnalyzeCashFlow::class)
        ->assertOk()
        ->assertSee([
            '"has_data":true',
            '"months":["2026-07","2026-09"]',
            '"total_inflow":319217',
            '"summary":', '"monthly":', '"outflow_by_category":', '"revenue_by_customer":',
            '"customer":"墊腳石國際"',
            '"hhi":10000',
        ])
        ->assertDontSee(['"month":"2026-08"', '"daily_balance":', '"collection":']);
});

test('analyze_cash_flow returns only the requested sections for the period', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(AnalyzeCashFlow::class, ['from' => '2026-09', 'to' => '2026-09-30', 'sections' => ['vat', 'runway', 'daily_balance']])
        ->assertOk()
        ->assertSee(['"from":"2026-09-01"', '"vat":{"total":20577', '"runway":', '"daily_balance":[{"date":"2026-09-01","balance":180577', '{"date":"2026-09-15","balance":160000'])
        ->assertDontSee(['"summary":{', '"monthly":', '2026-07']);
});

test('analyze_cash_flow rejects unknown sections and malformed dates', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(AnalyzeCashFlow::class, ['sections' => ['everything']])
        ->assertHasErrors();

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(AnalyzeCashFlow::class, ['from' => '2026/07'])
        ->assertHasErrors();

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(AnalyzeCashFlow::class, ['from' => '2026-09', 'to' => '2026-07'])
        ->assertHasErrors(['must not be after']);
});
