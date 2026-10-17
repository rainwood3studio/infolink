<?php

use App\Filament\Pages\ExpenseCalendar;
use App\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use App\Models\CostBaseline;
use App\Models\PlannedCashFlow;
use App\Models\RecurringExpense;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00'));
    $this->actingAs(User::factory()->create());
});

it('lists the coming months with every occurrence, the baseline badge and the headline figures', function () {
    CostBaseline::factory()->create(['effective_from' => '2026-01-01', 'monthly_cost' => 229_666]);
    $vat = RecurringExpense::factory()->vat(60_000)->create(['starts_on' => '2026-01-01']);
    RecurringExpense::factory()->inBaseline()->create(['name' => '勞健保', 'amount' => 30_000, 'due_day' => 31, 'category' => 'insurance', 'starts_on' => '2026-01-01']);
    PlannedCashFlow::factory()->create(['flow_on' => '2027-01-20', 'amount' => -200_000, 'description' => '年終獎金']);

    $this->get(ExpenseCalendar::getUrl())->assertOk()->assertSee('支出日曆');

    Livewire::test(ExpenseCalendar::class)
        ->assertSeeInOrder(['月成本基準', 'NT$229,666', '接下來 90 天額外固定支出', 'NT$60,000'])
        ->assertSeeInOrder(['2026 年 10 月', '額外', 'NT$0', '已含月成本 NT$30,000', '10/31（六）', '勞健保', '已含月成本'])
        ->assertSeeInOrder(['2026 年 11 月', 'NT$60,000', '11/15（日）', '營業稅', '稅務', '11/30（一）', '勞健保'])
        ->assertSeeInOrder(['2027 年 1 月', 'NT$260,000', '1/15（五）', '營業稅', '1/20（三）', '年終獎金', '已排定', 'NT$200,000'])
        ->assertSee('2027 年 3 月')
        ->assertDontSee('2027 年 4 月')
        ->assertDontSee('還沒有固定支出')
        ->assertSeeHtml('href="'.RecurringExpenseResource::getUrl('edit', ['record' => $vat->id]).'"');
});

it('shows an empty state that points to creating the first expense', function () {
    Livewire::test(ExpenseCalendar::class)
        ->assertSee('還沒有固定支出')
        ->assertSee('先新增營業稅（雙月 15 日）、勞健保（每月月底）、營所稅（每年 5 月）')
        ->assertSee('月成本基準')
        ->assertSee('尚未設定')
        ->assertSeeHtml('href="'.RecurringExpenseResource::getUrl('create').'"');
});
