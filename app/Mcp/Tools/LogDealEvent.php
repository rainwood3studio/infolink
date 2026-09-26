<?php

namespace App\Mcp\Tools;

use App\Domain\Sales\DealService;
use App\Enums\DealEventType;
use App\Mcp\Tools\Concerns\PresentsDeals;
use App\Models\Deal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('log_deal_event')]
#[Description(<<<'TEXT'
Add an entry to a deal's history: a meeting, a proposal sent, or a note. Identify the deal by `deal_id` (from list_deals) or by `deal_external_key`.
With `external_key` the call is idempotent (the same deal + key updates the entry instead of adding another).
Stage changes are NOT logged here: call upsert_deal with the new `stage` (it records the stage_change event).
Optionally pass `next_action` / `next_action_on` to update the deal's next step in the same call — do this whenever the interaction produced one.
TEXT)]
class LogDealEvent extends WriteTool
{
    use PresentsDeals;

    /**
     * Event types that can be logged directly (stage_change comes from upsert_deal).
     *
     * @return list<string>
     */
    protected static function loggableTypes(): array
    {
        return array_values(array_map(
            fn (DealEventType $type): string => $type->value,
            array_filter(DealEventType::cases(), fn (DealEventType $type): bool => $type !== DealEventType::StageChange),
        ));
    }

    public function handle(Request $request, DealService $deals): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'deal_id' => ['required_without:deal_external_key', 'nullable', 'integer'],
            'deal_external_key' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(self::loggableTypes())],
            'content' => ['required', 'string'],
            'occurred_on' => ['nullable', 'date_format:Y-m-d'],
            'external_key' => ['nullable', 'string', 'max:255'],
            'next_action' => ['nullable', 'string', 'max:255'],
            'next_action_on' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'deal_id.required_without' => 'Pass `deal_id` (from list_deals) or `deal_external_key`.',
            'type.in' => 'type must be one of: '.implode(', ', self::loggableTypes()).'. To change the stage, call upsert_deal with `stage`.',
        ]);

        $deal = isset($validated['deal_id'])
            ? Deal::query()->find($validated['deal_id'])
            : Deal::query()->where('external_key', $validated['deal_external_key'])->orderBy('id')->first();

        if ($deal === null) {
            return Response::error('No deal matches '.(isset($validated['deal_id']) ? "id {$validated['deal_id']}" : "external_key [{$validated['deal_external_key']}]").'. Use list_deals to find it, or upsert_deal to create it.');
        }

        $nextAction = array_intersect_key($validated, array_flip(['next_action', 'next_action_on']));

        $event = DB::transaction(function () use ($deals, $deal, $validated, $nextAction) {
            $event = $deals->logEvent(
                $deal,
                DealEventType::from($validated['type']),
                $validated['content'],
                isset($validated['occurred_on']) ? CarbonImmutable::parse($validated['occurred_on']) : null,
                self::SOURCE,
                $validated['external_key'] ?? null,
            );

            if ($nextAction !== []) {
                $deals->upsert([...$nextAction, 'id' => $deal->id], self::SOURCE);
            }

            return $event;
        });

        return Response::json([
            'result' => $event->wasRecentlyCreated ? 'created' : 'updated',
            'event' => self::presentDealEvent($event),
            'deal' => self::presentDeal($deal->refresh(), 3),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'deal_id' => $schema->integer()->description('The deal (id from list_deals).'),
            'deal_external_key' => $schema->string()->description('Alternatively, the deal\'s external_key.'),
            'type' => $schema->string()->enum(self::loggableTypes())->description('meeting / proposal_sent / note.')->required(),
            'content' => $schema->string()->description('What happened, in a sentence or two.')->required(),
            'occurred_on' => $schema->string()->format('date')->description('YYYY-MM-DD; default today.'),
            'external_key' => $schema->string()->description('Idempotency key for this entry, e.g. a vault note path + date.'),
            'next_action' => $schema->string()->description('Update the deal\'s next step.'),
            'next_action_on' => $schema->string()->format('date')->description('Update the deal\'s next step date (YYYY-MM-DD).'),
        ];
    }
}
