<?php

use App\Domain\Finance\ReceivableService;
use App\Enums\Confidence;
use App\Filament\Widgets\CashForecastChart;
use App\Filament\Widgets\FinanceStatsWidget;
use App\Filament\Widgets\ReceivableScheduleChart;
use App\Models\BankTransaction;
use App\Models\CostBaseline;
use App\Models\Receivable;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the finance cards show an empty state before any bank data exists', function () {
    Livewire::test(FinanceStatsWidget::class)->assertSee('尚未匯入銀行交易');
});

test('the finance cards reflect balance, runway and receivables', function () {
    CostBaseline::factory()->create(['effective_from' => today()->subYear(), 'monthly_cost' => 200_000]);
    BankTransaction::factory()->create(['txn_date' => today(), 'balance' => 1_000_000]);
    Receivable::factory()->create(['amount_untaxed' => 400_000, 'expected_on' => today()->addMonth()]);
    Receivable::factory()->create(['amount_untaxed' => 100_000, 'expected_on' => today()->subDays(5)]);
    Receivable::factory()->create(['amount_untaxed' => 200_000, 'confidence' => Confidence::Low]);

    Livewire::test(FinanceStatsWidget::class)
        ->assertSee('100.0 萬')
        ->assertSee('5.0')
        ->assertSee('52.5 萬')
        ->assertSee('低確定性另有 21.0 萬')
        ->assertSee('10.5 萬')
        ->assertSee('需要追款');
});

test('marking a receivable received updates the cards immediately', function () {
    BankTransaction::factory()->create(['txn_date' => today(), 'balance' => 1_000_000]);
    $receivable = Receivable::factory()->create(['amount_untaxed' => 100_000, 'expected_on' => today()->subDays(5)]);

    $widget = Livewire::test(FinanceStatsWidget::class)->assertSee('需要追款');

    app(ReceivableService::class)->markReceived($receivable, today());

    $widget->call('$refresh')->assertSee('沒有逾期');
});

test('the charts render with forecast and schedule data', function () {
    BankTransaction::factory()->create(['txn_date' => today(), 'balance' => 1_000_000]);
    Receivable::factory()->create(['expected_on' => today()->addMonth()]);

    Livewire::test(CashForecastChart::class)->assertOk();
    Livewire::test(ReceivableScheduleChart::class)->assertOk();
});

test('the dashboard forecast chart stops at year end', function () {
    $this->travelTo('2026-09-26');
    BankTransaction::factory()->create(['txn_date' => '2026-09-22', 'balance' => 1_000_000]);

    $data = invade(Livewire::test(CashForecastChart::class)->instance())->getData();

    expect(end($data['labels']))->toBe('2026-12');
});
