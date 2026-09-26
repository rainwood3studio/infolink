<?php

use App\Domain\Finance\ForecastAccuracy;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashForecast;
use App\Models\MetricValue;
use Database\Seeders\MetricDefinitionSeeder;

beforeEach(function () {
    $this->seed(MetricDefinitionSeeder::class);
    $this->account = BankAccount::factory()->create();
    $this->accuracy = app(ForecastAccuracy::class);

    $this->balanceOn = fn (string $date, int $balance): BankTransaction => BankTransaction::factory()->create([
        'bank_account_id' => $this->account->id,
        'txn_date' => $date,
        'balance' => $balance,
    ]);
    $this->forecast = fn (string $asOf, array $balances): CashForecast => CashForecast::factory()->create([
        'as_of' => $asOf,
        'rows' => collect($balances)->map(fn (int $balance, string $month): array => [
            'month' => $month, 'inflow' => 0, 'outflow' => 0, 'balance' => $balance, 'items' => [],
        ])->values()->all(),
    ]);
});

it('compares the latest forecast made in the previous month with the actual month-end balance', function () {
    ($this->forecast)('2026-08-10', ['2026-08' => 100_000, '2026-09' => 999_999]);
    $latestAugust = ($this->forecast)('2026-08-30', ['2026-08' => 200_000, '2026-09' => 1_000_000]);
    ($this->forecast)('2026-09-05', ['2026-09' => 1]);
    ($this->balanceOn)('2026-09-22', 1_072_776);
    ($this->balanceOn)('2026-09-30', 1_050_000);
    ($this->balanceOn)('2026-10-02', 5);

    expect($this->accuracy->history())->toBe([[
        'month' => '2026-09',
        'forecast_id' => $latestAugust->id,
        'forecast_as_of' => '2026-08-30',
        'predicted' => 1_000_000,
        'actual' => 1_050_000,
        'error' => 50_000,
        'error_ratio' => 0.05,
    ]]);
});

it('does not pair a month that has not been fully imported', function () {
    ($this->forecast)('2026-08-30', ['2026-09' => 1_000_000]);
    ($this->balanceOn)('2026-09-30', 1_050_000);

    expect($this->accuracy->history())->toBe([]);
});

it('does not pair a month without a forecast row for it or without a forecast in the previous month', function () {
    ($this->forecast)('2026-12-20', ['2026-12' => 1_000_000]);
    ($this->forecast)('2027-01-10', ['2027-01' => 1_000_000, '2027-03' => 900_000]);
    ($this->balanceOn)('2027-04-02', 800_000);

    expect($this->accuracy->history())->toBe([]);
});

it('records the error metrics idempotently', function () {
    ($this->forecast)('2026-08-30', ['2026-09' => 1_000_000]);
    ($this->balanceOn)('2026-09-30', 900_000);
    ($this->balanceOn)('2026-10-01', 900_000);

    $this->accuracy->record();
    $this->accuracy->record();

    $error = MetricValue::query()->where('metric_key', 'cash.forecast_error')->sole();

    expect((float) $error->value)->toBe(-100_000.0)
        ->and($error->period_start->toDateString())->toBe('2026-09-01')
        ->and($error->notes)->toContain('predicted 1,000,000')
        ->and((float) MetricValue::query()->where('metric_key', 'cash.forecast_error_ratio')->sole()->value)->toBe(-0.1);
});

it('skips the ratio when the predicted balance is zero', function () {
    ($this->forecast)('2026-08-30', ['2026-09' => 0]);
    ($this->balanceOn)('2026-09-15', 10_000);
    ($this->balanceOn)('2026-10-01', 10_000);

    $this->accuracy->record();

    expect(MetricValue::query()->where('metric_key', 'cash.forecast_error')->count())->toBe(1)
        ->and(MetricValue::query()->where('metric_key', 'cash.forecast_error_ratio')->count())->toBe(0);
});
