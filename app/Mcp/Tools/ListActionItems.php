<?php

namespace App\Mcp\Tools;

use App\Enums\ActionItemStatus;
use App\Mcp\Tools\Concerns\PresentsRecords;
use App\Models\ActionItem;
use App\Models\Insight;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_action_items')]
#[Description(<<<'TEXT'
List action items (things someone needs to do), ordered by due date (undated last), then priority (p1 highest). ALWAYS call this before create_action_item to avoid duplicates.
By default only pending items (status todo, doing or waiting). `status` also accepts done, dropped, all.
Filters: `due_before` (YYYY-MM-DD, inclusive: due on or before that date; undated items are excluded), `owner` (partial match), `insight_id` (items linked to that insight).
Fields: `due_on`, `days_overdue` (days past due_on for pending items, else 0), `owner`, `related_type`/`related_id` (the record it came from, e.g. insight 12), `external_key` (idempotency key for create_action_item), `completed_at`.
`total` is the number of matching items; at most `limit` (default 100, max 300) are returned.
TEXT)]
class ListActionItems extends ReadTool
{
    use PresentsRecords;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_ALL = 'all';

    public const int DEFAULT_LIMIT = 100;

    public const int MAX_LIMIT = 300;

    public function handle(Request $request): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', self::statuses())],
            'due_before' => ['nullable', 'date'],
            'owner' => ['nullable', 'string', 'max:255'],
            'insight_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);

        $status = $validated['status'] ?? self::STATUS_PENDING;

        $query = ActionItem::query()
            ->when($status === self::STATUS_PENDING, fn (Builder $query) => $query->pending())
            ->when(! in_array($status, [self::STATUS_PENDING, self::STATUS_ALL], true), fn (Builder $query) => $query->where('status', $status))
            ->when($validated['due_before'] ?? null, fn (Builder $query, string $date) => $query->whereNotNull('due_on')->whereDate('due_on', '<=', $date))
            ->when(filled($validated['owner'] ?? null), fn (Builder $query) => $query->whereLike('owner', '%'.$validated['owner'].'%'))
            ->when($validated['insight_id'] ?? null, fn (Builder $query, int|string $insightId) => $query
                ->where('related_type', (new Insight)->getMorphClass())
                ->where('related_id', (int) $insightId));

        $total = (clone $query)->count();

        $actionItems = $query
            ->orderByRaw('case when due_on is null then 1 else 0 end')
            ->orderBy('due_on')
            ->orderBy('priority')
            ->orderBy('id')
            ->limit($validated['limit'] ?? self::DEFAULT_LIMIT)
            ->get();

        return Response::json([
            'filters' => array_filter([
                'status' => $status,
                'due_before' => $validated['due_before'] ?? null,
                'owner' => $validated['owner'] ?? null,
                'insight_id' => $validated['insight_id'] ?? null,
            ], filled(...)),
            'total' => $total,
            'action_items' => $actionItems->map(fn (ActionItem $actionItem): array => static::presentActionItem($actionItem))->all(),
        ]);
    }

    /**
     * @return list<string>
     */
    protected static function statuses(): array
    {
        return [...array_column(ActionItemStatus::cases(), 'value'), self::STATUS_PENDING, self::STATUS_ALL];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(self::statuses())
                ->description('Default `pending` (todo + doing + waiting).'),
            'due_before' => $schema->string()->format('date')
                ->description('Only items due on or before this date (YYYY-MM-DD).'),
            'owner' => $schema->string()
                ->description('Partial match on owner.'),
            'insight_id' => $schema->integer()
                ->description('Only items linked to this insight.'),
            'limit' => $schema->integer()->min(1)->max(self::MAX_LIMIT)
                ->description('Maximum items to return. Default 100.'),
        ];
    }
}
