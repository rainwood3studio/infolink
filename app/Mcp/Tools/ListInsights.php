<?php

namespace App\Mcp\Tools;

use App\Enums\Category;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Mcp\Tools\Concerns\PresentsRecords;
use App\Models\Insight;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_insights')]
#[Description(<<<'TEXT'
List insights (things worth knowing: risks, anomalies, reminders), most severe first, then most recently seen. ALWAYS call this before raise_insight to avoid duplicates.
By default only unresolved insights (status open or acknowledged). `status` also accepts open, acknowledged, resolved, dismissed, all.
Filters: `severity` (critical/warning/info), `category` (finance/sales/delivery/company), `fingerprint` (prefix match, e.g. `receivable-overdue:` finds every overdue-receivable insight).
Fields: `fingerprint` (dedup key `<category>-<subject>:<id>`; raising the same fingerprint while unresolved refreshes that insight), `kind`, `evidence` (JSON the raiser attached), `first_seen_at`/`last_seen_at` (ISO 8601, Asia/Taipei), `resolved_at`, `expires_at`, `action_items_count` (action items linked to it; see list_action_items with insight_id), `source`/`actor` (who wrote it), `notes`.
`total` is the number of matching insights; at most `limit` (default 50, max 200) are returned.
TEXT)]
class ListInsights extends ReadTool
{
    use PresentsRecords;

    public const string STATUS_UNRESOLVED = 'unresolved';

    public const string STATUS_ALL = 'all';

    public const int DEFAULT_LIMIT = 50;

    public const int MAX_LIMIT = 200;

    public function handle(Request $request): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', self::statuses())],
            'severity' => ['nullable', Rule::enum(InsightSeverity::class)],
            'category' => ['nullable', Rule::enum(Category::class)],
            'fingerprint' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);

        $status = $validated['status'] ?? self::STATUS_UNRESOLVED;
        $prefix = $validated['fingerprint'] ?? '';

        $query = Insight::query()
            ->when($status === self::STATUS_UNRESOLVED, fn (Builder $query) => $query->unresolved())
            ->when(! in_array($status, [self::STATUS_UNRESOLVED, self::STATUS_ALL], true), fn (Builder $query) => $query->where('status', $status))
            ->when($validated['severity'] ?? null, fn (Builder $query, string $severity) => $query->where('severity', $severity))
            ->when($validated['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($prefix !== '', fn (Builder $query) => $query->whereRaw('substr(fingerprint, 1, ?) = ?', [mb_strlen($prefix), $prefix]));

        $total = (clone $query)->count();

        $insights = $query
            ->withCount('actionItems')
            ->orderByRaw('case severity when ? then 0 when ? then 1 else 2 end', [InsightSeverity::Critical->value, InsightSeverity::Warning->value])
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit($validated['limit'] ?? self::DEFAULT_LIMIT)
            ->get();

        return Response::json([
            'filters' => array_filter([
                'status' => $status,
                'severity' => $validated['severity'] ?? null,
                'category' => $validated['category'] ?? null,
                'fingerprint' => $prefix,
            ], filled(...)),
            'total' => $total,
            'insights' => $insights->map(fn (Insight $insight): array => static::presentInsight($insight))->all(),
        ]);
    }

    /**
     * @return list<string>
     */
    protected static function statuses(): array
    {
        return [...array_column(InsightStatus::cases(), 'value'), self::STATUS_UNRESOLVED, self::STATUS_ALL];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(self::statuses())
                ->description('Default `unresolved` (open + acknowledged).'),
            'severity' => $schema->string()
                ->enum(array_column(InsightSeverity::cases(), 'value')),
            'category' => $schema->string()
                ->enum(array_column(Category::cases(), 'value')),
            'fingerprint' => $schema->string()
                ->description('Fingerprint prefix, e.g. `receivable-overdue:` or an exact fingerprint.'),
            'limit' => $schema->integer()->min(1)->max(self::MAX_LIMIT)
                ->description('Maximum insights to return. Default 50.'),
        ];
    }
}
