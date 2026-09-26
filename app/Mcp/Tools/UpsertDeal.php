<?php

namespace App\Mcp\Tools;

use App\Domain\Sales\DealService;
use App\Enums\DealStage;
use App\Mcp\Tools\Concerns\PresentsDeals;
use App\Mcp\Tools\Concerns\WriteToolHelpers;
use App\Models\Customer;
use App\Models\Deal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('upsert_deal')]
#[Description(<<<'TEXT'
Create or update a sales opportunity (業務機會). Matched, in order, by `id`, by `external_key`, or by party (customer, or prospect_name when not yet a customer) + title, so re-running never duplicates.
The party is `customer` (an existing customer short_name, e.g. 長照) OR `prospect_name` (free text, e.g. 多羅滿賞鯨) — one is required when creating.
amount_untaxed and recurring_monthly are UNTAXED whole NTD; probability is 0–100 (weighted amount = amount × probability).
Changing `stage` records a stage_change event (add `stage_note` / `stage_changed_on`). won forces probability 100, lost forces 0, and both set closed_at; reopening clears it (pass a new probability).
Always keep `next_action` + `next_action_on` current: open deals without a future next action are flagged (業務斷層). Use log_deal_event for meetings, proposals and notes.
TEXT)]
class UpsertDeal extends WriteTool
{
    use PresentsDeals, WriteToolHelpers;

    /**
     * Fields copied from the request onto the deal when present.
     */
    protected const array FIELDS = ['prospect_name', 'title', 'stage', 'amount_untaxed', 'probability', 'recurring_monthly', 'expected_close_on', 'next_action', 'next_action_on', 'notes', 'vault_ref'];

    public function handle(Request $request, DealService $deals): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'id' => ['nullable', 'integer'],
            'external_key' => ['nullable', 'string', 'max:255'],
            'customer' => ['nullable', 'string', 'max:255'],
            'prospect_name' => ['nullable', 'string', 'max:255'],
            'title' => ['required_without:id', 'nullable', 'string', 'max:255'],
            'stage' => ['nullable', Rule::enum(DealStage::class)],
            'stage_note' => ['nullable', 'string'],
            'stage_changed_on' => ['nullable', 'date_format:Y-m-d'],
            'amount_untaxed' => ['nullable', 'integer', 'min:0'],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'recurring_monthly' => ['nullable', 'integer', 'min:0'],
            'expected_close_on' => ['nullable', 'date_format:Y-m-d'],
            'next_action' => ['nullable', 'string', 'max:255'],
            'next_action_on' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string'],
            'vault_ref' => ['nullable', 'string', 'max:255'],
        ], [
            'title.required_without' => 'Pass `title` (e.g. 賞鯨訂位中樞), or `id` to update an existing deal.',
            'stage.enum' => 'stage must be one of: '.implode(', ', self::enumValues(DealStage::class)).'.',
            'amount_untaxed.integer' => 'amount_untaxed must be a whole NTD integer, UNTAXED.',
            'probability.max' => 'probability is a percentage 0–100 (e.g. 30), not a fraction.',
        ]);

        $attributes = array_intersect_key($validated, array_flip(self::FIELDS));

        if (filled($validated['customer'] ?? null)) {
            $customer = Customer::query()->where('short_name', $validated['customer'])->first()
                ?? Customer::query()->where('name', $validated['customer'])->first();

            if ($customer === null) {
                $known = Customer::query()->orderBy('short_name')->pluck('short_name');
                $close = self::closeMatches($validated['customer'], $known);

                return Response::error("Unknown customer [{$validated['customer']}]."
                    .($close !== [] ? ' Did you mean: '.implode(', ', $close).'?' : ' Customers (short_name): '.$known->implode(', ').'.')
                    .' If they are not a customer yet, pass `prospect_name` instead.');
            }

            $attributes['customer_id'] = $customer->id;
        }

        $existing = isset($validated['id'])
            ? Deal::query()->find($validated['id'])
            : $deals->findExisting($attributes, $validated['external_key'] ?? null);

        if (isset($validated['id']) && $existing === null) {
            return Response::error("No deal with id {$validated['id']}. Use list_deals to find it.");
        }

        if ($existing === null && ! isset($attributes['customer_id']) && blank($attributes['prospect_name'] ?? null)) {
            return Response::error('A new deal needs `customer` (existing customer short_name) or `prospect_name` (not yet a customer).');
        }

        $stageBefore = $existing?->stage;

        $deal = $deals->upsert(
            [...$attributes, ...($existing !== null ? ['id' => $existing->id] : [])],
            self::SOURCE,
            $validated['external_key'] ?? null,
            $validated['stage_note'] ?? null,
            isset($validated['stage_changed_on']) ? CarbonImmutable::parse($validated['stage_changed_on']) : null,
        );

        return Response::json([
            'result' => $deal->wasRecentlyCreated ? 'created' : 'updated',
            'stage_changed' => $stageBefore !== null && $stageBefore !== $deal->stage,
            'deal' => self::presentDeal($deal->refresh(), 3),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Update this deal (from list_deals).'),
            'external_key' => $schema->string()->description('Your idempotency key. Optional: without it the deal is matched by party + title.'),
            'customer' => $schema->string()->description('Existing customer short_name, e.g. 長照. Use prospect_name if not a customer yet.'),
            'prospect_name' => $schema->string()->description('Prospect (not yet a customer), e.g. 多羅滿賞鯨.'),
            'title' => $schema->string()->description('Deal title, e.g. 賞鯨訂位中樞. Required unless `id` is given.'),
            'stage' => $schema->string()->enum(self::enumValues(DealStage::class))->description('lead / proposal / negotiation / won / lost. Default lead when creating. A change is logged as a stage_change event.'),
            'stage_note' => $schema->string()->description('Why the stage changed; stored on the stage_change event.'),
            'stage_changed_on' => $schema->string()->format('date')->description('Date of the stage change (YYYY-MM-DD); default today. Also the closed date for won/lost.'),
            'amount_untaxed' => $schema->integer()->description('Deal amount, UNTAXED whole NTD.'),
            'probability' => $schema->integer()->description('Win probability 0–100.'),
            'recurring_monthly' => $schema->integer()->description('Monthly fee after winning (SaaS / 維運), UNTAXED NTD.'),
            'expected_close_on' => $schema->string()->format('date'),
            'next_action' => $schema->string()->description('The next concrete step, e.g. 寄送修正版提案.'),
            'next_action_on' => $schema->string()->format('date')->description('When the next step is due (YYYY-MM-DD).'),
            'notes' => $schema->string(),
            'vault_ref' => $schema->string()->description('Vault note path, e.g. 03.Business/Projects/多羅滿賞鯨/賞鯨訂位中樞 提案書 v3.5.md'),
        ];
    }
}
