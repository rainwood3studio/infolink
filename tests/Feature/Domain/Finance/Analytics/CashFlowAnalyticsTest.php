<?php

use App\Domain\Finance\CashFlowAnalytics;
use App\Enums\ReceivableStatus;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Receivable;

/**
 * One account, opening balance 1,000,000; June and August have lines, July has none.
 */
function seedCashFlowLedger(): void
{
    $account = BankAccount::factory()->create();
    $balance = 1_000_000;
    $sequence = 0;

    $lines = [
        ['2026-06-05', 'revenue', 100_000, 0, 'A客戶', false],
        ['2026-06-10', 'salary', 0, 200_000, null, false],
        ['2026-06-10', 'revenue', 50_000, 0, 'B客戶', false],
        ['2026-06-20', 'other', 0, 30_000, null, true],
        ['2026-06-25', 'reimbursement', 0, 10_000, null, false],
        ['2026-08-05', 'revenue', 300_000, 0, 'A客戶', false],
        ['2026-08-10', 'insurance', 0, 40_000, null, false],
        ['2026-08-12', 'reimbursement', 4_000, 0, null, false],
        ['2026-08-15', 'tax', 0, 60_000, null, false],
    ];

    foreach ($lines as [$date, $category, $deposit, $withdrawal, $counterparty, $oneOff]) {
        $balance += $deposit - $withdrawal;

        BankTransaction::factory()->create([
            'bank_account_id' => $account->id,
            'txn_date' => $date,
            'sequence' => ++$sequence,
            'category' => $category,
            'deposit' => $deposit,
            'withdrawal' => $withdrawal,
            'balance' => $balance,
            'counterparty' => $counterparty,
            'is_one_off' => $oneOff,
        ]);
    }
}

beforeEach(function () {
    $this->travelTo('2026-09-26 10:00:00');
});

test('monthly flows split outflow into regular, one-off and reimbursement and omit months without lines', function () {
    seedCashFlowLedger();

    $flows = collect(CashFlowAnalytics::between()->monthlyFlows())->keyBy('month');

    expect($flows->keys()->all())->toBe(['2026-06', '2026-08'])
        ->and($flows['2026-06'])->toMatchArray([
            'inflow' => 150_000,
            'outflow' => 240_000,
            'net' => -90_000,
            'regular_outflow' => 200_000,
            'one_off_outflow' => 30_000,
            'reimbursement_out' => 10_000,
            'reimbursement_in' => 0,
            'month_end_balance' => 910_000,
            'min_balance' => 900_000,
            'min_balance_date' => '2026-06-10',
            'lines' => 5,
            'is_partial' => false,
        ])
        ->and($flows['2026-08'])->toMatchArray([
            'inflow' => 304_000,
            'outflow' => 100_000,
            'regular_outflow' => 100_000,
            'reimbursement_in' => 4_000,
            'month_end_balance' => 1_114_000,
            'min_balance' => 1_114_000,
            'min_balance_date' => '2026-08-15',
            'is_partial' => true,
        ]);
});

test('outflow by category gives per-month amounts and period shares', function () {
    seedCashFlowLedger();

    $outflow = CashFlowAnalytics::between()->outflowByCategory();

    expect($outflow['total'])->toBe(340_000)
        ->and(array_column($outflow['categories'], 'category'))->toBe(['salary', 'tax', 'insurance', 'other', 'reimbursement'])
        ->and($outflow['categories'][0])->toBe(['category' => 'salary', 'label' => '薪資', 'amount' => 200_000, 'share_pct' => 58.8])
        ->and(array_keys($outflow['months']))->toBe(['2026-06', '2026-08'])
        ->and($outflow['months']['2026-08'])->toBe(['salary' => 0, 'tax' => 60_000, 'insurance' => 40_000, 'other' => 0, 'reimbursement' => 0]);
});

test('revenue by customer computes shares, top-N with others, and concentration', function () {
    seedCashFlowLedger();
    $customer = Customer::factory()->create(['short_name' => '丙公司']);
    $receivable = Receivable::factory()->create(['customer_id' => $customer->id]);
    BankTransaction::factory()->create(['txn_date' => '2026-08-20', 'category' => 'revenue', 'deposit' => 50_000, 'counterparty' => null, 'receivable_id' => $receivable->id]);

    $revenue = CashFlowAnalytics::between()->revenueByCustomer(top: 1);

    expect($revenue['total'])->toBe(500_000)
        ->and(array_column($revenue['customers'], 'customer'))->toBe(['A客戶', 'B客戶', '丙公司'])
        ->and($revenue['customers'][0])->toBe(['customer' => 'A客戶', 'amount' => 400_000, 'share_pct' => 80.0, 'months_paid' => 2])
        ->and($revenue['top'])->toBe([
            ['customer' => 'A客戶', 'amount' => 400_000, 'share_pct' => 80.0],
            ['customer' => '其他', 'amount' => 100_000, 'share_pct' => 20.0],
        ])
        ->and($revenue['months']['2026-06'])->toBe(['A客戶' => 100_000, 'B客戶' => 50_000, '丙公司' => 0])
        ->and($revenue['concentration'])->toBe([
            'customer_count' => 3,
            'top1_customer' => 'A客戶',
            'top1_share_pct' => 80.0,
            'top3_share_pct' => 100.0,
            'hhi' => 6_600, // 80² + 10² + 10²
        ]);
});

