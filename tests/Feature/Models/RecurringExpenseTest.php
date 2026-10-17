<?php

use App\Enums\ExpenseCadence;
use App\Models\RecurringExpense;
use Carbon\CarbonImmutable;

/**
 * @return list<string>
 */
function dueDates(RecurringExpense $expense, string $from, string $to): array
{
    return array_map(
        fn (array $occurrence): string => $occurrence['due_on']->toDateString(),
        $expense->occurrencesBetween(CarbonImmutable::parse($from), CarbonImmutable::parse($to)),
    );
}

it('falls due every month on its day, at the window edges inclusive', function () {
    $expense = RecurringExpense::factory()->create(['due_day' => 10, 'starts_on' => '2026-01-01', 'amount' => 20_000]);

    expect(dueDates($expense, '2026-10-10', '2027-01-10'))->toBe(['2026-10-10', '2026-11-10', '2026-12-10', '2027-01-10'])
        ->and($expense->occurrencesBetween(CarbonImmutable::parse('2026-10-11'), CarbonImmutable::parse('2026-11-09')))->toBe([]);
});

it('follows the due months of a bimonthly expense', function () {
    $vat = RecurringExpense::factory()->vat()->create(['starts_on' => '2026-01-01']);

    expect(dueDates($vat, '2026-10-01', '2027-03-31'))->toBe(['2026-11-15', '2027-01-15', '2027-03-15'])
        ->and($vat->occurrencesBetween(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2027-03-31'))[0]['amount'])->toBe(60_000);
});

it('falls due once a year', function () {
    $tax = RecurringExpense::factory()->yearly(5, 31)->create(['starts_on' => '2026-01-01']);

    expect(dueDates($tax, '2026-06-01', '2028-06-01'))->toBe(['2027-05-31', '2028-05-31']);
});

it('clamps the due day to the last day of a shorter month', function () {
    $expense = RecurringExpense::factory()->create(['due_day' => 31, 'starts_on' => '2027-01-01']);

    expect(dueDates($expense, '2027-01-01', '2027-04-30'))->toBe(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30'])
        ->and(dueDates($expense, '2028-02-01', '2028-02-29'))->toBe(['2028-02-29']);
});

it('starts at starts_on and stops at ends_on', function () {
    $expense = RecurringExpense::factory()->create(['due_day' => 5, 'starts_on' => '2026-11-05', 'ends_on' => '2027-01-05']);

    expect(dueDates($expense, '2026-01-01', '2027-12-31'))->toBe(['2026-11-05', '2026-12-05', '2027-01-05']);

    $expense->update(['starts_on' => '2026-11-06']);

    expect(dueDates($expense, '2026-01-01', '2027-12-31'))->toBe(['2026-12-05', '2027-01-05']);
});

it('has no occurrences when inactive', function () {
    $expense = RecurringExpense::factory()->create(['is_active' => false, 'starts_on' => '2026-01-01']);

    expect($expense->occurrencesBetween(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31')))->toBe([])
        ->and($expense->nextDueOn(CarbonImmutable::parse('2026-10-17')))->toBeNull();
});

it('uses the cadence rhythm counted from starts_on when no due months are given', function () {
    $quarterly = RecurringExpense::factory()->create(['cadence' => ExpenseCadence::Quarterly, 'due_months' => null, 'due_day' => 1, 'starts_on' => '2026-02-01']);

    expect($quarterly->dueMonths())->toBe([2, 5, 8, 11])
        ->and(dueDates($quarterly, '2026-01-01', '2026-12-31'))->toBe(['2026-02-01', '2026-05-01', '2026-08-01', '2026-11-01']);
});

it('finds the next due date strictly after a day', function () {
    $vat = RecurringExpense::factory()->vat()->create(['starts_on' => '2026-01-01']);
    $ended = RecurringExpense::factory()->create(['due_day' => 1, 'starts_on' => '2026-01-01', 'ends_on' => '2026-09-01']);

    expect($vat->nextDueOn(CarbonImmutable::parse('2026-10-17'))->toDateString())->toBe('2026-11-15')
        ->and($vat->nextDueOn(CarbonImmutable::parse('2026-11-15'))->toDateString())->toBe('2027-01-15')
        ->and($vat->nextDueOn(CarbonImmutable::parse('2026-11-14'))->toDateString())->toBe('2026-11-15')
        ->and($ended->nextDueOn(CarbonImmutable::parse('2026-10-17')))->toBeNull();
});
