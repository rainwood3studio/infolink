<?php

use App\Domain\Delivery\ClosingBoard;
use App\Domain\Finance\CashForecaster;
use App\Domain\Finance\RevenueOutlook;
use App\Enums\Confidence;
use App\Enums\DealStage;
use App\Enums\ProjectStatus;
use App\Enums\ReceivableStatus;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CostBaseline;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\PlannedCashFlow;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusSnapshot;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['services.redmine.acceptor_name' => '文豪']);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00'));
});

/**
 * Bank balance 1,000,000 on 2026-09-22 (150,000 of September's 200,000 cost already paid), so the outlook runs
 * 2026-09 … 2027-08. Untaxed amounts equal taxed ones (tax rate 0).
 *
 * Booked: an overdue 期中款 300,000 (09/15), a 尾款 600,000 (10/31), 維運費 100,000 in October, November and December,
 * a low-confidence 二期 400,000 (12/20) and a planned −80,000 營業稅 (11/15).
 *
 * @return array{interim: Receivable, final: Receivable, low: Receivable}
 */
function revenueOutlookScenario(int $monthlyCost = 200_000): array
{
    CostBaseline::factory()->create(['effective_from' => '2026-01-01', 'monthly_cost' => $monthlyCost]);

    $account = BankAccount::factory()->create(['is_primary' => true]);
    BankTransaction::factory()->for($account)->create(['txn_date' => '2026-09-05', 'withdrawal' => 150_000, 'deposit' => 0, 'balance' => 990_000, 'sequence' => 1]);
    BankTransaction::factory()->for($account)->create(['txn_date' => '2026-09-22', 'withdrawal' => 0, 'deposit' => 10_000, 'balance' => 1_000_000, 'sequence' => 2]);

    $customer = Customer::factory()->create(['short_name' => '墊腳石']);
    $receivable = fn (array $attributes): Receivable => Receivable::factory()->for($customer)->create(['tax_rate' => 0, ...$attributes]);

    $interim = $receivable(['item' => '期中款', 'amount_untaxed' => 300_000, 'expected_on' => '2026-09-15']);
    $final = $receivable(['item' => '尾款', 'amount_untaxed' => 600_000, 'expected_on' => '2026-10-31']);
    $low = $receivable(['item' => '二期', 'amount_untaxed' => 400_000, 'expected_on' => '2026-12-20', 'confidence' => Confidence::Low]);

    foreach (['2026-10-05', '2026-11-05', '2026-12-05'] as $date) {
        $receivable(['item' => '維運費', 'amount_untaxed' => 100_000, 'expected_on' => $date, 'is_recurring' => true]);
    }

    PlannedCashFlow::factory()->create(['flow_on' => '2026-11-15', 'amount' => -80_000, 'description' => '營業稅']);

    return ['interim' => $interim, 'final' => $final, 'low' => $low];
}

/**
 * @param  array<string, mixed>  $outlook
 * @return array<string, int>
 */
function revenueOutlookColumn(array $outlook, string $key): array
{
    return array_column($outlook['months'], $key, 'month');
}

it('reproduces the cash forecast in the confirmed balance when nothing is delayed', function () {
    revenueOutlookScenario();

    $outlook = app(RevenueOutlook::class)->calculate();

    $forecast = app(CashForecaster::class)->calculate(until: '2027-08');

    expect(array_column($outlook['months'], 'balance_confirmed'))->toBe(array_column($forecast->rows, 'balance'))
        ->and(array_slice(revenueOutlookColumn($outlook, 'balance_confirmed'), 0, 5, true))->toBe([
            '2026-09' => 1_250_000,
            '2026-10' => 1_750_000,
            '2026-11' => 1_570_000,
            '2026-12' => 1_470_000,
            '2027-01' => 1_270_000,
        ])
        ->and($outlook['months'][2])->toMatchArray([
            'month' => '2026-11',
            'receivables' => 0,
            'recurring' => 100_000,
            'planned_inflow' => 0,
            'cost' => 200_000,
            'planned_outflow' => 80_000,
            'net_confirmed' => -180_000,
        ])
        ->and($outlook['months'][0])->toMatchArray(['receivables' => 300_000, 'cost' => 50_000])
        ->and($outlook['summary'])->toMatchArray(['as_of' => '2026-09-22', 'opening_balance' => 1_000_000, 'horizon_end' => '2027-08']);
});

