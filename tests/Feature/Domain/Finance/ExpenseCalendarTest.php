<?php

use App\Domain\Finance\ExpenseCalendar;
use App\Models\CostBaseline;
use App\Models\PlannedCashFlow;
use App\Models\RecurringExpense;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->today = CarbonImmutable::parse('2026-10-17');
});

/**
 * Today is 2026-10-17, so the 90-day lookahead ends 2027-01-15 and takes in both the November and the January VAT.
 */
it('lists every recurring occurrence and planned outflow by month with the extra not covered by the baseline', function () {
    CostBaseline::factory()->create(['effective_from' => '2026-01-01', 'monthly_cost' => 229_666]);
    $vat = RecurringExpense::factory()->vat(60_000)->create(['starts_on' => '2026-01-01']);
    $insurance = RecurringExpense::factory()->inBaseline()->create(['name' => '勞健保', 'amount' => 30_000, 'due_day' => 31, 'category' => 'insurance', 'starts_on' => '2026-01-01']);
    RecurringExpense::factory()->create(['name' => '今天到期', 'amount' => 1_000, 'due_day' => 17, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
    RecurringExpense::factory()->create(['name' => '昨天到期', 'amount' => 99_999, 'due_day' => 16, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
    $bonus = PlannedCashFlow::factory()->create(['flow_on' => '2027-01-20', 'amount' => -200_000, 'description' => '年終獎金']);
    PlannedCashFlow::factory()->create(['flow_on' => '2026-12-20', 'amount' => 5_000, 'description' => '退款']);
    PlannedCashFlow::factory()->create(['flow_on' => '2027-04-01', 'amount' => -1, 'description' => '超出範圍']);

    $calendar = app(ExpenseCalendar::class)->calculate($this->today);

    expect($calendar['summary'])->toBe([
        'today' => '2026-10-17',
        'months' => 6,
        'horizon_end' => '2027-03',
        'monthly_cost' => 229_666,
        'has_baseline' => true,
        'extra_90d' => 121_000,
        'extra_total' => 381_000,
        'in_baseline_total' => 180_000,
        'recurring_expenses' => 4,
        'items' => 11,
        'next_due' => ['date' => '2026-10-17', 'name' => '今天到期', 'amount' => 1_000, 'in_baseline' => false],
    ])
        ->and(array_column($calendar['months'], 'month'))->toBe(['2026-10', '2026-11', '2026-12', '2027-01', '2027-02', '2027-03'])
        ->and(array_column($calendar['months'], 'extra_outflow', 'month'))->toBe(['2026-10' => 1_000, '2026-11' => 60_000, '2026-12' => 0, '2027-01' => 260_000, '2027-02' => 0, '2027-03' => 60_000])
        ->and(array_column($calendar['months'], 'in_baseline_outflow', 'month'))->toBe(['2026-10' => 30_000, '2026-11' => 30_000, '2026-12' => 30_000, '2027-01' => 30_000, '2027-02' => 30_000, '2027-03' => 30_000])
        ->and($calendar['months'][3]['items'])->toBe([
            ['kind' => 'recurring', 'id' => $vat->id, 'date' => '2027-01-15', 'name' => '營業稅', 'amount' => 60_000, 'category' => 'tax', 'category_label' => '稅務', 'in_baseline' => false],
            ['kind' => 'planned', 'id' => $bonus->id, 'date' => '2027-01-20', 'name' => '年終獎金', 'amount' => 200_000, 'category' => null, 'category_label' => '已排定', 'in_baseline' => false],
            ['kind' => 'recurring', 'id' => $insurance->id, 'date' => '2027-01-31', 'name' => '勞健保', 'amount' => 30_000, 'category' => 'insurance', 'category_label' => '勞健保', 'in_baseline' => true],
        ])
        ->and($calendar['months'][4]['items'][0]['date'])->toBe('2027-02-28');
});

it('is empty without expenses or a baseline', function () {
    $calendar = app(ExpenseCalendar::class)->calculate($this->today, 2);

    expect($calendar['summary'])->toMatchArray(['monthly_cost' => 0, 'has_baseline' => false, 'extra_90d' => 0, 'recurring_expenses' => 0, 'items' => 0, 'next_due' => null])
        ->and($calendar['months'])->toHaveCount(2)
        ->and($calendar['months'][0]['items'])->toBe([]);
});

it('rejects a horizon outside the supported range', function (int $months) {
    app(ExpenseCalendar::class)->calculate($this->today, $months);
})->with([0, 25])->throws(InvalidArgumentException::class);
