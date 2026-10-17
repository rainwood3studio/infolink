<?php

use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\ExpenseCalendarSummary;
use App\Models\CostBaseline;
use App\Models\PlannedCashFlow;
use App\Models\RecurringExpense;

beforeEach(function () {
    $this->travelTo('2026-10-17 10:00:00');
});

test('expense_calendar returns the months with their items and the headline figures', function () {
    CostBaseline::factory()->create(['effective_from' => '2026-01-01', 'monthly_cost' => 229_666]);
    $vat = RecurringExpense::factory()->vat(60_000)->create(['starts_on' => '2026-01-01']);
    RecurringExpense::factory()->inBaseline()->create(['name' => '勞健保', 'amount' => 30_000, 'due_day' => 31, 'category' => 'insurance', 'starts_on' => '2026-01-01']);
    PlannedCashFlow::factory()->create(['flow_on' => '2027-01-20', 'amount' => -200_000, 'description' => '年終獎金']);

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(ExpenseCalendarSummary::class, ['months' => 4])
        ->assertOk()
        ->assertSee([
            '"generated_at":"2026-10-17T10:00:00+08:00"',
            '"summary":{"today":"2026-10-17","months":4,"horizon_end":"2027-01","monthly_cost":229666,"has_baseline":true,"extra_90d":120000,"extra_total":320000,"in_baseline_total":120000,"recurring_expenses":2,"items":7',
            '"next_due":{"date":"2026-10-31","name":"勞健保","amount":30000,"in_baseline":true}',
            '{"month":"2026-11","extra_outflow":60000,"in_baseline_outflow":30000,"items":[{"kind":"recurring","id":'.$vat->id.',"date":"2026-11-15","name":"營業稅","amount":60000,"category":"tax","category_label":"稅務","in_baseline":false}',
            '"kind":"planned"',
            '"name":"年終獎金","amount":200000,"category":null,"category_label":"已排定","in_baseline":false',
        ])
        ->assertDontSee('"month":"2027-02"');
});

test('expense_calendar defaults to six months and says when nothing is set up', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(ExpenseCalendarSummary::class)
        ->assertOk()
        ->assertSee(['"months":6,"horizon_end":"2027-03","monthly_cost":0,"has_baseline":false,"extra_90d":0', '"recurring_expenses":0,"items":0,"next_due":null']);
});

test('expense_calendar needs the read ability', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(ExpenseCalendarSummary::class)
        ->assertHasErrors(['not found']);
});
