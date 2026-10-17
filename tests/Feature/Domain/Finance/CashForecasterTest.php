<?php

use App\Domain\Finance\CashForecaster;
use App\Enums\Confidence;
use App\Enums\ReceivableStatus;
use App\Enums\Source;
use App\Enums\TransactionCategory;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashForecast;
use App\Models\CostBaseline;
use App\Models\PlannedCashFlow;
use App\Models\Receivable;
use App\Models\RecurringExpense;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->forecaster = app(CashForecaster::class);
    $this->asOf = CarbonImmutable::parse('2026-10-20');

    CostBaseline::factory()->create(['effective_from' => '2026-01-01', 'monthly_cost' => 100_000]);

    $account = BankAccount::factory()->create(['is_primary' => true]);
    $booked = [
        ['txn_date' => '2026-09-30', 'withdrawal' => 70_000, 'balance' => 600_000],
        ['txn_date' => '2026-10-05', 'withdrawal' => 60_000, 'balance' => 540_000],
        ['txn_date' => '2026-10-06', 'withdrawal' => 30_000, 'balance' => 510_000, 'is_one_off' => true],
        ['txn_date' => '2026-10-07', 'withdrawal' => 20_000, 'balance' => 490_000, 'category' => TransactionCategory::Reimbursement],
        ['txn_date' => '2026-10-20', 'withdrawal' => 0, 'deposit' => 10_000, 'balance' => 500_000],
    ];

    foreach ($booked as $sequence => $attributes) {
        BankTransaction::factory()->for($account)->create([...$attributes, 'deposit' => $attributes['deposit'] ?? 0, 'sequence' => $sequence]);
    }

    $this->overdue = Receivable::factory()->create(['amount_untaxed' => 50_000, 'expected_on' => '2026-09-30']);
    $this->november = Receivable::factory()->create(['amount_untaxed' => 200_000, 'expected_on' => '2026-11-30', 'status' => ReceivableStatus::Invoiced]);
    $this->recurring = Receivable::factory()->create(['amount_untaxed' => 10_000, 'tax_rate' => 0, 'expected_on' => '2026-12-31', 'is_recurring' => true]);
    $this->low = Receivable::factory()->create(['amount_untaxed' => 100_000, 'expected_on' => '2026-11-30', 'confidence' => Confidence::Low]);
    Receivable::factory()->create(['amount_untaxed' => 300_000, 'expected_on' => '2026-11-15', 'status' => ReceivableStatus::Received]);
    Receivable::factory()->create(['amount_untaxed' => 400_000, 'expected_on' => '2027-01-31']);

    PlannedCashFlow::factory()->create(['flow_on' => '2026-11-15', 'amount' => -30_000, 'description' => '營業稅']);
    PlannedCashFlow::factory()->create(['flow_on' => '2026-10-10', 'amount' => -99_999, 'description' => '已發生']);
    PlannedCashFlow::factory()->create(['flow_on' => '2026-12-20', 'amount' => 5_000, 'description' => '退款']);
});

it('forecasts month-end balances through year end', function () {
    $result = $this->forecaster->calculate($this->asOf, 500_000);

    expect(collect($result->rows)->map(fn (array $row): array => [$row['month'], $row['inflow'], $row['outflow'], $row['balance']])->all())->toBe([
        ['2026-10', 52_500, 40_000, 512_500],
        ['2026-11', 210_000, 130_000, 592_500],
        ['2026-12', 15_000, 100_000, 507_500],
    ])
        ->and($result->openingBalance)->toBe(500_000)
        ->and($result->yearEndBalance)->toBe(507_500)
        ->and($result->minBalance)->toBe(507_500)
        ->and($result->minBalanceMonth)->toBe('2026-12');
});

it('deducts only the unpaid part of the as_of month cost', function () {
    $assumptions = $this->forecaster->calculate($this->asOf, 500_000)->assumptions;

    expect($assumptions['as_of_month_cost'])->toBe([
        'month' => '2026-10',
        'monthly_cost' => 100_000,
        'booked_regular_outflow' => 60_000,
        'deducted' => 40_000,
    ]);
});

it('records which receivables and flows were assumed', function () {
    $assumptions = $this->forecaster->calculate($this->asOf, 500_000)->assumptions;

    expect(collect($assumptions['receivables'])->pluck('id')->all())->toBe([$this->overdue->id, $this->november->id, $this->recurring->id])
        ->and($assumptions['receivables'][0])->toMatchArray(['month' => '2026-10', 'overdue' => true, 'amount_taxed' => 52_500])
        ->and($assumptions['excluded_low_confidence'])->toBe(['count' => 1, 'amount_taxed' => 105_000, 'ids' => [$this->low->id]])
        ->and(collect($assumptions['planned_cash_flows'])->pluck('description')->all())->toBe(['營業稅', '退款'])
        ->and($assumptions['cost_baselines'][0]['months'])->toBe(['2026-10', '2026-11', '2026-12'])
        ->and($assumptions['opening_balance_source'])->toBe('given');
});

