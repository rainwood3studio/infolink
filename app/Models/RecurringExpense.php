<?php

namespace App\Models;

use App\Enums\ExpenseCadence;
use App\Enums\ExpenseCategory;
use App\Models\Concerns\HasSource;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\RecurringExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A fixed expense that recurs on its own calendar: 營業稅 filed by the 15th of odd months, 勞健保 at month end,
 * 營所稅 every May, bonuses, insurance premiums, rent.
 *
 * Rules:
 * - `amount` is a positive whole-NTD outflow.
 * - An occurrence falls on `due_day` of every due month; a day the month does not have (31 in April, 29–31 in
 *   February) is clamped to the month's last day.
 * - Due months: every month for `monthly`; otherwise the `due_months` list (month numbers 1–12), falling back to the
 *   cadence's default rhythm counted from `starts_on` when the list is empty.
 * - Occurrences exist from `starts_on` through `ends_on` (inclusive; null = open-ended) and only while `is_active`.
 * - `in_baseline` = the amount is already part of the cost baseline (e.g. 勞健保 inside 月成本). Such expenses are
 *   listed on the 支出日曆 but the cash forecast does not deduct them a second time.
 */
#[Fillable(['name', 'amount', 'cadence', 'due_day', 'due_months', 'category', 'in_baseline', 'starts_on', 'ends_on', 'is_active'])]
class RecurringExpense extends Model
{
    /** @use HasFactory<RecurringExpenseFactory> */
    use HasFactory, HasSource;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'cadence' => ExpenseCadence::class,
            'due_day' => 'integer',
            'due_months' => 'array',
            'category' => ExpenseCategory::class,
            'in_baseline' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<RecurringExpense>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The month numbers (1–12) this expense falls due in.
     *
     * @return list<int>
     */
    public function dueMonths(): array
    {
        if ($this->cadence === ExpenseCadence::Monthly) {
            return range(1, 12);
        }

        $months = collect($this->due_months ?? [])
            ->map(fn (mixed $month): int => (int) $month)
            ->filter(fn (int $month): bool => $month >= 1 && $month <= 12)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $months === [] ? $this->cadence->defaultDueMonths($this->starts_on?->month ?? 1) : $months;
    }

    /**
     * The due date in the given month: `due_day`, clamped to the month's last day.
     */
    public function dueOnIn(CarbonInterface $month): CarbonImmutable
    {
        $month = CarbonImmutable::instance($month)->startOfMonth();

        return $month->day(min($this->due_day, $month->daysInMonth))->startOfDay();
    }

    /**
     * Every occurrence with `$from ≤ due_on ≤ $to` (both inclusive, day precision), earliest first. Empty when the
     * expense is inactive or the window misses its starts_on / ends_on span.
     *
     * @return list<array{due_on: CarbonImmutable, amount: int}>
     */
    public function occurrencesBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        if (! $this->is_active) {
            return [];
        }

        $from = CarbonImmutable::instance($from)->startOfDay();
        $to = CarbonImmutable::instance($to)->startOfDay();

        if ($this->starts_on !== null) {
            $from = max($from, CarbonImmutable::instance($this->starts_on)->startOfDay());
        }

        if ($this->ends_on !== null) {
            $to = min($to, CarbonImmutable::instance($this->ends_on)->startOfDay());
        }

        if ($from->gt($to)) {
            return [];
        }

        $dueMonths = $this->dueMonths();
        $occurrences = [];

        for ($month = $from->startOfMonth(); $month->lte($to); $month = $month->addMonthNoOverflow()) {
            if (! in_array($month->month, $dueMonths, true)) {
                continue;
            }

            $dueOn = $this->dueOnIn($month);

            if ($dueOn->between($from, $to)) {
                $occurrences[] = ['due_on' => $dueOn, 'amount' => $this->amount];
            }
        }

        return $occurrences;
    }

    /**
     * The first due date strictly after `$after` (default today), or null when the expense is inactive or has ended.
     */
    public function nextDueOn(?CarbonInterface $after = null): ?CarbonImmutable
    {
        $after = CarbonImmutable::instance($after ?? today())->startOfDay();
        $from = $after->addDay();
        $to = $from->addYear()->endOfMonth();

        if ($this->ends_on !== null && CarbonImmutable::instance($this->ends_on)->lt($from)) {
            return null;
        }

        return $this->occurrencesBetween($from, $to)[0]['due_on'] ?? null;
    }
}
