<?php

use App\Enums\ReceivableStatus;
use App\Filament\Pages\CashFlowAnalysis;
use App\Filament\Widgets\CashFlow\CashFlowKpiWidget;
use App\Filament\Widgets\CashFlow\CollectionPerformanceTable;
use App\Filament\Widgets\CashFlow\CustomerConcentrationChart;
use App\Filament\Widgets\CashFlow\CustomerRevenueChart;
use App\Filament\Widgets\CashFlow\DailyBalanceChart;
use App\Filament\Widgets\CashFlow\FixedCostTrendChart;
use App\Filament\Widgets\CashFlow\LargestInflowsTable;
use App\Filament\Widgets\CashFlow\LargestOutflowsTable;
use App\Filament\Widgets\CashFlow\MonthlyCashFlowChart;
use App\Filament\Widgets\CashFlow\OutflowCompositionChart;
use App\Filament\Widgets\CashFlow\OutflowStructureChart;
use App\Filament\Widgets\CashFlow\OutflowTypeChart;
use App\Filament\Widgets\CashFlow\RunwayHistoryChart;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-26 10:00:00');
    $this->actingAs(User::factory()->create());

    $account = BankAccount::factory()->create();
    $lines = [
        ['2025-12-05', 'revenue', 777_001, 0, '舊客戶'],
        ['2025-12-10', 'salary', 0, 210_000, null],
        ['2026-08-05', 'revenue', 319_217, 0, '墊腳石國際'],
        ['2026-08-10', 'salary', 0, 226_137, null],
        ['2026-08-11', 'insurance', 0, 41_000, null],
        ['2026-09-05', 'reimbursement', 0, 12_000, null],
    ];
    $balance = 500_000;

    foreach ($lines as $index => [$date, $category, $deposit, $withdrawal, $counterparty]) {
        $balance += $deposit - $withdrawal;
        BankTransaction::factory()->create(['bank_account_id' => $account->id, 'txn_date' => $date, 'sequence' => $index, 'category' => $category, 'deposit' => $deposit, 'withdrawal' => $withdrawal, 'balance' => $balance, 'counterparty' => $counterparty, 'summary' => "摘要{$index}"]);
    }

    Receivable::factory()->create([
        'customer_id' => Customer::factory()->create(['short_name' => '墊腳石'])->id,
        'status' => ReceivableStatus::Received,
        'expected_on' => '2026-08-01',
        'received_on' => '2026-08-05',
    ]);
});

test('the page renders the period filter and every widget', function () {
    Livewire::test(CashFlowAnalysis::class)
        ->assertOk()
        ->assertSee('期間')
        ->assertSeeLivewire(CashFlowKpiWidget::class)
        ->assertSeeLivewire(MonthlyCashFlowChart::class)
        ->assertSeeLivewire(OutflowTypeChart::class)
        ->assertSeeLivewire(OutflowStructureChart::class)
        ->assertSeeLivewire(OutflowCompositionChart::class)
        ->assertSeeLivewire(CustomerRevenueChart::class)
        ->assertSeeLivewire(CustomerConcentrationChart::class)
        ->assertSeeLivewire(FixedCostTrendChart::class)
        ->assertSeeLivewire(RunwayHistoryChart::class)
        ->assertSeeLivewire(DailyBalanceChart::class)
        ->assertSeeLivewire(CollectionPerformanceTable::class)
        ->assertSeeLivewire(LargestInflowsTable::class)
        ->assertSeeLivewire(LargestOutflowsTable::class);

    $this->get('/admin/cash-flow')->assertOk()->assertSee('金流分析');
    $this->get('/admin/cash-flow?filters[period]=custom&filters[from]=2025-12-01&filters[to]=2025-12-31')->assertOk();
});

test('each widget renders for the chosen period', function (string $widget, string $expected) {
    Livewire::test($widget, ['pageFilters' => ['period' => 'all']])
        ->assertOk()
        ->assertSee($expected);
})->with([
    'kpi' => [CashFlowKpiWidget::class, '109.6 萬'],
    'monthly' => [MonthlyCashFlowChart::class, '2026-08'],
    'outflow type' => [OutflowTypeChart::class, '代墊支出'],
    'outflow structure' => [OutflowStructureChart::class, '勞健保'],
    'outflow composition' => [OutflowCompositionChart::class, '期間支出組成'],
    'customer revenue' => [CustomerRevenueChart::class, '墊腳石國際'],
    'concentration' => [CustomerConcentrationChart::class, 'HHI'],
    'fixed costs' => [FixedCostTrendChart::class, '226137'],
    'runway' => [RunwayHistoryChart::class, '可撐月數'],
    'daily balance' => [DailyBalanceChart::class, '2026-08-11'],
    'collection' => [CollectionPerformanceTable::class, '墊腳石'],
    'largest inflows' => [LargestInflowsTable::class, '777,001'],
    'largest outflows' => [LargestOutflowsTable::class, '226,137'],
]);

test('the period filter changes the widget data', function () {
    Livewire::test(CustomerRevenueChart::class, ['pageFilters' => ['period' => '3m']])
        ->assertSee('墊腳石國際')
        ->assertDontSee('舊客戶');

    Livewire::test(CustomerRevenueChart::class, ['pageFilters' => ['period' => 'custom', 'from' => '2025-12-01', 'to' => '2025-12-31']])
        ->assertSee('舊客戶')
        ->assertDontSee('墊腳石國際');

    Livewire::test(LargestInflowsTable::class, ['pageFilters' => ['period' => '6m']])
        ->assertSee('319,217')
        ->assertDontSee('777,001');

    Livewire::test(CashFlowAnalysis::class)
        ->set('filters.period', 'custom')
        ->assertFormFieldVisible('from', 'filtersForm')
        ->set('filters.from', '2025-12-01')
        ->assertOk();
});

test('filters resolve to a period with safe fallbacks', function () {
    expect(CashFlowAnalysis::analyticsFor(null)->from->toDateString())->toBe('2025-10-01')
        ->and(CashFlowAnalysis::analyticsFor(['period' => '3m'])->from->toDateString())->toBe('2026-07-01')
        ->and(CashFlowAnalysis::analyticsFor(['period' => 'all'])->from)->toBeNull()
        ->and(CashFlowAnalysis::analyticsFor(['period' => 'custom', 'from' => '2026-01-15', 'to' => 'garbage'])->to)->toBeNull()
        ->and(CashFlowAnalysis::analyticsFor(['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-01-01'])->from)->toBeNull()
        ->and(CashFlowAnalysis::analyticsFor(['period' => 'bogus'])->from->toDateString())->toBe('2025-10-01');
});

test('widgets show an empty state without data', function () {
    BankTransaction::query()->delete();

    Livewire::test(MonthlyCashFlowChart::class, ['pageFilters' => ['period' => 'all']])
        ->assertOk()
        ->assertSee('這段期間沒有逐筆交易資料');

    Livewire::test(CashFlowKpiWidget::class, ['pageFilters' => ['period' => 'all']])
        ->assertOk();
});