it('defaults the opening balance and as_of to the latest bank balance', function () {
    $result = $this->forecaster->calculate(until: '2026-11');

    expect($result->asOf->toDateString())->toBe('2026-10-20')
        ->and($result->openingBalance)->toBe(500_000)
        ->and($result->assumptions['opening_balance_source'])->toBe('bank')
        ->and($result->rows)->toHaveCount(2)
        ->and($result->yearEndBalance)->toBe(592_500);
});

it('uses the cost baseline effective in each month', function () {
    CostBaseline::factory()->create(['effective_from' => '2026-12-01', 'monthly_cost' => 150_000]);

    $result = $this->forecaster->calculate($this->asOf, 500_000);

    expect($result->rows[2]['outflow'])->toBe(150_000)
        ->and($result->yearEndBalance)->toBe(457_500);
});

it('persists a snapshot', function () {
    $snapshot = $this->forecaster->snapshot($this->asOf, 500_000);

    expect(CashForecast::count())->toBe(1)
        ->and($snapshot->fresh()->as_of->toDateString())->toBe('2026-10-20')
        ->and($snapshot->fresh()->year_end_balance)->toBe(507_500)
        ->and($snapshot->fresh()->min_balance_month)->toBe('2026-12')
        ->and($snapshot->fresh()->rows)->toHaveCount(3)
        ->and($snapshot->fresh()->source)->toBe(Source::System);
});

describe('recurring expenses', function () {
    it('deducts each occurrence due after as_of unless it is already in the baseline', function () {
        $vat = RecurringExpense::factory()->vat(30_000)->create(['starts_on' => '2026-01-01']);
        RecurringExpense::factory()->create(['name' => '租金', 'amount' => 20_000, 'due_day' => 25, 'starts_on' => '2026-01-01']);
        RecurringExpense::factory()->inBaseline()->create(['name' => '勞健保', 'amount' => 15_000, 'due_day' => 31, 'starts_on' => '2026-01-01']);
        RecurringExpense::factory()->create(['name' => '已停用', 'amount' => 99_999, 'due_day' => 1, 'starts_on' => '2026-01-01', 'is_active' => false]);
        RecurringExpense::factory()->create(['name' => '已結束', 'amount' => 99_999, 'due_day' => 1, 'starts_on' => '2026-01-01', 'ends_on' => '2026-10-15']);
        RecurringExpense::factory()->create(['name' => '當天到期', 'amount' => 99_999, 'due_day' => 20, 'starts_on' => '2026-01-01', 'ends_on' => '2026-10-31']);

        $result = $this->forecaster->calculate($this->asOf, 500_000);

        expect(collect($result->rows)->map(fn (array $row): array => [$row['month'], $row['outflow'], $row['balance']])->all())->toBe([
            ['2026-10', 60_000, 492_500],
            ['2026-11', 180_000, 522_500],
            ['2026-12', 120_000, 417_500],
        ])
            ->and($result->minBalance)->toBe(417_500)
            ->and(collect($result->rows[1]['items'])->firstWhere('label', '營業稅'))->toBe(['label' => '營業稅', 'amount' => -30_000])
            ->and($result->assumptions['recurring_expenses'])->toBe([
                ['id' => $vat->id + 1, 'name' => '租金', 'due_on' => '2026-10-25', 'amount' => 20_000, 'category' => 'other'],
                ['id' => $vat->id, 'name' => '營業稅', 'due_on' => '2026-11-15', 'amount' => 30_000, 'category' => 'tax'],
                ['id' => $vat->id + 1, 'name' => '租金', 'due_on' => '2026-11-25', 'amount' => 20_000, 'category' => 'other'],
                ['id' => $vat->id + 1, 'name' => '租金', 'due_on' => '2026-12-25', 'amount' => 20_000, 'category' => 'other'],
            ])
            ->and($result->assumptions['method'])->toContain('recurring fixed expenses');
    });

    it('leaves the forecast unchanged when there are no recurring expenses', function () {
        $result = $this->forecaster->calculate($this->asOf, 500_000);

        expect($result->assumptions['recurring_expenses'])->toBe([])
            ->and($result->yearEndBalance)->toBe(507_500);
    });
});
