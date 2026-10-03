<?php

namespace App\Mcp\Tools;

use App\Domain\Work\ActionItemService;
use App\Enums\ActionItemPriority;
use App\Enums\ActionItemStatus;
use App\Mcp\Tools\Concerns\WriteToolHelpers;
use App\Models\ActionItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_action_item')]
#[Description('Update an action item by `id` (or `external_key`): change status (todo / doing / waiting / done / dropped), priority, due date, owner, title or detail. Only the fields you pass change; pass due_on null to clear it. Marking done records completed_at. Never delete — use status dropped. Idempotent.')]
class UpdateActionItem extends WriteTool
{
    use WriteToolHelpers;

    public function handle(Request $request, ActionItemService $actionItems): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'required_without:external_key'],
            'external_key' => ['nullable', 'string', 'required_without:id'],
            'title' => ['sometimes', 'string', 'max:255'],
            'detail' => ['sometimes', 'nullable', 'string'],
            'priority' => ['sometimes', Rule::in(self::enumValues(ActionItemPriority::class))],
            'status' => ['sometimes', Rule::in(self::enumValues(ActionItemStatus::class))],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'owner' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ], [
            'id.required_without' => 'Identify the action item with `id` or `external_key` (see list_action_items).',
            'external_key.required_without' => 'Identify the action item with `id` or `external_key` (see list_action_items).',
            'status.in' => 'status must be one of: '.implode(', ', self::enumValues(ActionItemStatus::class)).'.',
            'priority.in' => 'priority must be p1, p2 or p3.',
        ]);

        $actionItem = $this->findActionItem($validated);

        if (is_string($actionItem)) {
            return Response::error($actionItem);
        }

        $changes = array_intersect_key($validated, array_flip(['title', 'detail', 'priority', 'status', 'due_on', 'owner', 'notes']));

        if ($changes === []) {
            return Response::error('Nothing to update; pass at least one of title, detail, priority, status, due_on, owner, notes.');
        }

        $actionItems->update($actionItem, $changes);

        return Response::json([
            'result' => 'updated',
            'action_item' => CreateActionItem::presentActionItem($actionItem->refresh()),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function findActionItem(array $validated): ActionItem|string
    {
        if (isset($validated['id'])) {
            return ActionItem::query()->find($validated['id']) ?? "No action item with id {$validated['id']}.";
        }

        $matches = ActionItem::query()->where('external_key', $validated['external_key'])->get();

        return match ($matches->count()) {
            1 => $matches->first(),
            0 => "No action item with external_key [{$validated['external_key']}].",
            default => "external_key [{$validated['external_key']}] matches several action items (ids ".$matches->pluck('id')->implode(', ').'); pass `id`.',
        };
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Action item id.'),
            'external_key' => $schema->string()->description('Alternative to id.'),
            'title' => $schema->string(),
            'detail' => $schema->string()->nullable(),
            'priority' => $schema->string()->enum(self::enumValues(ActionItemPriority::class)),
            'status' => $schema->string()->enum(self::enumValues(ActionItemStatus::class))->description('done = finished; dropped = no longer needed.'),
            'due_on' => $schema->string()->format('date')->nullable()->description('YYYY-MM-DD, or null to clear.'),
            'owner' => $schema->string()->nullable()->description('Delegate by setting a colleague name (as in dev_activity_summary → developers); null or Kenneth = his own.'),
            'notes' => $schema->string()->nullable(),
        ];
    }
}
