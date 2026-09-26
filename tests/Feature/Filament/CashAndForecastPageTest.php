<?php

use App\Domain\Finance\CashForecaster;
use App\Enums\Source;
use App\Filament\Pages\CashAndForecast;
use App\Filament\Widgets\CashBalanceTrendChart;
use App\Filament\Widgets\CurrentForecastChart;
use App\Filament\Widgets\ForecastVersionsChart;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashForecast;
use App\Models\CostBaseline;
use App\Models\Receivable;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('month-end balances take the last balance per month, summed across accounts and carried forward', function () {
    $main = BankAccount::factory()->create();
    $reserve = BankAccount::factory()->create();

    BankTransaction::factory()->create(['bank_account_id' => $main->id, 'txn_date' => '2026-06-03', 'sequence' => 1, 'balance' => 900_000]);
    BankTransaction::factory()->create(['bank_account_id' => $main->id, 'txn_date' => '2026-06-28', 'sequence' => 1, 'balance' => 800_000]);
    BankTransaction::factory()->create(['bank_account_id' => $reserve->id, 'txn_date' => '2026-06-15', 'sequence' => 1, 'balance' => 100_000]);
    BankTransaction::factory()->create(['bank_account_id' => $main->id, 'txn_date' => '2026-08-10', 'sequence' => 1, 'balance' => 700_000]);

    expect(CashBalanceTrendChart::monthEndBalances())->toBe([
        '2026-06' => 900_000,
        '2026-07' => 900_000,
        '2026-08' => 800_000,
    ]);
});

test('the page renders the charts and the forecast snapshots', function () {
    CostBaseline::factory()->create(['effective_from' => today()->subYear(), 'monthly_cost' => 200_000]);
    BankTransaction::factory()->create(['txn_date' => today(), 'balance' => 1_000_000]);
    Receivable::factory()->create(['amount_untaxed' => 400_000, 'expected_on' => today()->addMonth()]);
    app(CashForecaster::class)->snapshot(source: Source::Claude);
    CashForecast::factory()->create(['year_end_balance' => 1_234_000, 'min_balance' => 456_000, 'min_balance_month' => '2026-11']);

    Livewire::test(CashAndForecast::class)
        ->assertOk()
        ->assertSeeLivewire(CashBalanceTrendChart::class)
        ->assertSeeLivewire(CurrentForecastChart::class)
        ->assertSeeLivewire(ForecastVersionsChart::class)
        ->assertCanSeeTableRecords(CashForecast::all())
        ->assertSee('Claude')
        ->assertSee('NT$1,234,000')
        ->assertSee('2026-11');

    $this->get('/admin/cash')->assertOk()->assertSee('現金與推估');
});

test('the live forecast chart explains its method', function () {
    BankTransaction::factory()->create(['txn_date' => today(), 'balance' => 1_000_000]);

    Livewire::test(CurrentForecastChart::class)
        ->assertOk()
        ->assertSee('方法：high-confidence outstanding receivables');
});

test('the version chart overlays year-end and minimum balances oldest first', function () {
    CashForecast::factory()->create(['year_end_balance' => 1_000_000, 'min_balance' => 500_000]);
    CashForecast::factory()->create(['year_end_balance' => 1_200_000, 'min_balance' => 600_000]);

    $data = (fn () => $this->getData())->call(new ForecastVersionsChart);

    expect($data['datasets'][0]['data'])->toBe([1_000_000, 1_200_000])
        ->and($data['datasets'][1]['data'])->toBe([500_000, 600_000]);
});
