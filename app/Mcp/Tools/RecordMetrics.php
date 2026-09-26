<?php

namespace App\Mcp\Tools;

use App\Domain\Metrics\MetricRecorder;
use App\Domain\Metrics\UnknownMetricException;
use App\Mcp\Tools\Concerns\WriteToolHelpers;
use App\Models\MetricDefinition;
use App\Models\MetricValue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('record_metrics')]
#[Description('Write metric values in one atomic batch. Idempotent on (key, period_start, dimension): writing the same period again updates the value. period_start defaults to today and is normalised to the metric\'s granularity (week → Monday, month → 1st). Keys must exist (see list_metric_definitions); an unknown key rejects the whole batch and suggests close matches. Metrics that have a calculator are normally computed by the app — writing them is allowed but the response warns.')]
class RecordMetrics extends WriteTool
{
    use WriteToolHelpers;

    public function handle(Request $request, MetricRecorder $recorder): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*' => ['array'],
            'entries.*.key' => ['required', 'string', 'max:128'],
            'entries.*.value' => ['required', 'numeric'],
            'entries.*.period_start' => ['nullable', 'date_format:Y-m-d'],
            'entries.*.dimension' => ['nullable', 'string', 'max:255'],
            'entries.*.notes' => ['nullable', 'string'],
            'entries.*.vault_ref' => ['nullable', 'string', 'max:255'],
        ], [
            'entries.required' => 'Pass `entries`: a non-empty list of {key, value, period_start?, dimension?, notes?, vault_ref?}.',
            'entries.*.key.required' => 'Every entry needs a metric `key` (e.g. cash.balance).',
            'entries.*.value.required' => 'Every entry needs a numeric `value`.',
            'entries.*.value.numeric' => 'Entry :attribute must be a number (no thousands separators or units).',
            'entries.*.period_start.date_format' => 'Entry :attribute must be a date in YYYY-MM-DD format.',
        ]);

        $entries = array_map(fn (array $entry): array => [
            ...$entry,
            'value' => $entry['value'] + 0,
            'dimension' => $entry['dimension'] ?? '',
        ], $validated['entries']);

        $definitions = MetricDefinition::query()->get()->keyBy('key');

        if ($unknown = $this->unknownKeys($entries, $definitions->keys()->all())) {
            return Response::error("Unknown metric key(s); nothing was recorded.\n".implode("\n", $unknown)."\nUse list_metric_definitions to see all keys.");
        }

        try {
            $values = $recorder->recordMany($entries, self::SOURCE);
        } catch (UnknownMetricException $exception) {
            return Response::error($exception->getMessage());
        }

        $warnings = collect($entries)
            ->pluck('key')
            ->unique()
            ->filter(fn (string $key): bool => filled($definitions[$key]->calculator))
            ->map(fn (string $key): string => "[{$key}] is computed by the app ({$definitions[$key]->calculator}); your value will be overwritten by the next calculation. Prefer letting the app compute it.")
            ->values()
            ->all();

        return Response::json([
            'recorded' => $values->count(),
            'values' => $values->map(fn (MetricValue $value): array => [
                'key' => $value->metric_key,
                'period_start' => $value->period_start->toDateString(),
                'dimension' => $value->dimension,
                'value' => (float) $value->value,
            ])->all(),
            'warnings' => $warnings,
        ]);
    }

    /**
     * @param  list<array{key: string}>  $entries
     * @param  list<string>  $knownKeys
     * @return list<string>
     */
    protected function unknownKeys(array $entries, array $knownKeys): array
    {
        return collect($entries)
            ->pluck('key')
            ->unique()
            ->reject(fn (string $key): bool => in_array($key, $knownKeys, true))
            ->map(function (string $key) use ($knownKeys): string {
                $matches = self::closeMatches($key, $knownKeys);

                return "- [{$key}]".($matches === [] ? ': no similar key.' : ': did you mean '.implode(', ', $matches).'?');
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'entries' => $schema->array()
                ->items($schema->object([
                    'key' => $schema->string()->description('Metric key, e.g. cash.balance or delivery.verifying.others.')->required(),
                    'value' => $schema->number()->description('The value; NTD amounts as whole integers.')->required(),
                    'period_start' => $schema->string()->format('date')->description('YYYY-MM-DD; defaults to today. Normalised to the metric\'s period (week → Monday, month → 1st).'),
                    'dimension' => $schema->string()->description('Breakdown, e.g. project:tcsb-5f-b2c or assignee:文豪. Omit for the total.'),
                    'notes' => $schema->string()->description('How the value was derived.'),
                    'vault_ref' => $schema->string()->description('Vault note path the value came from.'),
                ]))
                ->min(1)
                ->description('Values to record atomically.')
                ->required(),
        ];
    }
}
