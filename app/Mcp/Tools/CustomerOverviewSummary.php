<?php

namespace App\Mcp\Tools;

use App\Domain\Sales\CustomerOverview;
use App\Models\Customer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('customer_overview')]
#[Description(<<<'TEXT'
Everything about ONE customer on one screen (the 客戶總覽 page): maintenance contract, projects with their money, open receivables, bank deposits, open deals, open Redmine issues and a list of things to fix. Amounts are whole NTD, UNTAXED unless the key says `taxed`.
- `maintenance` (null = no contract): `fee_untaxed` / `fee_taxed` (5% VAT), `cycle` (monthly / yearly), `since`, `until` (null = open-ended), `days_until_end` (signed; negative = already ended), `is_ended`, `is_expiring_soon` (ends within 45 days), `annualised_fee_untaxed`.
- `projects`: every project (active, closing, then closed) with `contract_amount_untaxed` (null = not entered, NOT zero), `target_close_date`, `open_issues` (open issues of its linked Redmine project; null = not linked) and `receivables` = roll-up of its non-cancelled receivables: `scheduled_untaxed` (all), `received_untaxed`, `outstanding_untaxed` / `outstanding_taxed` (planned or invoiced), `overdue_untaxed` (outstanding and past `expected_on`).
- `receivables.items`: outstanding NON-recurring receivables, oldest expected first, with `is_overdue` and `days` (signed days until `expected_on`; negative = overdue). The totals (`outstanding_*`, `overdue_*`) include recurring fees too, so they match the customer list.
- `recurring`: the last 6 recurring (maintenance) receivables, newest first, with status and `received_on`.
- `deposits`: bank lines of category revenue with a deposit, matched to the customer by the linked receivable or by bank alias (customer name, short name, `counterparties`; exact or prefix, longest alias wins). `total_12m` / `count_12m`, `total_all_time`, `last_on` / `last_amount`, `by_month` (last 12 months, zero-filled, tax-inclusive as received), `matched_counterparties` (the names the bank printed).
- `deals`: open deals (lead / proposal / negotiation) with `amount_untaxed`, `probability`, `weighted_amount`, `next_action`, `next_action_on`.
- `open_issues`: open Redmine issues across the customer's `redmine_project_ids` and its projects' linked Redmine projects, de-duplicated; `redmine` lists them per Redmine project (`identifier` / `name` null when the mirror has never seen that project).
- `flags`: Chinese one-liners worth acting on — 合約金額未填：<project>, 維護合約 N 天後到期 / 已到期 N 天, 逾期應收 NT$…, 入帳對不到戶名 (money was marked received in the last 12 months but no bank deposit matched this customer: add the bank's name to the customer's counterparties).
Pass `customer` = short_name (preferred) or full name; the error lists the known short names.
TEXT)]
class CustomerOverviewSummary extends ReadTool
{
    public function handle(Request $request, CustomerOverview $overview): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'customer' => ['required', 'string'],
        ], [
            'customer.required' => 'Pass `customer`: the customer short_name (e.g. 長照) or full name.',
        ]);

        $customer = Customer::query()->where('short_name', $validated['customer'])->first()
            ?? Customer::query()->where('name', $validated['customer'])->first();

        if ($customer === null) {
            $known = Customer::query()->orderBy('short_name')->pluck('short_name');

            return Response::error("Unknown customer [{$validated['customer']}]. Customers (short_name): ".$known->implode(', ').'.');
        }

        return Response::json([
            'generated_at' => now()->toIso8601String(),
            ...$overview->calculate($customer),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'customer' => $schema->string()->description('Customer short_name, e.g. 長照 (full name also accepted). Must exist.')->required(),
        ];
    }
}
