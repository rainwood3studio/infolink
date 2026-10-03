<?php

namespace App\Mcp\Tools;

use App\Domain\Insights\InsightService;
use App\Domain\Work\ActionItemService;
use App\Enums\ActionItemPriority;
use App\Mcp\Tools\Concerns\WriteToolHelpers;
use App\Models\ActionItem;
use App\Models\Insight;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_action_item')]
#[Description('Create an action item (something someone must DO). Link it to the insight it comes from with insight_id or insight_fingerprint — then title defaults to the insight title and priority follows severity (critical → p1, warning → p2, info → p3). Idempotent on external_key; when omitted, the key is derived from the title + linked insight, so re-running the same analysis updates the item instead of duplicating it (its status is never reset). Check list_action_items first.')]
class CreateActionItem extends WriteTool
{
    use WriteToolHelpers;

    public function handle(Request $request, ActionItemService $actionItems, InsightService $insights): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255', 'required_without_all:insight_id,insight_fingerprint'],
            'detail' => ['nullable', 'string'],
            'priority' => ['nullable', Rule::in(self::enumValues(ActionItemPriority::class))],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'owner' => ['nullable', 'string', 'max:255'],
            'external_key' => ['nullable', 'string', 'max:255'],
            'insight_id' => ['nullable', 'integer'],
            'insight_fingerprint' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'vault_ref' => ['nullable', 'string', 'max:255'],
        ], [
            'title.required_without_all' => 'Pass a `title` (an imperative, e.g. 催收長照期中款), or link an insight to inherit its title.',
            'priority.in' => 'priority must be p1, p2 or p3.',
            'due_on.date_format' => 'due_on must be YYYY-MM-DD.',
        ]);

        $insight = $this->findInsight($validated);

        if (is_string($insight)) {
            return Response::error($insight);
        }

        $attributes = array_intersect_key($validated, array_flip(['title', 'detail', 'priority', 'due_on', 'owner', 'notes', 'vault_ref']));
        $title = $attributes['title'] ?? $insight?->title;
        $attributes['external_key'] = filled($validated['external_key'] ?? null)
            ? $validated['external_key']
            : 'auto:'.substr(sha1(mb_strtolower(trim((string) $title)).'|'.($insight?->id ?? '')), 0, 20);

        try {
            $actionItem = $insight !== null
                ? $insights->createActionItem($insight, $attributes, self::SOURCE)
                : $actionItems->create($attributes, self::SOURCE);
        } catch (InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::json([
            'result' => $actionItem->wasRecentlyCreated ? 'created' : 'updated',
            'action_item' => self::presentActionItem($actionItem->refresh()),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function findInsight(array $validated): Insight|string|null
    {
        if (isset($validated['insight_id'])) {
            return Insight::query()->find($validated['insight_id']) ?? "No insight with id {$validated['insight_id']}.";
        }

        if (filled($validated['insight_fingerprint'] ?? null)) {
            return Insight::query()->where('fingerprint', $validated['insight_fingerprint'])->orderByRaw('resolved_at is not null')->latest('id')->first()
                ?? "No insight with fingerprint [{$validated['insight_fingerprint']}]; raise_insight first.";
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentActionItem(ActionItem $actionItem): array
    {
        return [
            'id' => $actionItem->id,
            'external_key' => $actionItem->external_key,
            'title' => $actionItem->title,
            'detail' => $actionItem->detail,
            'priority' => $actionItem->priority?->value,
            'status' => $actionItem->status?->value,
            'due_on' => $actionItem->due_on?->toDateString(),
            'owner' => $actionItem->owner,
            'is_mine' => $actionItem->isMine(),
            'related_type' => $actionItem->related_type,
            'related_id' => $actionItem->related_id,
            'completed_at' => $actionItem->completed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Imperative one-liner, e.g. 催收長照期中款. Optional when an insight is linked.'),
            'detail' => $schema->string()->description('Markdown: context and definition of done.'),
            'priority' => $schema->string()->enum(self::enumValues(ActionItemPriority::class))->description('Default p2, or from the linked insight severity.'),
            'due_on' => $schema->string()->format('date')->description('YYYY-MM-DD.'),
            'owner' => $schema->string()->description('Who does it: omit for Kenneth, or a colleague name exactly as in dev_activity_summary → developers (the item then shows under 已交辦).'),
            'external_key' => $schema->string()->description('Idempotency key; derived from title + insight when omitted.'),
            'insight_id' => $schema->integer()->description('Insight this item comes from.'),
            'insight_fingerprint' => $schema->string()->description('Alternative to insight_id.'),
            'notes' => $schema->string(),
            'vault_ref' => $schema->string()->description('Related vault note path.'),
        ];
    }
}
