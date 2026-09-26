<?php

namespace App\Mcp\Tools;

use App\Domain\Finance\CashFlowAnalytics;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('analyze_cash_flow')]
#[Description(<<<'TEXT'
Cash-flow analysis over line-level bank transactions for a period (the same numbers as the 金流分析 page). Amounts are whole NTD.
Period: `from`/`to` as YYYY-MM (whole month) or YYYY-MM-DD, inclusive; omitted = from the first / through the latest transaction.
Sections (default: summary, monthly, outflow_by_category, revenue_by_customer):
- summary: totals in/out/net, revenue, average monthly inflow and regular outflow, one-off outflow, opening/closing/lowest balance, revenue concentration, unrepaid 代墊, top-5 single inflows and outflows.
- monthly: per month inflow, outflow, net, regular / one-off / reimbursement outflow (they add up to outflow), reimbursement inflow, month-end balance, lowest balance after any line (statement order) and its date, is_partial.
- outflow_by_category: withdrawals per month × category and period totals with share_pct.
- revenue_by_customer: revenue deposits per month × customer (counterparty, else the linked receivable's customer), totals with share_pct, top 5 + 其他, concentration (top1/top3 share_pct, HHI on the 0–10,000 scale; > 2,500 = highly concentrated).
- fixed_costs: non-one-off salary, insurance (勞健保＋勞退), tax, subscription, rent per month, with averages.
- daily_balance: end-of-day balance for every day (carried forward; days in months without lines omitted). Long — request only when needed.
- collection: receivables received in the period — days late vs expected_on (negative = early; on time = not after expected_on), on-time rate, by customer; plus receivables overdue today.
- runway: month-end balance ÷ average regular outflow of that month and up to 2 previous months with data.
- reimbursement: 代墊 advanced vs repaid per month, cumulative from the first transaction; `outstanding` = still owed back.
- vat: tax-category payments per month with lines (營業稅 is bi-monthly, so alternate months are often 0).
Caveats: cash basis (when money moved, not when earned/invoiced); revenue deposits are tax-inclusive as received; months without line-level data are omitted, never zero-filled (older history exists only as monthly summary metrics — see query_metrics cash.month_end_balance); balances are the bank-printed balance summed across accounts; shares are percentages (0–100).
TEXT)]
class AnalyzeCashFlow extends ReadTool
{
    public const array SECTIONS = [
        'summary', 'monthly', 'outflow_by_category', 'revenue_by_customer', 'fixed_costs', 'daily_balance',
        'collection', 'runway', 'reimbursement', 'vat',
    ];

    public const array DEFAULT_SECTIONS = ['summary', 'monthly', 'outflow_by_category', 'revenue_by_customer'];

    public function handle(Request $request): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $bound = ['nullable', 'string', 'regex:/^\d{4}-\d{2}(-\d{2})?$/'];
        $validated = $request->validate([
            'from' => $bound,
            'to' => $bound,
            'sections' => ['nullable', 'array'],
            'sections.*' => ['string', 'distinct', Rule::in(self::SECTIONS)],
        ]);

        try {
            $analytics = CashFlowAnalytics::between($validated['from'] ?? null, $validated['to'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }

        $sections = ($validated['sections'] ?? []) ?: self::DEFAULT_SECTIONS;

        $result = ['period' => $analytics->period(), 'has_data' => $analytics->hasData()];

        foreach ($sections as $section) {
            $result[$section] = match ($section) {
                'summary' => array_diff_key($analytics->summary(), ['period' => true]),
                'monthly' => $analytics->monthlyFlows(),
                'outflow_by_category' => $analytics->outflowByCategory(),
                'revenue_by_customer' => $analytics->revenueByCustomer(),
                'fixed_costs' => $analytics->fixedCostTrend(),
                'daily_balance' => $analytics->dailyBalance(),
                'collection' => $analytics->collectionPerformance(),
                'runway' => $analytics->runwayHistory(),
                'reimbursement' => $analytics->reimbursementBalance(),
                'vat' => $analytics->vatPayments(),
            };
        }

        return Response::json($result);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()
                ->description('Period start, YYYY-MM (month start) or YYYY-MM-DD. Default: the first transaction.'),
            'to' => $schema->string()
                ->description('Period end, YYYY-MM (month end) or YYYY-MM-DD. Default: the latest transaction.'),
            'sections' => $schema->array()
                ->items($schema->string()->enum(self::SECTIONS))
                ->description('Sections to return. Default: summary, monthly, outflow_by_category, revenue_by_customer.'),
        ];
    }
}
