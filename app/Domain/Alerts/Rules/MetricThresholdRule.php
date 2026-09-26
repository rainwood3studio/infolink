<?php

namespace App\Domain\Alerts\Rules;

use App\Domain\Alerts\Firing;
use App\Enums\InsightKind;
use App\Enums\InsightSeverity;
use App\Models\AlertRule;
use App\Models\MetricDefinition;
use App\Models\MetricValue;
use App\Models\RedmineIssue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A metric compared to thresholds, using the values of the metric's latest period.
 *
 * - `operator` + `threshold` → the rule's severity; `critical_threshold` (same operator) → critical.
 * - params `dimension_prefix` (e.g. `project:`): evaluate every dimension with that prefix instead of the total
 *   (dimension ''), one firing per dimension. Only dimensions recorded in the latest period count, so a project
 *   that dropped out of the snapshot does not keep firing on an old value.
 * - params `increase_threshold` (+ `increase_days`, default 7): also fire when the value rose by more than this
 *   since the latest period at least `increase_days` earlier (week-over-week for daily snapshots).
 * - params `suggestion`: the suggested action appended to the body.
 *
 * Template variables: value, threshold, critical_threshold, previous, change, change_text (「（7 天 +6）」, empty without
 * a comparison), period, dimension, project, project_name.
 */
class MetricThresholdRule extends BaseRule
{
    public static function label(): string
    {
        return '指標門檻';
    }

    public function evaluate(AlertRule $rule, CarbonImmutable $now): array
    {
        $key = (string) $rule->metric_key;
        $latestPeriod = MetricValue::query()->where('metric_key', $key)->max('period_start');

        if ($latestPeriod === null) {
            return [];
        }

        $latestPeriod = CarbonImmutable::parse($latestPeriod)->startOfDay();
        $prefix = $this->param($rule, 'dimension_prefix');
        $current = $this->valuesAt($key, $latestPeriod, $prefix);
        $increaseThreshold = $this->param($rule, 'increase_threshold');
        $previous = collect();
        $previousPeriod = null;

        if ($increaseThreshold !== null) {
            $previousPeriod = MetricValue::query()
                ->where('metric_key', $key)
                ->whereDate('period_start', '<=', $latestPeriod->subDays((int) $this->param($rule, 'increase_days', 7))->toDateString())
                ->max('period_start');
            $previousPeriod = $previousPeriod !== null ? CarbonImmutable::parse($previousPeriod)->startOfDay() : null;
            $previous = $previousPeriod !== null ? $this->valuesAt($key, $previousPeriod, $prefix) : collect();
        }

        $definition = MetricDefinition::query()->find($key);
        $projectNames = $prefix === 'project:' ? $this->projectNames($current->keys()) : collect();
        $firings = [];

        foreach ($current as $dimension => $value) {
            $previousValue = $previous->get($dimension);
            $change = $previousValue !== null ? $value - $previousValue : null;
            $severity = $this->severityFor($rule, $value, $change, $increaseThreshold !== null ? (float) $increaseThreshold : null);

            if ($severity === null) {
                continue;
            }

            $project = $prefix !== null ? substr($dimension, strlen($prefix)) : '';

            $firings[] = new Firing(
                vars: [
                    'value' => self::number($value),
                    'threshold' => $rule->threshold !== null ? self::number((float) $rule->threshold) : '—',
                    'critical_threshold' => $rule->critical_threshold !== null ? self::number((float) $rule->critical_threshold) : '—',
                    'previous' => $previousValue !== null ? self::number($previousValue) : '—',
                    'change' => $change !== null ? self::signed($change) : '—',
                    'change_text' => $change !== null ? '（'.$this->param($rule, 'increase_days', 7).' 天 '.self::signed($change).'）' : '',
                    'period' => $latestPeriod->toDateString(),
                    'dimension' => $dimension,
                    'project' => $project,
                    'project_name' => $projectNames->get($project, $project),
                ],
                body: $this->body($rule, $definition, $dimension, $value, $latestPeriod, $previousValue, $previousPeriod, $change),
                evidence: [
                    'metric_key' => $key,
                    'dimension' => $dimension,
                    'period_start' => $latestPeriod->toDateString(),
                    'value' => $value,
                    'previous_period_start' => $previousPeriod?->toDateString(),
                    'previous_value' => $previousValue,
                    'change' => $change,
                ],
                severity: $severity,
                kind: $increaseThreshold !== null ? InsightKind::Anomaly : InsightKind::Risk,
            );
        }

        return $firings;
    }

