<?php

namespace App\Domain\Finance;

use App\Models\CostBaseline;
use App\Models\PlannedCashFlow;
use App\Models\RecurringExpense;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Read model for the 支出日曆 page and the `expense_calendar` MCP tool: the dated outflows of the coming months, grouped
 * by month, so the owner sees what falls due when and how much of it the monthly cost baseline does NOT already cover.
 *
 * Rules:
 * - Covers today (inclusive) through the end of the `$months`-th month counted from today's month.
 * - Lists every occurrence of an active {@see RecurringExpense} ({@see RecurringExpense::occurrencesBetween()}) and
 *   every negative {@see PlannedCashFlow} (a one-off outflow) in the window, earliest first within a month.
 * - `in_baseline` items are already inside the cost baseline (e.g. 勞健保 within 月成本): they are shown so the date is
 *   not forgotten, but they are not extra money. A month's `extra_outflow` therefore sums only the items with
 *   `in_baseline` false — the recurring ones the forecast deducts on top of the baseline, and the planned one-offs.
 * - `summary.extra_90d` is the same extra outflow over the next {@see self::LOOKAHEAD_DAYS} days, the figure that goes
 *   next to the baseline in the page's headline. `summary.monthly_cost` is the baseline in force today.
 * - Amounts are whole NTD; outflows are positive here.
 */
class ExpenseCalendar
{
    public const int DEFAULT_MONTHS = 6;

    public const int MAX_MONTHS = 24;

    public const int LOOKAHEAD_DAYS = 90;

    public const string KIND_RECURRING = 'recurring';

    public const string KIND_PLANNED = 'planned';

    /**
     * @return array{
     *     summary: array{
     *         today: string,
     *         months: int,
     *         horizon_end: string,
     *         monthly_cost: int,
     *         has_baseline: bool,
     *         extra_90d: int,
     *         extra_total: int,
     *         in_baseline_total: int,
     *         recurring_expenses: int,
     *         items: int,
     *         next_due: ?array{date: string, name: string, amount: int, in_baseline: bool},
     *     },
     *     months: list<array{
     *         month: string,
     *         extra_outflow: int,
     *         in_baseline_outflow: int,
     *         items: list<array{kind: string, id: int, date: string, name: string, amount: int, category: ?string, category_label: string, in_baseline: bool}>,
     *     }>,
     * }
     */
    public function calculate(?CarbonImmutable $today = null, int $months = self::DEFAULT_MONTHS): array
    {
        if ($months < 1 || $months > self::MAX_MONTHS) {
            throw new InvalidArgumentException('The calendar covers 1 to '.self::MAX_MONTHS.' months.');
        }

        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $until = $today->startOfMonth()->addMonthsNoOverflow($months - 1)->endOfMonth()->startOfDay();
        $lookaheadEnd = $today->addDays(self::LOOKAHEAD_DAYS);

        $byMonth = [];

        for ($month = $today->startOfMonth(); $month->lte($until); $month = $month->addMonthNoOverflow()) {
            $byMonth[$month->format('Y-m')] = ['month' => $month->format('Y-m'), 'extra_outflow' => 0, 'in_baseline_outflow' => 0, 'items' => []];
        }

        $expenses = RecurringExpense::query()
            ->active()
            ->whereDate('starts_on', '<=', $until)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->orderBy('id')
            ->get();

        foreach ($expenses as $expense) {
            foreach ($expense->occurrencesBetween($today, $until) as $occurrence) {
                $byMonth[$occurrence['due_on']->format('Y-m')]['items'][] = [
                    'kind' => self::KIND_RECURRING,
                    'id' => $expense->id,
                    'date' => $occurrence['due_on']->toDateString(),
                    'name' => $expense->name,
                    'amount' => $occurrence['amount'],
                    'category' => $expense->category->value,
                    'category_label' => $expense->category->getLabel(),
                    'in_baseline' => $expense->in_baseline,
                ];
            }
        }

        $flows = PlannedCashFlow::query()
            ->where('amount', '<', 0)
            ->whereDate('flow_on', '>=', $today)
            ->whereDate('flow_on', '<=', $until)
            ->orderBy('flow_on')
            ->orderBy('id')
            ->get();

        foreach ($flows as $flow) {
            $byMonth[$flow->flow_on->format('Y-m')]['items'][] = [
                'kind' => self::KIND_PLANNED,
                'id' => $flow->id,
                'date' => $flow->flow_on->toDateString(),
                'name' => $flow->description,
                'amount' => -$flow->amount,
                'category' => null,
                'category_label' => '已排定',
                'in_baseline' => false,
            ];
        }

        $extra90 = 0;
        $extraTotal = 0;
        $inBaselineTotal = 0;
        $count = 0;
        $nextDue = null;

        foreach ($byMonth as &$month) {
            usort($month['items'], fn (array $a, array $b): int => [$a['date'], $a['kind'], $a['id']] <=> [$b['date'], $b['kind'], $b['id']]);

            foreach ($month['items'] as $item) {
                $count++;
                $nextDue ??= ['date' => $item['date'], 'name' => $item['name'], 'amount' => $item['amount'], 'in_baseline' => $item['in_baseline']];

                if ($item['in_baseline']) {
                    $month['in_baseline_outflow'] += $item['amount'];
                    $inBaselineTotal += $item['amount'];

                    continue;
                }

                $month['extra_outflow'] += $item['amount'];
                $extraTotal += $item['amount'];

                if ($item['date'] <= $lookaheadEnd->toDateString()) {
                    $extra90 += $item['amount'];
                }
            }
        }

        unset($month);

        $baseline = CostBaseline::query()->whereDate('effective_from', '<=', $today)->latest('effective_from')->first();

        return [
            'summary' => [
                'today' => $today->toDateString(),
                'months' => $months,
                'horizon_end' => $until->format('Y-m'),
                'monthly_cost' => (int) ($baseline?->monthly_cost ?? 0),
                'has_baseline' => $baseline !== null,
                'extra_90d' => $extra90,
                'extra_total' => $extraTotal,
                'in_baseline_total' => $inBaselineTotal,
                'recurring_expenses' => $expenses->count(),
                'items' => $count,
                'next_due' => $nextDue,
            ],
            'months' => array_values($byMonth),
        ];
    }
}