it('assumes the recurring fees continue from the month after the last booked one', function () {
    revenueOutlookScenario();

    $outlook = app(RevenueOutlook::class)->calculate();

    expect(array_slice(revenueOutlookColumn($outlook, 'recurring_assumed'), 2, 4, true))->toBe([
        '2026-11' => 0,
        '2026-12' => 0,
        '2027-01' => 100_000,
        '2027-02' => 100_000,
    ])
        ->and($outlook['months'][11]['recurring_assumed'])->toBe(100_000)
        ->and($outlook['months'][11]['balance_base'])->toBe(670_000)
        ->and($outlook['months'][11]['balance_confirmed'])->toBe(-130_000)
        ->and($outlook['assumptions'])->toMatchArray(['recurring_last_month' => '2026-12', 'recurring_assumed_from' => '2027-01'])
        ->and($outlook['summary']['recurring_monthly'])->toBe(100_000);
});

it('takes the run-rate from every fee of the last booked month except cancelled ones', function () {
    revenueOutlookScenario();
    Receivable::factory()->create(['tax_rate' => 0, 'amount_untaxed' => 20_000, 'expected_on' => '2026-12-10', 'is_recurring' => true, 'status' => ReceivableStatus::Received]);
    Receivable::factory()->create(['tax_rate' => 0, 'amount_untaxed' => 7_000, 'expected_on' => '2026-12-10', 'is_recurring' => true, 'status' => ReceivableStatus::Cancelled]);

    $outlook = app(RevenueOutlook::class)->calculate();

    expect($outlook['summary']['recurring_monthly'])->toBe(120_000)
        ->and($outlook['months'][3]['recurring'])->toBe(100_000)
        ->and($outlook['months'][4]['recurring_assumed'])->toBe(120_000);
});

it('moves only the non-recurring receivables when the payments are delayed', function () {
    revenueOutlookScenario();

    $outlook = app(RevenueOutlook::class)->calculate(delayMonths: 2);

    expect(array_slice(revenueOutlookColumn($outlook, 'receivables'), 0, 5, true))->toBe([
        '2026-09' => 0,
        '2026-10' => 0,
        '2026-11' => 300_000,
        '2026-12' => 600_000,
        '2027-01' => 0,
    ])
        ->and(array_slice(revenueOutlookColumn($outlook, 'recurring'), 0, 4, true))->toBe([
            '2026-09' => 0,
            '2026-10' => 100_000,
            '2026-11' => 100_000,
            '2026-12' => 100_000,
        ])
        ->and($outlook['months'][1]['balance_confirmed'])->toBe(850_000)
        ->and($outlook['months'][3]['balance_confirmed'])->toBe(1_470_000)
        ->and($outlook['summary']['peak'])->toBe(['month' => '2026-12', 'balance' => 1_470_000])
        ->and($outlook['assumptions']['delayed_beyond_horizon'])->toBe(['count' => 0, 'amount_taxed' => 0]);
});

it('drops a receivable that the delay pushes past the horizon and says how much', function () {
    revenueOutlookScenario();

    $outlook = app(RevenueOutlook::class)->calculate(months: 3, delayMonths: 2);

    expect(revenueOutlookColumn($outlook, 'receivables'))->toBe(['2026-09' => 0, '2026-10' => 0, '2026-11' => 300_000])
        ->and($outlook['assumptions']['delayed_beyond_horizon'])->toBe(['count' => 1, 'amount_taxed' => 600_000]);
});

