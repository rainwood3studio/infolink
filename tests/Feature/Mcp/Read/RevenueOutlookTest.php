<?php

use App\Enums\Confidence;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\RevenueOutlookSummary;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CostBaseline;
use App\Models\Deal;
use App\Models\PlannedCashFlow;
use App\Models\Receivable;

beforeEach(function () {
    config(['services.redmine.acceptor_name' => '文豪']);
    $this->travelTo('2026-10-03 10:00:00');
});

/**
 * Bank balance 1,000,000 on 2026-09-22, monthly cost 200,000 (150,000 of September's already paid), tax rate 0.
 * Booked: overdue 期中款 300,000, 尾款 600,000 (10/31), 維運費 100,000 for October–December, a low-confidence 二期
 * 400,000 (12/20) and a planned −80,000 營業稅 (11/15).
 */
function seedRevenueOutlookTool(): void
{
    CostBaseline::factory()->create(['effective_from' => '2026-01-01', 'monthly_cost' => 200_000]);

    $account = BankAccount::factory()->create(['is_primary' => true]);
    BankTransaction::factory()->for($account)->create(['txn_date' => '2026-09-05', 'withdrawal' => 150_000, 'deposit' => 0, 'balance' => 990_000, 'sequence' => 1]);
    BankTransaction::factory()->for($account)->create(['txn_date' => '2026-09-22', 'withdrawal' => 0, 'deposit' => 10_000, 'balance' => 1_000_000, 'sequence' => 2]);

    $receivable = fn (array $attributes): Receivable => Receivable::factory()->create(['tax_rate' => 0, ...$attributes]);

    $receivable(['item' => '期中款', 'amount_untaxed' => 300_000, 'expected_on' => '2026-09-15']);
    $receivable(['item' => '尾款', 'amount_untaxed' => 600_000, 'expected_on' => '2026-10-31']);
    $receivable(['item' => '二期', 'amount_untaxed' => 400_000, 'expected_on' => '2026-12-20', 'confidence' => Confidence::Low]);

    foreach (['2026-10-05', '2026-11-05', '2026-12-05'] as $date) {
        $receivable(['item' => '維運費', 'amount_untaxed' => 100_000, 'expected_on' => $date, 'is_recurring' => true]);
    }

    PlannedCashFlow::factory()->create(['flow_on' => '2026-11-15', 'amount' => -80_000, 'description' => '營業稅']);
}

test('revenue_outlook returns the summary, the month layers as columns and rows, and what each deal is missing', function () {
    seedRevenueOutlookTool();
    Deal::factory()->create(['title' => '官網改版', 'amount_untaxed' => null, 'expected_close_on' => null, 'next_action_on' => null]);

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(RevenueOutlookSummary::class)
        ->assertOk()
        ->assertSee([
            '"generated_at":"2026-10-03T10:00:00+08:00"',
            '"options":{"months":12,"delay_months":0,"include_low_confidence":false}',
            '"as_of":"2026-09-22","has_bank_data":true,"opening_balance":1000000,"months":12,"horizon_end":"2027-08"',
            '"monthly_cost":200000,"recurring_monthly":100000,"monthly_gap":100000,"annual_gap":1200000,"peak":{"month":"2026-10","balance":1750000},"first_declining_month":"2026-11"',
            '"zero_month":{"month":"2028-03","extrapolated":true,"months_of_runway_after_horizon":7}',
            '"zero_month_confirmed":{"month":"2027-08","extrapolated":false,"months_of_runway_after_horizon":null}',
            '"pipeline_weighted_total":0,"open_deals":1,"counted_deals":0',
            '"recurring_last_month":"2026-12","recurring_assumed_from":"2027-01","low_confidence_taxed":400000',
            '"columns":["month","receivables","recurring","recurring_assumed","low_confidence","planned_inflow","cost","planned_outflow","pipeline_weighted","net_confirmed","net_base","balance_confirmed","balance_base","balance_with_pipeline"]',
            '["2026-11",0,100000,0,0,0,200000,80000,0,-180000,-180000,1570000,1570000,1570000]',
            '["2027-01",0,0,100000,0,0,200000,0,0,-200000,-100000,1270000,1370000,1370000]',
            '"title":"官網改版"',
            '"missing":["金額","預計成交日","下一步日期"],"counted":false,"not_counted_reason":"missing_fields"',
            '"closing_receivables":[]',
        ])
        ->assertDontSee('"items"');
});

test('revenue_outlook delays the project receivables and counts low-confidence ones when asked', function () {
    seedRevenueOutlookTool();

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(RevenueOutlookSummary::class, ['delay_months' => 2, 'include_low_confidence' => true])
        ->assertOk()
        ->assertSee([
            '"options":{"months":12,"delay_months":2,"include_low_confidence":true}',
            '["2026-10",0,100000,0,0,0,200000,0,0,-100000,-100000,850000,850000,850000]',
            '["2026-12",600000,100000,0,400000,0,200000,0,0,500000,900000,1470000,1870000,1870000]',
            '"peak":{"month":"2026-12","balance":1870000}',
        ]);
});

test('revenue_outlook rejects a delay outside 0–12 months and a non-boolean flag', function (array $arguments) {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(RevenueOutlookSummary::class, $arguments)
        ->assertHasErrors();
})->with([
    'delay over a year' => [['delay_months' => 13]],
    'negative delay' => [['delay_months' => -1]],
    'non-boolean flag' => [['include_low_confidence' => 'maybe']],
]);

test('revenue_outlook needs the read ability', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(RevenueOutlookSummary::class)
        ->assertHasErrors(['not found']);
});
