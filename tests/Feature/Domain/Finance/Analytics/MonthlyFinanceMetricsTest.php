<?php

use App\Domain\Finance\MonthlyFinanceMetrics;
use App\Enums\ReceivableStatus;
use App\Enums\Source;
use App\Enums\TransactionCategory;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\MetricValue;
use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Database\Seeders\MetricDefinitionSeeder;

beforeEach(function () {
    $this->seed(MetricDefinitionSeeder::class);
    $this->account = BankAccount::factory()->create(['name' => '彰銀中壢']);
    $this->metrics = app(MonthlyFinanceMetrics::class);

    $this->txn = fn (string $date, TransactionCategory $category, int $withdrawal = 0, int $deposit = 0, bool $oneOff = false, int $balance = 0): BankTransaction => BankTransaction::factory()->create([
        'bank_account_id' => $this->account->id,
        'txn_date' => $date,
        'category' => $category,
        'withdrawal' => $withdrawal,
        'deposit' => $deposit,
        'is_one_off' => $oneOff,
        'balance' => $balance,
    ]);
});

function monthlyFinanceMetric(string $key, string $month): ?MetricValue
{
    return MetricValue::query()->where('metric_key', $key)->whereDate('period_start', "{$month}-01")->first();
}

it('computes the monthly formulas from line-level transactions', function () {
    ($this->txn)('2026-08-03', TransactionCategory::Insurance, withdrawal: 40_000);
    ($this->txn)('2026-08-06', TransactionCategory::Salary, withdrawal: 160_000);
    ($this->txn)('2026-08-10', TransactionCategory::Salary, withdrawal: 7_000, oneOff: true);
    ($this->txn)('2026-08-12', TransactionCategory::Subscription, withdrawal: 50_000);
    ($this->txn)('2026-08-18', TransactionCategory::Reimbursement, withdrawal: 44_900);
    ($this->txn)('2026-08-17', TransactionCategory::Revenue, deposit: 199_990);
    ($this->txn)('2026-08-21', TransactionCategory::Revenue, deposit: 20_000);
    ($this->txn)('2026-08-22', TransactionCategory::Other, deposit: 1_000);
    ($this->txn)('2026-09-01', TransactionCategory::Other, withdrawal: 1);
    Receivable::factory()->create(['is_recurring' => true, 'amount_untaxed' => 100_000, 'tax_rate' => 0.05, 'expected_on' => '2026-08-31', 'status' => ReceivableStatus::Received]);
    Receivable::factory()->create(['is_recurring' => true, 'amount_untaxed' => 20_000, 'tax_rate' => 0, 'expected_on' => '2026-08-31']);
    Receivable::factory()->create(['is_recurring' => true, 'amount_untaxed' => 50_000, 'expected_on' => '2026-08-31', 'status' => ReceivableStatus::Cancelled]);
    Receivable::factory()->create(['is_recurring' => false, 'amount_untaxed' => 500_000, 'expected_on' => '2026-08-31']);

    $values = $this->metrics->record(CarbonImmutable::parse('2026-08-15'));

    expect($values)->toBe([
        'revenue.received' => 219_990,
        'cash.monthly_cost' => 250_000,
        'cost.personnel_ratio' => 0.8,
        'revenue.recurring_monthly' => 125_000,
    ])
        ->and((float) monthlyFinanceMetric('cash.monthly_cost', '2026-08')->value)->toBe(250_000.0)
        ->and(monthlyFinanceMetric('cash.monthly_cost', '2026-08')->notes)->toBeNull()
        ->and(monthlyFinanceMetric('cash.monthly_cost', '2026-08')->source)->toBe(Source::System);
});

it('flags the month as partial until a later transaction exists', function () {
    ($this->txn)('2026-09-04', TransactionCategory::Salary, withdrawal: 100_000);
    ($this->txn)('2026-09-22', TransactionCategory::Revenue, deposit: 860_000);

    $this->metrics->record(CarbonImmutable::parse('2026-09-01'));

    expect(monthlyFinanceMetric('revenue.received', '2026-09')->notes)->toBe('partial month (data through 2026-09-22)');

    ($this->txn)('2026-10-01', TransactionCategory::Other, withdrawal: 500);
    $this->metrics->record(CarbonImmutable::parse('2026-09-01'));

    expect(monthlyFinanceMetric('revenue.received', '2026-09')->notes)->toBeNull()
        ->and(MetricValue::query()->where('metric_key', 'revenue.received')->count())->toBe(1);
});