test('fixed cost trend excludes one-off lines', function () {
    seedCashFlowLedger();
    BankTransaction::factory()->create(['txn_date' => '2026-06-28', 'category' => 'salary', 'deposit' => 0, 'withdrawal' => 80_000, 'is_one_off' => true]);
    BankTransaction::factory()->create(['txn_date' => '2026-06-28', 'category' => 'rent', 'deposit' => 0, 'withdrawal' => 20_000]);

    $trend = CashFlowAnalytics::between()->fixedCostTrend();

    expect($trend['categories'])->toBe(['salary' => '薪資', 'insurance' => '勞健保', 'tax' => '稅', 'subscription' => '訂閱', 'rent' => '租金'])
        ->and($trend['months']['2026-06'])->toBe(['salary' => 200_000, 'insurance' => 0, 'tax' => 0, 'subscription' => 0, 'rent' => 20_000, 'total' => 220_000])
        ->and($trend['months']['2026-08']['total'])->toBe(100_000)
        ->and($trend['averages']['total'])->toBe(160_000);
});

test('daily balance carries the end-of-day balance forward and skips months without lines', function () {
    seedCashFlowLedger();

    $daily = collect(CashFlowAnalytics::between()->dailyBalance())->keyBy('date');

    expect($daily->keys()->first())->toBe('2026-06-05')
        ->and($daily->keys()->last())->toBe('2026-08-15')
        ->and($daily)->toHaveCount(26 + 15)
        ->and($daily->keys()->filter(fn (string $date): bool => str_starts_with($date, '2026-07')))->toBeEmpty()
        ->and($daily['2026-06-10'])->toBe(['date' => '2026-06-10', 'balance' => 950_000, 'deposit' => 50_000, 'withdrawal' => 200_000])
        ->and($daily['2026-06-11']['balance'])->toBe(950_000)
        ->and($daily['2026-08-01']['balance'])->toBe(910_000);

    expect(array_column(CashFlowAnalytics::between()->dailyBalance('2026-06-08', '2026-06-12'), 'balance'))
        ->toBe([1_100_000, 1_100_000, 950_000, 950_000, 950_000]);
});

test('daily balance sums accounts', function () {
    $main = BankAccount::factory()->create();
    $reserve = BankAccount::factory()->create();
    BankTransaction::factory()->create(['bank_account_id' => $main->id, 'txn_date' => '2026-06-01', 'balance' => 500_000]);
    BankTransaction::factory()->create(['bank_account_id' => $reserve->id, 'txn_date' => '2026-06-02', 'balance' => 200_000]);

    expect(array_column(CashFlowAnalytics::between()->dailyBalance(), 'balance'))->toBe([500_000, 700_000]);
});

test('runway divides the month-end balance by the trailing regular outflow average', function () {
    seedCashFlowLedger();

    expect(CashFlowAnalytics::between()->runwayHistory())->toBe([
        ['month' => '2026-06', 'month_end_balance' => 910_000, 'avg_regular_outflow' => 200_000, 'months_averaged' => 1, 'runway_months' => 4.55],
        ['month' => '2026-08', 'month_end_balance' => 1_114_000, 'avg_regular_outflow' => 150_000, 'months_averaged' => 2, 'runway_months' => 7.43],
    ]);

    expect(CashFlowAnalytics::between('2026-08', '2026-08')->runwayHistory())
        ->toHaveCount(1)
        ->sequence(fn ($month) => $month->avg_regular_outflow->toBe(150_000));
});

test('reimbursement balance is cumulative from the first line', function () {
    seedCashFlowLedger();

    $reimbursement = CashFlowAnalytics::between('2026-08', '2026-08')->reimbursementBalance();

    expect($reimbursement)->toMatchArray([
        'period_out' => 0,
        'period_in' => 4_000,
        'total_out' => 10_000,
        'total_in' => 4_000,
        'outstanding' => 6_000,
    ])->and($reimbursement['months'])->toBe([
        ['month' => '2026-08', 'out' => 0, 'in' => 4_000, 'cumulative_out' => 10_000, 'cumulative_in' => 4_000, 'outstanding' => 6_000],
    ]);
});

test('vat payments list tax lines per month', function () {
    seedCashFlowLedger();

    $vat = CashFlowAnalytics::between()->vatPayments();

    expect($vat['total'])->toBe(60_000)
        ->and(array_column($vat['months'], 'amount', 'month'))->toBe(['2026-06' => 0, '2026-08' => 60_000])
        ->and($vat['months'][1]['lines'][0])->toMatchArray(['date' => '2026-08-15', 'amount' => 60_000]);
});

