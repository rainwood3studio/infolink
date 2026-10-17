<?php

use App\Enums\ExpenseCadence;
use App\Enums\ExpenseCategory;
use App\Filament\Resources\RecurringExpenses\Pages\CreateRecurringExpense;
use App\Filament\Resources\RecurringExpenses\Pages\EditRecurringExpense;
use App\Filament\Resources\RecurringExpenses\Pages\ListRecurringExpenses;
use App\Models\RecurringExpense;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00'));
    $this->actingAs(User::factory()->create());
});

it('lists the expenses with their rhythm, next due date and baseline flag', function () {
    $vat = RecurringExpense::factory()->vat(60_000)->create(['starts_on' => '2026-01-01']);
    $insurance = RecurringExpense::factory()->inBaseline()->create(['name' => '勞健保', 'amount' => 30_000, 'due_day' => 31, 'category' => ExpenseCategory::Insurance, 'starts_on' => '2026-01-01']);

    $this->get('/admin/recurring-expenses')->assertOk()->assertSee('固定支出');

    Livewire::test(ListRecurringExpenses::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$vat, $insurance])
        ->assertSee(['營業稅', '雙月（1、3、5、7、9、11 月）15 日', 'NT$60,000', '2026-11-15'])
        ->assertSee(['勞健保', '每月 月底', 'NT$30,000', '2026-10-31'])
        ->assertSee('支出日曆');
});

it('creates an expense, storing the due months only for non-monthly cadences', function () {
    Livewire::test(CreateRecurringExpense::class)
        ->fillForm([
            'name' => '營所稅',
            'amount' => 120000,
            'category' => ExpenseCategory::Tax,
            'cadence' => ExpenseCadence::Yearly,
            'due_months' => ['5'],
            'due_day' => 31,
            'starts_on' => '2026-01-01',
            'in_baseline' => false,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(RecurringExpense::sole())
        ->name->toBe('營所稅')
        ->amount->toBe(120_000)
        ->cadence->toBe(ExpenseCadence::Yearly)
        ->due_months->toBe([5])
        ->due_day->toBe(31)
        ->category->toBe(ExpenseCategory::Tax)
        ->in_baseline->toBeFalse()
        ->nextDueOn()->toDateString()->toBe('2027-05-31');

    Livewire::test(CreateRecurringExpense::class)
        ->fillForm(['name' => '租金', 'amount' => 25000, 'cadence' => ExpenseCadence::Monthly, 'due_months' => ['1', '2'], 'due_day' => 5, 'starts_on' => '2026-01-01'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(RecurringExpense::query()->where('name', '租金')->sole()->due_months)->toBeNull();
});

it('requires the due months for a non-monthly cadence', function () {
    Livewire::test(CreateRecurringExpense::class)
        ->fillForm(['name' => '營業稅', 'amount' => 60000, 'cadence' => ExpenseCadence::Bimonthly, 'due_months' => [], 'due_day' => 15, 'starts_on' => '2026-01-01'])
        ->call('create')
        ->assertHasFormErrors(['due_months' => 'required']);
});

it('edits an expense', function () {
    $expense = RecurringExpense::factory()->vat()->create(['starts_on' => '2026-01-01']);

    Livewire::test(EditRecurringExpense::class, ['record' => $expense->getRouteKey()])
        ->assertSchemaStateSet(['name' => '營業稅', 'due_months' => [1, 3, 5, 7, 9, 11]])
        ->fillForm(['amount' => 70000, 'in_baseline' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($expense->refresh())->amount->toBe(70_000)->in_baseline->toBeTrue();
});
