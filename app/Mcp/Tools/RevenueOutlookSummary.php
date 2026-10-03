<?php

namespace App\Mcp\Tools;

use App\Domain\Finance\RevenueOutlook;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('revenue_outlook')]
#[Description(<<<'TEXT'
The 12-month revenue outlook (the 收入展望 page): month by month what comes in and goes out once the project payments are collected, when cash starts falling, when it reaches zero and how much new business closes the gap. Built on the same cash forecast as `get_cash_position` (same as_of, opening balance and method), extended to 12 months starting with the as_of month. Amounts are whole NTD.
- `months` (`columns` + `rows`, one row per month) — layers:
  - `receivables`: HIGH-confidence outstanding receivables that are not recurring, tax included, in their expected month (overdue ones in the as_of month). `delay_months` moves every one of them that many months later.
  - `recurring`: the recurring fee rows that are actually booked (one receivable per month), tax included. Never delayed.
  - `recurring_assumed`: an EXTRAPOLATION, not a booking. Recurring rows are only entered some months ahead, so every month after `assumptions.recurring_last_month` repeats that month's total — i.e. it assumes the maintenance contracts are renewed at the same fee. Always say so when quoting anything that depends on it.
  - `low_confidence`: low-confidence outstanding receivables, tax included. Always listed; counted in the base figures only with `include_low_confidence`.
  - `planned_inflow` / `planned_outflow`: planned cash flows (VAT, bonuses, …). `cost`: the monthly cost baseline (as_of month: only the part not yet paid, see `assumptions.as_of_month_cost`).
  - `pipeline_weighted`: open deals with both an amount and an expected close date inside the 12 months — amount × probability in the close month, plus recurring_monthly × probability in every later month. UNTAXED and weighted, and a deal closing is not cash arriving, so it is kept out of `net_base` and only feeds `balance_with_pipeline`.
  - `net_confirmed` = receivables + recurring + planned_inflow − cost − planned_outflow; `net_base` = net_confirmed + recurring_assumed (+ low_confidence when included).
  - Month-end balances: `balance_confirmed` (booked rows only; equals the cash forecast when `delay_months` is 0), `balance_base` (the headline scenario), `balance_with_pipeline` (base + cumulative pipeline).
- `summary`: `monthly_cost`, `recurring_monthly` (the run-rate used for the extrapolation), `monthly_gap` = monthly_cost − recurring_monthly (positive = burning that much every month once the project money is in), `annual_gap` = monthly_gap × 12 (new business needed per year to break even), `peak` (highest month-end base balance), `first_declining_month` (from this month on the base net is negative in every month to the end; null if not), `zero_month` (first month-end with base balance < 0; `extrapolated` = not reached inside the 12 months, continued in a straight line at monthly_gap for `months_of_runway_after_horizon` months — an estimate; null = not burning), `zero_month_confirmed` (the same without the renewal assumption), `pipeline_weighted_total`, `open_deals`, `counted_deals`.
- `deals`: every open deal with `missing` (any of 金額, 預計成交日, 下一步日期) and `counted`. A deal is NOT counted when it lacks an amount or an expected close date (`not_counted_reason` = missing_fields), when its close month is already past (close_date_passed) or after the 12 months (beyond_horizon). Name the uncounted deals and what they lack: the pipeline understates until they are filled in.
- `closing_receivables`: non-recurring outstanding receivables that hang on a project still in `closing`, with that project's projection from `closing_projects` (`projection`, `projected_close_date`, `days_late`) and `at_risk` (project past its target, not converging, projected late, or projected to close after the payment is expected). `month` is where the outlook counts it (null = pushed past the 12 months). These final payments only arrive once the project closes — use `delay_months` to see what a slip costs.
- `assumptions.delayed_beyond_horizon`: receivables the delay pushed past the 12 months (dropped from every balance).
TEXT)]
class RevenueOutlookSummary extends ReadTool
{
    public const array MONTH_COLUMNS = [
        'month', 'receivables', 'recurring', 'recurring_assumed', 'low_confidence', 'planned_inflow', 'cost', 'planned_outflow',
        'pipeline_weighted', 'net_confirmed', 'net_base', 'balance_confirmed', 'balance_base', 'balance_with_pipeline',
    ];

    public function handle(Request $request, RevenueOutlook $outlook): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'delay_months' => ['nullable', 'integer', 'min:0', 'max:'.RevenueOutlook::MAX_DELAY_MONTHS],
            'include_low_confidence' => ['nullable', 'boolean'],
        ]);

        $result = $outlook->calculate(
            delayMonths: (int) ($validated['delay_months'] ?? 0),
            includeLowConfidence: (bool) ($validated['include_low_confidence'] ?? false),
        );

        return Response::json([
            'generated_at' => now()->toIso8601String(),
            'options' => $result['options'],
            'summary' => $result['summary'],
            'assumptions' => $result['assumptions'],
            'months' => [
                'columns' => self::MONTH_COLUMNS,
                'rows' => array_map(
                    fn (array $month): array => array_map(fn (string $column): mixed => $month[$column], self::MONTH_COLUMNS),
                    $result['months'],
                ),
            ],
            'deals' => $result['deals'],
            'closing_receivables' => $result['closing_receivables'],
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'delay_months' => $schema->integer()
                ->min(0)
                ->max(RevenueOutlook::MAX_DELAY_MONTHS)
                ->description('Move every non-recurring high-confidence receivable this many months later (what if the final payments slip). Default 0.'),
            'include_low_confidence' => $schema->boolean()
                ->description('Count low-confidence receivables in net_base, balance_base and balance_with_pipeline. Default false.'),
        ];
    }
}