test('collection performance measures days late against expected dates and lists overdue receivables', function () {
    $early = Customer::factory()->create(['short_name' => '準時客']);
    $late = Customer::factory()->create(['short_name' => '拖延客']);
    Receivable::factory()->create(['customer_id' => $early->id, 'status' => ReceivableStatus::Received, 'expected_on' => '2026-06-01', 'received_on' => '2026-06-05', 'amount_untaxed' => 100_000]);
    Receivable::factory()->create(['customer_id' => $early->id, 'status' => ReceivableStatus::Received, 'expected_on' => '2026-06-10', 'received_on' => '2026-06-08', 'amount_untaxed' => 100_000]);
    Receivable::factory()->create(['customer_id' => $late->id, 'status' => ReceivableStatus::Received, 'expected_on' => '2026-08-01', 'received_on' => '2026-08-11', 'amount_untaxed' => 200_000]);
    Receivable::factory()->create(['customer_id' => $late->id, 'status' => ReceivableStatus::Invoiced, 'expected_on' => '2026-09-01', 'item' => '尾款', 'amount_untaxed' => 50_000]);

    $performance = CashFlowAnalytics::between()->collectionPerformance();

    expect($performance['overall'])->toBe([
        'count' => 3,
        'on_time' => 1,
        'on_time_rate_pct' => 33.3,
        'avg_days_late' => 4.0,
        'avg_delay_when_late' => 7.0,
        'max_days_late' => 10,
        'amount_taxed' => 420_000,
    ])
        ->and($performance['by_customer'][0])->toMatchArray(['customer' => '準時客', 'count' => 2, 'on_time' => 1, 'on_time_rate_pct' => 50.0, 'avg_days_late' => 1.0, 'max_days_late' => 4])
        ->and($performance['overdue'])->toBe([
            'count' => 1,
            'amount_taxed' => 52_500,
            'items' => [['customer' => '拖延客', 'item' => '尾款', 'expected_on' => '2026-09-01', 'days_overdue' => 25, 'amount_taxed' => 52_500]],
        ]);

    expect(CashFlowAnalytics::between('2026-08-01', '2026-08-31')->collectionPerformance()['overall']['count'])->toBe(1);
});

test('the period filter narrows every section and the summary reports its headline numbers', function () {
    seedCashFlowLedger();

    $analytics = CashFlowAnalytics::between('2026-08', '2026-08');

    expect(array_column($analytics->monthlyFlows(), 'month'))->toBe(['2026-08'])
        ->and($analytics->revenueByCustomer()['total'])->toBe(300_000)
        ->and($analytics->period())->toMatchArray(['from' => '2026-08-01', 'to' => '2026-08-31', 'months' => ['2026-08']]);

    $summary = CashFlowAnalytics::between()->summary();

    expect($summary)->toMatchArray([
        'months_with_data' => 2,
        'total_inflow' => 454_000,
        'total_outflow' => 340_000,
        'net' => 114_000,
        'revenue' => 450_000,
        'avg_monthly_regular_outflow' => 150_000,
        'one_off_outflow' => 30_000,
        'opening_balance' => null,
        'closing_balance' => 1_114_000,
        'min_balance' => 900_000,
        'min_balance_date' => '2026-06-10',
        'reimbursement_outstanding' => 6_000,
    ])
        ->and($summary['revenue_concentration']['top3_share_pct'])->toBe(100.0)
        ->and(array_column($summary['largest_inflows'], 'amount'))->toBe([300_000, 100_000, 50_000, 4_000])
        ->and($summary['largest_outflows'][0])->toMatchArray(['date' => '2026-06-10', 'category' => 'salary', 'category_label' => '薪資', 'amount' => 200_000]);

    expect(CashFlowAnalytics::between()->summary('2026-08', '2026-08'))
        ->toMatchArray(['opening_balance' => 910_000, 'net' => 204_000]);
});

test('last months are anchored on the latest transaction month', function () {
    seedCashFlowLedger();

    $analytics = CashFlowAnalytics::lastMonths(2);

    expect($analytics->from->toDateString())->toBe('2026-07-01')
        ->and($analytics->to->toDateString())->toBe('2026-08-31')
        ->and($analytics->period()['months'])->toBe(['2026-08']);
});

test('no transactions gives empty sections', function () {
    $analytics = CashFlowAnalytics::between();

    expect($analytics->hasData())->toBeFalse()
        ->and($analytics->monthlyFlows())->toBe([])
        ->and($analytics->dailyBalance())->toBe([])
        ->and($analytics->summary())->toMatchArray(['months_with_data' => 0, 'net' => 0, 'closing_balance' => null]);
});

test('invalid bounds are rejected', function () {
    CashFlowAnalytics::between('2026/08');
})->throws(InvalidArgumentException::class);
