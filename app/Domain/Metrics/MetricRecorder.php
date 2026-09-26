<?php

namespace App\Domain\Metrics;

use App\Enums\MetricDirection;
use App\Enums\PeriodType;
use App\Enums\Source;
use App\Models\MetricDefinition;
use App\Models\MetricValue;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The single write path for metric values, plus the "latest vs previous" read used by dashboard cards and briefings.
 *
 * Values are idempotent on (metric_key, period_start, dimension): writing the same period again updates the row.
 */
class MetricRecorder
{
    public const string STATUS_OK = 'ok';

    public const string STATUS_WARN = 'warn';

    public const string STATUS_CRITICAL = 'critical';

    /**
     * Create or update the value of a metric for one period.
     *
     * The period start is normalised to the definition's granularity (week → Monday, month → 1st, otherwise the day),
     * so writing "2026-09-24" for a weekly metric lands on the same row as "2026-09-21".
     *
     * @throws UnknownMetricException
     */
    public function record(
        string $key,
        int|float $value,
        ?CarbonInterface $periodStart = null,
        string $dimension = '',
        Source $source = Source::System,
        ?string $vaultRef = null,
        ?string $notes = null,
    ): MetricValue {
        $definition = $this->definition($key);
        $periodStart = $this->normalisePeriodStart($definition->period_type, $periodStart);

        $metricValue = MetricValue::query()
            ->where('metric_key', $key)
            ->whereDate('period_start', $periodStart->toDateString())
            ->where('dimension', $dimension)
            ->first()
            ?? new MetricValue([
                'metric_key' => $key,
                'period_start' => $periodStart,
                'dimension' => $dimension,
            ]);

        $metricValue->fill([
            'value' => $value,
            'source' => $source,
            'vault_ref' => $vaultRef,
            'notes' => $notes,
        ])->save();

        return $metricValue;
    }

    /**
     * Record several values atomically; an unknown key rolls back the whole batch.
     *
     * @param  list<array{key: string, value: int|float, period_start?: CarbonInterface|string|null, dimension?: string|null, vault_ref?: string|null, notes?: string|null}>  $entries
     * @return Collection<int, MetricValue>
     *
     * @throws UnknownMetricException
     */
    public function recordMany(array $entries, Source $source): Collection
    {
        return DB::transaction(fn (): Collection => collect($entries)->map(fn (array $entry): MetricValue => $this->record(
            key: $entry['key'],
            value: $entry['value'],
            periodStart: isset($entry['period_start']) ? CarbonImmutable::parse($entry['period_start']) : null,
            dimension: $entry['dimension'] ?? '',
            source: $source,
            vaultRef: $entry['vault_ref'] ?? null,
            notes: $entry['notes'] ?? null,
        ))->values());
    }

    /**
     * The most recent value for a metric (and dimension), by period.
     */
    public function latest(string $key, string $dimension = ''): ?MetricValue
    {
        return MetricValue::query()
            ->where('metric_key', $key)
            ->where('dimension', $dimension)
            ->orderByDesc('period_start')
            ->first();
    }

    /**
     * The latest value, the one before it, the absolute change, and the threshold status of the latest value.
     *
     * @return array{current: ?MetricValue, previous: ?MetricValue, change: ?float, status: 'ok'|'warn'|'critical'}
     *
     * @throws UnknownMetricException
     */
    public function latestWithPrevious(string $key, string $dimension = ''): array
    {
        $definition = $this->definition($key);

        [$current, $previous] = MetricValue::query()
            ->where('metric_key', $key)
            ->where('dimension', $dimension)
            ->orderByDesc('period_start')
            ->limit(2)
            ->get()
            ->pad(2, null)
            ->all();

        return [
            'current' => $current,
            'previous' => $previous,
            'change' => $current !== null && $previous !== null ? (float) $current->value - (float) $previous->value : null,
            'status' => $current !== null ? $this->statusFor($definition, (float) $current->value) : self::STATUS_OK,
        ];
    }

    /**
     * Compare a value to the definition's thresholds (strict comparison).
     *
     * better=up: below a threshold is bad. better=down: above a threshold is bad.
     * better=none: direction is inferred from the thresholds (critical below warn → low is bad), otherwise ok.
     *
     * @return 'ok'|'warn'|'critical'
     */
    public function statusFor(MetricDefinition $definition, float $value): string
    {
        $warn = $definition->warn_threshold !== null ? (float) $definition->warn_threshold : null;
        $critical = $definition->critical_threshold !== null ? (float) $definition->critical_threshold : null;

        $lowIsBad = match ($definition->better) {
            MetricDirection::Up => true,
            MetricDirection::Down => false,
            MetricDirection::None => $warn !== null && $critical !== null ? $critical < $warn : null,
        };

        if ($lowIsBad === null) {
            return self::STATUS_OK;
        }

        $breaches = fn (?float $threshold): bool => $threshold !== null && ($lowIsBad ? $value < $threshold : $value > $threshold);

        return match (true) {
            $breaches($critical) => self::STATUS_CRITICAL,
            $breaches($warn) => self::STATUS_WARN,
            default => self::STATUS_OK,
        };
    }

    /**
     * @throws UnknownMetricException
     */
    protected function definition(string $key): MetricDefinition
    {
        return MetricDefinition::query()->find($key) ?? throw UnknownMetricException::forKey($key);
    }

    protected function normalisePeriodStart(PeriodType $periodType, ?CarbonInterface $periodStart): CarbonImmutable
    {
        $date = CarbonImmutable::instance($periodStart ?? today())->startOfDay();

        return match ($periodType) {
            PeriodType::Week => $date->startOfWeek(CarbonInterface::MONDAY),
            PeriodType::Month => $date->startOfMonth(),
            PeriodType::Day, PeriodType::Snapshot => $date,
        };
    }
}
