<?php

use App\Enums\TransactionCategory;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashForecast;
use App\Models\MetricValue;
use Database\Seeders\MetricDefinitionSeeder;

beforeEach(function () {
    $this->seed(MetricDefinitionSeeder::class);
    $this->account = BankAccount::factory()->create();
});

test('the daily snapshot records the previous and current month and is idempotent', function () {
    $this->travelTo('2026-10-02 07:00');
    BankTransaction::factory()->for($this->account)->create(['txn_date' => '2026-09-18', 'category' => TransactionCategory::Revenue, 'deposit' => 860_000, 'balance' => 1_000_000]);
    BankTransaction::factory()->for($this->account)->create(['txn_date' => '2026-10-01', 'category' => TransactionCategory::Salary, 'deposit' => 0, 'withdrawal' => 100_000, 'balance' => 900_000]);
    CashForecast::factory()->create(['as_of' => '2026-08-30', 'rows' => [['month' => '2026-09', 'inflow' => 0, 'outflow' => 0, 'balance' => 800_000, 'items' => []]]]);

    $this->artisan('infolink:snapshot-finance')->assertSuccessful();
    $this->artisan('infolink:snapshot-finance')->assertSuccessful();

    $received = MetricValue::query()->where('metric_key', 'revenue.received')->orderBy('period_start')->get();

    expect($received->map(fn (MetricValue $value): array => [$value->period_start->format('Y-m'), (float) $value->value, $value->notes])->all())->toBe([
        ['2026-09', 860_000.0, null],
        ['2026-10', 0.0, 'partial month (data through 2026-10-01)'],
    ])
        ->and((float) MetricValue::query()->where('metric_key', 'cash.forecast_error')->sole()->value)->toBe(200_000.0);
});

test('the backfill records every month with line-level data', function () {
    BankTransaction::factory()->for($this->account)->create(['txn_date' => '2026-07-06', 'category' => TransactionCategory::Salary, 'deposit' => 0, 'withdrawal' => 100_000]);
    BankTransaction::factory()->for($this->account)->create(['txn_date' => '2026-09-06', 'category' => TransactionCategory::Salary, 'deposit' => 0, 'withdrawal' => 120_000]);

    $this->artisan('infolink:backfill-finance-metrics', ['--without-summary' => true])->assertSuccessful();
    $this->artisan('infolink:backfill-finance-metrics', ['--without-summary' => true, '--from' => '2026-09'])->assertSuccessful();

    expect(MetricValue::query()->where('metric_key', 'cash.monthly_cost')->orderBy('period_start')->pluck('value')->map(fn ($value): float => (float) $value)->all())
        ->toBe([100_000.0, 120_000.0]);
});

test('the backfill rejects a malformed month', function () {
    $this->artisan('infolink:backfill-finance-metrics', ['--from' => '2026-9'])->assertFailed();
});
