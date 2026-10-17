<?php

namespace App\Mcp\Tools;

use App\Domain\Finance\ExpenseCalendar;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('expense_calendar')]
#[Description(<<<'TEXT'
The fixed-expense calendar (the 支出日曆 page): the dated outflows of the coming months, grouped by month — every occurrence of a recurring fixed expense (營業稅 by the 15th of odd months, 勞健保 at month end, 營所稅 in May, bonuses, rent, …) plus every planned one-off outflow. Amounts are whole NTD, outflows positive. Default 6 months from today.
- `months[].items`: `kind` (`recurring` = a recurring_expenses row, `planned` = a planned_cash_flows row), `date`, `name`, `amount`, `category` (tax / insurance / payroll / rent / subscription / other; null for planned), `in_baseline`.
- `in_baseline` = true means the amount is ALREADY inside the monthly cost baseline (e.g. 勞健保 within 月成本): it is listed so the date is not missed, but it is not extra money and the cash forecast does not deduct it again. Everything with `in_baseline` false IS extra on top of the baseline and is what `get_cash_position` / `revenue_outlook` deduct (recurring ones as `recurring_expenses` / `fixed_outflow`, planned ones as `planned_cash_flows` / `planned_outflow`).
- `months[].extra_outflow` sums the extra items of the month; `in_baseline_outflow` the covered ones.
- `summary`: `monthly_cost` (the baseline in force today, 0 with `has_baseline` false when none), `extra_90d` (extra outflow due in the next 90 days — quote it next to the baseline: 「月成本 X，接下來 90 天另有 Y 固定支出」), `extra_total` / `in_baseline_total` over the whole window, `recurring_expenses` (active rows; 0 means the owner has not set any up yet — suggest 營業稅, 勞健保, 營所稅), `next_due`.
TEXT)]
class ExpenseCalendarSummary extends ReadTool
{
    public function handle(Request $request, ExpenseCalendar $calendar): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'months' => ['nullable', 'integer', 'min:1', 'max:'.ExpenseCalendar::MAX_MONTHS],
        ]);

        try {
            $result = $calendar->calculate(months: (int) ($validated['months'] ?? ExpenseCalendar::DEFAULT_MONTHS));
        } catch (InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::json([
            'generated_at' => now()->toIso8601String(),
            ...$result,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'months' => $schema->integer()
                ->min(1)
                ->max(ExpenseCalendar::MAX_MONTHS)
                ->description('How many months to list, starting with the current one. Default '.ExpenseCalendar::DEFAULT_MONTHS.'.'),
        ];
    }
}