    protected function severityFor(AlertRule $rule, float $value, ?float $change, ?float $increaseThreshold): ?InsightSeverity
    {
        $operator = (string) ($rule->operator ?: '>');

        return match (true) {
            self::breaches($operator, $value, $this->criticalThreshold($rule)) => InsightSeverity::Critical,
            self::breaches($operator, $value, $rule->threshold !== null ? (float) $rule->threshold : null) => $rule->severity,
            $change !== null && $increaseThreshold !== null && $change > $increaseThreshold => $rule->severity,
            default => null,
        };
    }

    /**
     * @return Collection<string, float> keyed by dimension
     */
    protected function valuesAt(string $key, CarbonImmutable $period, ?string $prefix): Collection
    {
        return MetricValue::query()
            ->where('metric_key', $key)
            ->whereDate('period_start', $period->toDateString())
            ->when(
                $prefix !== null,
                fn ($query) => $query->where('dimension', 'like', $prefix.'%'),
                fn ($query) => $query->where('dimension', ''),
            )
            ->orderBy('dimension')
            ->get()
            ->filter(fn (MetricValue $row): bool => $prefix === null || str_starts_with($row->dimension, $prefix))
            ->mapWithKeys(fn (MetricValue $row): array => [$row->dimension => (float) $row->value]);
    }

    /**
     * @param  Collection<int, string>  $dimensions
     * @return Collection<string, string> Redmine project name by identifier
     */
    protected function projectNames(Collection $dimensions): Collection
    {
        $identifiers = $dimensions->map(fn (string $dimension): string => substr($dimension, strlen('project:')))->all();

        return RedmineIssue::withTrashed()
            ->whereIn('project_identifier', $identifiers)
            ->distinct()
            ->pluck('project_name', 'project_identifier');
    }

    protected function body(AlertRule $rule, ?MetricDefinition $definition, string $dimension, float $value, CarbonImmutable $period, ?float $previousValue, ?CarbonImmutable $previousPeriod, ?float $change): string
    {
        $operator = (string) ($rule->operator ?: '>');
        $lines = [
            sprintf('- 指標：%s（`%s`%s）', $definition->name ?? $rule->metric_key, $rule->metric_key, $dimension !== '' ? "，`{$dimension}`" : ''),
            sprintf('- 最新值：**%s**（%s）', self::number($value), $period->toDateString()),
        ];

        if ($rule->threshold !== null || $rule->critical_threshold !== null) {
            $lines[] = '- 門檻：'.collect([
                $rule->threshold !== null ? "{$rule->severity->getLabel()} {$operator} ".self::number((float) $rule->threshold) : null,
                $rule->critical_threshold !== null ? "嚴重 {$operator} ".self::number((float) $rule->critical_threshold) : null,
            ])->filter()->implode('；');
        }

        if ($this->param($rule, 'increase_threshold') !== null) {
            $lines[] = $previousValue !== null
                ? sprintf('- 對照 %s：%s（變化 **%s**，門檻：增加超過 %s）', $previousPeriod?->toDateString(), self::number($previousValue), self::signed((float) $change), self::number((float) $this->param($rule, 'increase_threshold')))
                : '- 沒有一週前的數值可比較';
        }

        $suggestion = $this->param($rule, 'suggestion');

        return implode("\n", $lines).(filled($suggestion) ? "\n\n**建議**：{$suggestion}" : '');
    }
}
