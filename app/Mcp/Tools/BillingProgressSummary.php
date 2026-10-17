<?php

namespace App\Mcp\Tools;

use App\Domain\Finance\BillingProgress;
use App\Domain\Finance\DepositMatcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('billing_progress')]
#[Description(<<<'TEXT'
Invoicing and collection progress: how far each project's contract has become receivables, invoices and money in the bank, which invoices should already have gone out, which maintenance periods have no receivable row, and which bank deposits are still waiting to be matched to a receivable. Amounts are whole NTD, UNTAXED unless a key says `taxed`.
- `summary`: `contract_total`, `scheduled` (all non-recurring, non-cancelled receivables), `received`, `outstanding` (= `invoiced_outstanding` + `planned`), `unscheduled` (contract − scheduled over projects WITH a contract amount), `overdue` (outstanding past expected_on: amount + count), `due_uninvoiced` (planned, no invoice, expected within 7 days: amount + count), `over_scheduled` / `missing_contract` (project names), `recurring_missing` / `recurring_planned` (maintenance periods without a row / without an invoice).
- `projects` (closing first, then those with problems, then by contract amount): per project the same sums, `unscheduled` (null without a contract amount, negative = receivables exceed the contract → `is_over_scheduled`), `has_problems`, and `receivables` (id, item, untaxed, taxed, expected_on, status, invoiced_on, `invoice_number` — null means no 統一發票 was recorded even if invoiced_on is set — received_on, confidence, is_overdue, days_overdue, is_due_uninvoiced). Recurring receivables are NOT in `projects`.
- `without_project`: non-recurring receivables tied to no project, grouped by customer.
- `recurring`: per customer with a maintenance contract, the two previous, current and two next billing periods with `status` received / invoiced / planned (row exists, no invoice) / missing (no receivable row — create one with upsert_receivable, is_recurring=true, expected_on inside the period) and the matching receivable.
- `unsettled_deposits`: revenue bank deposits of the last 18 months with no `receivable_id`. For each, `customer` (from the counterparty), `suggestions` ranked best first with `kind` exact_taxed / exact_untaxed / combination (2–3 receivables' taxed amounts add up) / near (within 1% or NT$100), `receivable_ids`, `amount_taxed` and `difference` (deposit − amount), and `best` (the top suggestion when exact or combination, else null). `unmatched_counterparties` are deposits whose counterparty matches no customer alias. To settle one, call record_receivable_payment with the receivable `id`, `transaction_id` = the deposit's `transaction.id` and `received_on` = its date (one call per receivable for a combination; the first call links the deposit). Never settle a `near` match without saying so.
TEXT)]
class BillingProgressSummary extends ReadTool
{
    public function handle(Request $request, BillingProgress $billing, DepositMatcher $matcher): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        return Response::json([
            'generated_at' => now()->toIso8601String(),
            ...$billing->calculate(),
            'unsettled_deposits' => $matcher->suggest(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
