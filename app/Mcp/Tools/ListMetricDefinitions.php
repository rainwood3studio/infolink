<?php

namespace App\Mcp\Tools;

use App\Enums\Category;
use App\Mcp\Tools\Concerns\PresentsRecords;
use App\Models\MetricDefinition;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_metric_definitions')]
#[Description(<<<'TEXT'
List the metric catalog: what each metric key means and how to judge it. Read a metric's `description` before interpreting its numbers.
Fields: `key`; `name` (Chinese display name); `category` (finance/sales/delivery/company); `unit` (twd = whole NTD, count, hours, ratio = 0..1, months, days); `period_type` (day/week/month/snapshot; week periods start on Monday); `better` (up = higher is better, down = lower is better, none = neutral); `target`, `warn_threshold`, `critical_threshold` (null = none; a value past a threshold in the bad direction is warn/critical); `calculator` (how the value is produced: an app calculator/sync name, or null when written by Claude or by hand); `is_pinned` (shown on the dashboard and in get_briefing).
Metric values are fetched with query_metrics.
TEXT)]
class ListMetricDefinitions extends ReadTool
{
    use PresentsRecords;

    public function handle(Request $request): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'category' => ['nullable', Rule::enum(Category::class)],
            'pinned_only' => ['nullable', 'boolean'],
        ]);

        $definitions = MetricDefinition::query()
            ->when($validated['category'] ?? null, fn ($query, string $category) => $query->where('category', $category))
            ->when($validated['pinned_only'] ?? false, fn ($query) => $query->where('is_pinned', true))
            ->orderBy('category')
            ->orderBy('sort')
            ->orderBy('key')
            ->get();

        return Response::json([
            'count' => $definitions->count(),
            'definitions' => $definitions->map(fn (MetricDefinition $definition): array => static::presentMetricDefinition($definition))->all(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()
                ->enum(array_column(Category::cases(), 'value'))
                ->description('Only metrics of this category. Omit for all.'),
            'pinned_only' => $schema->boolean()
                ->description('Only the pinned (dashboard headline) metrics. Default false.'),
        ];
    }
}
