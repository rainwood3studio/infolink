<?php

use App\Domain\Alerts\Firing;
use App\Domain\Alerts\Rules\ExpenseDueRule;
use App\Enums\InsightKind;
use App\Models\AlertRule;
use App\Models\BankTransaction;
use App\Models\RecurringExpense;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->now = CarbonImmutable::parse('2026-11-10 09:00');
    $this->rule = AlertRule::factory()->make([
        'key' => 'expense-due',
        'query_class' => ExpenseDueRule::class,
        'metric_key' => null,
        'title_template' => '{name} {amount} 萬將於 {date} 到期',
        'fingerprint_template' => 'expense-due:{id}:{due_on}',
        'params' => null,
    ]);
    $this->evaluate = fn (?array $params = null): array => app(ExpenseDueRule::class)->evaluate(
        $params === null ? $this->rule : $this->rule->fill(['params' => $params]),
        $this->now,
    );
});

test('fires once per occurrence due within the window, with the balance after paying it', function () {
    BankTransaction::factory()->create(['txn_date' => '2026-11-09', 'balance' => 800_000, 'withdrawal' => 0, 'deposit' => 0]);
    $vat = RecurringExpense::factory()->vat(60_000)->create(['starts_on' => '2026-01-01']);
    RecurringExpense::factory()->create(['name' => '下個月', 'amount' => 10_000, 'due_day' => 18, 'starts_on' => '2026-01-01']);
    RecurringExpense::factory()->create(['name' => '已過', 'amount' => 10_000, 'due_day' => 9, 'starts_on' => '2026-01-01']);
    RecurringExpense::factory()->create(['name' => '停用', 'amount' => 10_000, 'due_day' => 12, 'starts_on' => '2026-01-01', 'is_active' => false]);
    $today = RecurringExpense::factory()->create(['name' => '今天', 'amount' => 5_000, 'due_day' => 10, 'starts_on' => '2026-01-01']);

    $firings = ($this->evaluate)();

    expect($firings)->toHaveCount(2)
        ->and($firings[0])->toBeInstanceOf(Firing::class)
        ->and($firings[0]->vars)->toBe(['id' => $today->id, 'name' => '今天', 'amount' => '0.50', 'amount_ntd' => '5,000', 'date' => '11/10', 'due_on' => '2026-11-10', 'days' => 0])
        ->and($firings[1]->vars)->toBe(['id' => $vat->id, 'name' => '營業稅', 'amount' => '6.00', 'amount_ntd' => '60,000', 'date' => '11/15', 'due_on' => '2026-11-15', 'days' => 5])
        ->and($firings[1]->kind)->toBe(InsightKind::Reminder)
        ->and($firings[1]->body)->toContain('- 固定支出：營業稅（稅務，雙月）', '- 金額：NT$60,000', '- 到期日：2026-11-15（5 天後）', '付完剩 **NT$740,000**')
        ->and($firings[1]->evidence)->toMatchArray(['recurring_expense_id' => $vat->id, 'due_on' => '2026-11-15', 'amount' => 60_000, 'balance' => 800_000, 'balance_after' => 740_000, 'in_baseline' => false]);
});

test('expenses inside the baseline fire only from min_amount up', function () {
    RecurringExpense::factory()->inBaseline()->create(['name' => '勞健保', 'amount' => 30_000, 'due_day' => 15, 'starts_on' => '2026-01-01']);
    RecurringExpense::factory()->inBaseline()->create(['name' => '薪資', 'amount' => 160_000, 'due_day' => 15, 'starts_on' => '2026-01-01']);

    expect(array_map(fn (Firing $firing): string => $firing->vars['name'], ($this->evaluate)()))->toBe(['薪資'])
        ->and(array_map(fn (Firing $firing): string => $firing->vars['name'], ($this->evaluate)(['min_amount' => 20_000])))->toBe(['勞健保', '薪資'])
        ->and(($this->evaluate)(['min_amount' => 200_000]))->toBe([]);
});

test('the window is configurable and the body copes without bank data', function () {
    RecurringExpense::factory()->create(['name' => '租金', 'amount' => 20_000, 'due_day' => 25, 'starts_on' => '2026-01-01']);

    expect(($this->evaluate)())->toBe([])
        ->and(($this->evaluate)(['window_days' => 15]))->toHaveCount(1)
        ->and(($this->evaluate)(['window_days' => 15])[0]->body)->toContain('- 目前餘額：尚無銀行明細');
});

test('owns the expense-due prefix', function () {
    expect(app(ExpenseDueRule::class)->ownedFingerprints($this->rule))->toBe(['expense-due:*'])
        ->and(ExpenseDueRule::label())->toBe('固定支出即將到期');
});