it('lists low-confidence receivables but counts them in the base balance only when asked', function () {
    revenueOutlookScenario();

    $without = app(RevenueOutlook::class)->calculate();
    $with = app(RevenueOutlook::class)->calculate(includeLowConfidence: true);

    expect($without['months'][3])->toMatchArray(['low_confidence' => 400_000, 'balance_base' => 1_470_000, 'balance_confirmed' => 1_470_000])
        ->and($with['months'][3])->toMatchArray(['low_confidence' => 400_000, 'balance_base' => 1_870_000, 'balance_confirmed' => 1_470_000])
        ->and($with['months'][3]['items']['low_confidence'])->toBe([['label' => '墊腳石 二期', 'amount' => 400_000]])
        ->and($with['summary']['end_balance_base'])->toBe(1_070_000)
        ->and($with['assumptions']['low_confidence_taxed'])->toBe(400_000);
});

it('adds the weighted pipeline of deals with an amount and a close date, with their monthly fee after closing', function () {
    revenueOutlookScenario();
    $counted = Deal::factory()->create([
        'prospect_name' => '長照', 'title' => 'HR 二期', 'amount_untaxed' => 1_000_000, 'probability' => 50, 'recurring_monthly' => 20_000,
        'expected_close_on' => '2026-11-20', 'next_action_on' => '2026-10-10',
    ]);
    Deal::factory()->create(['stage' => DealStage::Won, 'amount_untaxed' => 900_000, 'probability' => 100, 'expected_close_on' => '2026-11-01']);

    $outlook = app(RevenueOutlook::class)->calculate();

    expect(array_slice(revenueOutlookColumn($outlook, 'pipeline_weighted'), 1, 3, true))->toBe([
        '2026-10' => 0,
        '2026-11' => 500_000,
        '2026-12' => 10_000,
    ])
        ->and($outlook['months'][11]['pipeline_weighted'])->toBe(10_000)
        ->and($outlook['months'][2]['balance_with_pipeline'])->toBe(2_070_000)
        ->and($outlook['months'][11]['balance_with_pipeline'])->toBe(670_000 + 590_000)
        ->and($outlook['months'][11]['balance_base'])->toBe(670_000)
        ->and($outlook['summary'])->toMatchArray(['pipeline_weighted_total' => 590_000, 'open_deals' => 1, 'counted_deals' => 1])
        ->and($outlook['deals'])->toHaveCount(1)
        ->and($outlook['deals'][0])->toMatchArray([
            'id' => $counted->id, 'party' => '長照', 'title' => 'HR 二期', 'stage_label' => '提案', 'weighted' => 500_000,
            'missing' => [], 'counted' => true, 'not_counted_reason' => null,
        ]);
});

it('names what each open deal is missing and leaves those it cannot place out of the pipeline', function () {
    revenueOutlookScenario();
    $blank = ['amount_untaxed' => null, 'expected_close_on' => null, 'next_action' => null, 'next_action_on' => null];
    Deal::factory()->create(['title' => '空白', ...$blank]);
    Deal::factory()->create(['title' => '只有月費', ...$blank, 'recurring_monthly' => 30_000, 'next_action_on' => '2026-10-10']);
    Deal::factory()->create(['title' => '日期已過', 'amount_untaxed' => 300_000, 'expected_close_on' => '2026-08-15']);
    Deal::factory()->create(['title' => '太遠', 'amount_untaxed' => 300_000, 'expected_close_on' => '2028-01-15']);

    $outlook = app(RevenueOutlook::class)->calculate();

    $deals = collect($outlook['deals'])->keyBy('title');

    expect($deals['空白'])->toMatchArray(['missing' => ['金額', '預計成交日', '下一步日期'], 'counted' => false, 'not_counted_reason' => 'missing_fields'])
        ->and($deals['只有月費'])->toMatchArray(['missing' => ['預計成交日'], 'counted' => false, 'not_counted_reason' => 'missing_fields'])
        ->and($deals['日期已過'])->toMatchArray(['missing' => [], 'counted' => false, 'not_counted_reason' => 'close_date_passed'])
        ->and($deals['太遠'])->toMatchArray(['missing' => [], 'counted' => false, 'not_counted_reason' => 'beyond_horizon'])
        ->and($outlook['summary'])->toMatchArray(['pipeline_weighted_total' => 0, 'open_deals' => 4, 'counted_deals' => 0])
        ->and($outlook['summary']['end_balance_with_pipeline'])->toBe($outlook['summary']['end_balance_base']);
});