it('does not record months without transactions', function () {
    ($this->txn)('2026-07-06', TransactionCategory::Salary, withdrawal: 100_000);
    ($this->txn)('2026-09-06', TransactionCategory::Salary, withdrawal: 100_000);
    Receivable::factory()->create(['is_recurring' => true, 'expected_on' => '2026-08-31']);

    expect($this->metrics->record(CarbonImmutable::parse('2026-08-01')))->toBe([])
        ->and(array_keys($this->metrics->recordRange()))->toBe(['2026-07', '2026-09'])
        ->and(MetricValue::query()->whereDate('period_start', '2026-08-01')->count())->toBe(0);
});

it('skips the personnel ratio when there is no regular outflow and the recurring revenue when none is modelled', function () {
    ($this->txn)('2026-08-17', TransactionCategory::Revenue, deposit: 199_990);
    ($this->txn)('2026-08-18', TransactionCategory::Reimbursement, withdrawal: 44_900);

    expect($this->metrics->record(CarbonImmutable::parse('2026-08-01')))->toBe([
        'revenue.received' => 199_990,
        'cash.monthly_cost' => 0,
    ]);
});

it('accumulates the net cash flow from January, including reimbursements and one-offs', function () {
    ($this->txn)('2027-01-05', TransactionCategory::Revenue, deposit: 300_000);
    ($this->txn)('2027-01-06', TransactionCategory::Salary, withdrawal: 100_000);
    ($this->txn)('2027-02-06', TransactionCategory::Reimbursement, withdrawal: 50_000);
    ($this->txn)('2027-02-07', TransactionCategory::Other, withdrawal: 10_000, oneOff: true);

    $recorded = $this->metrics->recordRange();

    expect($recorded['2027-01']['company.net_cashflow_ytd'])->toBe(200_000)
        ->and($recorded['2027-02']['company.net_cashflow_ytd'])->toBe(140_000);
});

it('does not guess the year-to-date net cash flow without the previous month', function () {
    ($this->txn)('2026-07-06', TransactionCategory::Revenue, deposit: 300_000);

    expect($this->metrics->record(CarbonImmutable::parse('2026-07-01')))->not->toHaveKey('company.net_cashflow_ytd');
});

it('seeds the year-to-date net cash flow from a matching monthly summary and chains onto it', function () {
    ($this->txn)('2026-03-06', TransactionCategory::Salary, withdrawal: 50_000, balance: 70_000);
    ($this->txn)('2026-03-20', TransactionCategory::Revenue, deposit: 100_000, balance: 170_000);

    $result = $this->metrics->recordSummaryYtd([
        'account_name' => '彰銀中壢',
        'rows' => [
            ['month' => '2025-12', 'deposit' => 500, 'withdrawal' => 0, 'closing_balance' => 200_000],
            ['month' => '2026-01', 'deposit' => 10_000, 'withdrawal' => 60_000, 'closing_balance' => 150_000],
            ['month' => '2026-02', 'deposit' => 0, 'withdrawal' => 30_000, 'closing_balance' => 120_000],
            ['month' => '2026-03', 'deposit' => 1, 'withdrawal' => 1, 'closing_balance' => 1],
        ],
    ], 'vault/note.md');

    expect($result)->toBe(['recorded' => ['2026-01' => -50_000, '2026-02' => -80_000], 'skipped' => null])
        ->and(monthlyFinanceMetric('company.net_cashflow_ytd', '2026-02')->source)->toBe(Source::Vault)
        ->and(monthlyFinanceMetric('company.net_cashflow_ytd', '2026-02')->vault_ref)->toBe('vault/note.md')
        ->and($this->metrics->record(CarbonImmutable::parse('2026-03-01'))['company.net_cashflow_ytd'])->toBe(-30_000);
});

it('refuses a monthly summary whose closing balance does not match the first transaction', function () {
    ($this->txn)('2026-03-06', TransactionCategory::Salary, withdrawal: 50_000, balance: 70_000);

    $result = $this->metrics->recordSummaryYtd([
        'account_name' => '彰銀中壢',
        'rows' => [
            ['month' => '2026-01', 'deposit' => 0, 'withdrawal' => 0, 'closing_balance' => 120_000],
            ['month' => '2026-02', 'deposit' => 0, 'withdrawal' => 0, 'closing_balance' => 119_999],
        ],
    ]);

    expect($result['recorded'])->toBe([])
        ->and($result['skipped'])->toContain('does not match')
        ->and(MetricValue::query()->count())->toBe(0);
});
