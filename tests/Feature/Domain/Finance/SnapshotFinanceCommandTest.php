<?php

use App\Models\BankTransaction;
use App\Models\CashForecast;
use App\Models\MetricValue;
use App\Models\Receivable;
use Database\Seeders\MetricDefinitionSeeder;

beforeEach(function () {
    $this->seed(MetricDefinitionSeeder::class);
});

test('it records finance metrics and a forecast snapshot', function () {
    BankTransaction::factory()->create(['txn_date' => today(), 'balance' => 1_000_000]);
    Receivable::factory()->create(['amount_untaxed' => 100_000, 'expected_on' => today()->addMonth()]);

    $this->artisan('infolink:snapshot-finance')->assertSuccessful();

    expect(CashForecast::count())->toBe(1)
        ->and((float) MetricValue::where('metric_key', 'cash.balance')->sole()->value)->toBe(1_000_000.0)
        ->and((float) MetricValue::where('metric_key', 'ar.outstanding_taxed')->sole()->value)->toBe(105_000.0);
});

test('running it twice on the same day keeps one value per metric', function () {
    BankTransaction::factory()->create(['txn_date' => today(), 'balance' => 1_000_000]);

    $this->artisan('infolink:snapshot-finance');
    $this->artisan('infolink:snapshot-finance');

    expect(MetricValue::where('metric_key', 'cash.balance')->count())->toBe(1);
});

test('it does nothing without bank data', function () {
    $this->artisan('infolink:snapshot-finance')->assertSuccessful();

    expect(MetricValue::count())->toBe(0);
});