it('reports the monthly gap, the peak, when cash starts falling and when it reaches zero', function () {
    revenueOutlookScenario();

    $summary = app(RevenueOutlook::class)->calculate()['summary'];

    expect($summary)->toMatchArray([
        'monthly_cost' => 200_000,
        'recurring_monthly' => 100_000,
        'monthly_gap' => 100_000,
        'annual_gap' => 1_200_000,
        'peak' => ['month' => '2026-10', 'balance' => 1_750_000],
        'first_declining_month' => '2026-11',
        'zero_month' => ['month' => '2028-03', 'extrapolated' => true, 'months_of_runway_after_horizon' => 7],
        'zero_month_confirmed' => ['month' => '2027-08', 'extrapolated' => false, 'months_of_runway_after_horizon' => null],
    ]);
});

it('finds the zero month inside the horizon when the base balance goes negative there', function () {
    revenueOutlookScenario(monthlyCost: 400_000);

    $summary = app(RevenueOutlook::class)->calculate()['summary'];

    expect($summary['zero_month'])->toBe(['month' => '2027-03', 'extrapolated' => false, 'months_of_runway_after_horizon' => null])
        ->and($summary['monthly_gap'])->toBe(300_000);
});

it('has no zero month and no declining month when recurring income covers the monthly cost', function () {
    revenueOutlookScenario(monthlyCost: 80_000);

    $summary = app(RevenueOutlook::class)->calculate()['summary'];

    expect($summary)->toMatchArray([
        'monthly_gap' => -20_000,
        'annual_gap' => -240_000,
        'first_declining_month' => null,
        'zero_month' => null,
        'peak' => ['month' => '2027-08', 'balance' => 2_040_000],
    ])
        ->and($summary['zero_month_confirmed'])->toBe(['month' => '2028-12', 'extrapolated' => true, 'months_of_runway_after_horizon' => 16]);
});

it('lists the non-recurring receivables that hang on a closing project with its projection', function () {
    ['final' => $final] = revenueOutlookScenario();
    $project = Project::factory()->create(['name' => '商城 APP', 'status' => ProjectStatus::Closing, 'redmine_project_id' => 21, 'target_close_date' => '2026-10-31']);
    $final->update(['project_id' => $project->id]);
    Receivable::factory()->for($project)->create(['item' => '維運費', 'expected_on' => '2026-10-05', 'is_recurring' => true]);
    Receivable::factory()->for(Project::factory())->create(['item' => '進行中專案的款項', 'expected_on' => '2026-10-20']);
    RedmineIssue::factory()->count(3)->create(['project_id' => 21, 'project_identifier' => 'mall-app']);
    RedmineStatusSnapshot::factory()->create(['snapshot_date' => '2026-09-26', 'project_identifier' => 'mall-app', 'count' => 3]);

    $rows = app(RevenueOutlook::class)->calculate(delayMonths: 1)['closing_receivables'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray([
            'id' => $final->id,
            'label' => '墊腳石 尾款',
            'amount_taxed' => 600_000,
            'expected_on' => '2026-10-31',
            'month' => '2026-11',
            'at_risk' => true,
        ])
        ->and($rows[0]['project'])->toMatchArray([
            'id' => $project->id,
            'name' => '商城 APP',
            'open' => 3,
            'projection' => ClosingBoard::PROJECTION_NOT_CONVERGING,
            'projected_close_date' => null,
        ]);
});

it('rejects a horizon or delay outside the supported range', function (int $months, int $delayMonths) {
    app(RevenueOutlook::class)->calculate($months, $delayMonths);
})->with([
    'no months' => [0, 0],
    'too many months' => [37, 0],
    'negative delay' => [12, -1],
    'delay over a year' => [12, 13],
])->throws(InvalidArgumentException::class);
