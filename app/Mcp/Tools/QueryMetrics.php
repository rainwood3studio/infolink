<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsRecords;
use App\Models\MetricDefinition;
use App\Models\MetricValue;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('query_metrics')]
#[Description(<<<'TEXT'
Metric history. For each requested key returns its `definition` (name, unit, period_type, better, thresholds, description — read it before interpreting) and `series`: one entry per dimension, each with `values` ordered oldest → newest as {period, value}.
- `period` is the period start date: the day for day/snapshot metrics, the Monday for week metrics, the 1st for month metrics.
- Units: twd = whole NTD (receivable/AR metrics are tax-inclusive when the key says `_taxed`), ratio = 0..1, count, hours, months, days.
- `dimension`: '' (default) = the company-wide total; a specific value such as `project:tcsb-5f-b2c`, `assignee:裕樺` or `user:永彬`; or '*' for every dimension including the total.
- `from`/`to` (YYYY-MM-DD, inclusive) default to the last 180 days up to today.
- At most 500 values per key; when more match, the newest 500 are returned and `truncated` is true — narrow the dates or the dimension.
Unknown keys are listed in `unknown_keys` (see list_metric_definitions).
TEXT)]
class QueryMetrics extends ReadTool
{
    use PresentsRecords;

    public const int MAX_VALUES_PER_KEY = 500;

    public const int DEFAULT_DAYS = 180;

    public const string ALL_DIMENSIONS = '*';

    public function handle(Request $request): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'keys' => ['required', 'array', 'min:1', 'max:30'],
            'keys.*' => ['required', 'string', 'distinct'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'dimension' => ['nullable', 'string', 'max:255'],
        ]);

        $to = CarbonImmutable::parse($validated['to'] ?? today())->startOfDay();
        $from = isset($validated['from'])
            ? CarbonImmutable::parse($validated['from'])->startOfDay()
            : $to->subDays(self::DEFAULT_DAYS);
        $dimension = $validated['dimension'] ?? '';

        $definitions = MetricDefinition::query()->whereIn('key', $validated['keys'])->get()->keyBy('key');

        $metrics = collect($validated['keys'])
            ->filter(fn (string $key): bool => $definitions->has($key))
            ->map(fn (string $key): array => $this->metric($definitions[$key], $from, $to, $dimension))
            ->values()
            ->all();

        return Response::json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'dimension' => $dimension,
            'metrics' => $metrics,
            'unknown_keys' => array_values(array_diff($validated['keys'], $definitions->keys()->all())),
        ]);
    }

    /**
     * @return array{key: string, definition: array<string, mixed>, truncated: bool, series: list<array{dimension: string, values: list<array{period: string, value: int|float|null}>}>}
     */
    protected function metric(MetricDefinition $definition, CarbonImmutable $from, CarbonImmutable $to, string $dimension): array
    {
        $rows = MetricValue::query()
            ->where('metric_key', $definition->key)
            ->whereDate('period_start', '>=', $from->toDateString())
            ->whereDate('period_start', '<=', $to->toDateString())
            ->when($dimension !== self::ALL_DIMENSIONS, fn ($query) => $query->where('dimension', $dimension))
            ->orderByDesc('period_start')
            ->orderBy('dimension')
            ->limit(self::MAX_VALUES_PER_KEY + 1)
            ->get(['period_start', 'dimension', 'value']);

        $truncated = $rows->count() > self::MAX_VALUES_PER_KEY;

        $series = $rows
            ->take(self::MAX_VALUES_PER_KEY)
            ->groupBy('dimension')
            ->sortKeys()
            ->map(fn (Collection $values, string $dimension): array => [
                'dimension' => $dimension,
                'values' => $values
                    ->sortBy('period_start')
                    ->map(fn (MetricValue $value): array => [
                        'period' => $value->period_start->toDateString(),
                        'value' => static::number($value->value),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        $presented = static::presentMetricDefinition($definition);

        return [
            'key' => $definition->key,
            'definition' => array_intersect_key($presented, array_flip(['name', 'unit', 'period_type', 'better', 'target', 'warn_threshold', 'critical_threshold', 'description'])),
            'truncated' => $truncated,
            'series' => $series,
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'keys' => $schema->array()
                ->items($schema->string()->description('Metric key, e.g. cash.balance or delivery.open'))
                ->description('Metric keys to fetch (see list_metric_definitions). Up to 30.')
                ->required(),
            'from' => $schema->string()->format('date')
                ->description('First period start to include (YYYY-MM-DD). Default: 180 days before `to`.'),
            'to' => $schema->string()->format('date')
                ->description('Last period start to include (YYYY-MM-DD). Default: today.'),
            'dimension' => $schema->string()
                ->description("'' (default) = company total; a dimension such as 'project:tcsb-5f-b2c'; '*' = all dimensions."),
        ];
    }
}
