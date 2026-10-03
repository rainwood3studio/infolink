<?php

namespace App\Mcp\Tools\Concerns;

use App\Enums\ActionItemStatus;
use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Models\ActionItem;
use App\Models\Insight;
use App\Models\MetricDefinition;
use App\Models\Receivable;
use App\Models\Report;
use App\Models\SyncRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Shared JSON shapes for the read tools, so the same record looks the same in every tool (and in get_briefing).
 */
trait PresentsRecords
{
    /**
     * Decimal columns come back as strings ("1072776.0000"); send whole numbers as ints, the rest as floats.
     */
    protected static function number(int|float|string|null $value): int|float|null
    {
        if ($value === null) {
            return null;
        }

        $float = (float) $value;

        return floor($float) === $float && abs($float) < PHP_INT_MAX ? (int) $float : round($float, 4);
    }

    protected static function date(?CarbonInterface $date): ?string
    {
        return $date?->toDateString();
    }

    protected static function dateTime(?CarbonInterface $dateTime): ?string
    {
        return $dateTime?->toIso8601String();
    }

    /**
     * @return array<string, mixed>
     */
    protected static function presentMetricDefinition(MetricDefinition $definition): array
    {
        return [
            'key' => $definition->key,
            'name' => $definition->name,
            'category' => $definition->category->value,
            'unit' => $definition->unit->value,
            'period_type' => $definition->period_type->value,
            'better' => $definition->better->value,
            'target' => static::number($definition->target),
            'warn_threshold' => static::number($definition->warn_threshold),
            'critical_threshold' => static::number($definition->critical_threshold),
            'calculator' => $definition->calculator,
            'is_pinned' => $definition->is_pinned,
            'description' => $definition->description,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function presentReceivable(Receivable $receivable): array
    {
        return [
            'id' => $receivable->id,
            'customer' => $receivable->customer?->short_name ?: $receivable->customer?->name,
            'project' => $receivable->project?->name,
            'item' => $receivable->item,
            'untaxed' => $receivable->amount_untaxed,
            'taxed' => $receivable->amount_taxed,
            'tax_rate' => static::number($receivable->tax_rate),
            'expected_on' => static::date($receivable->expected_on),
            'days_overdue' => $receivable->is_overdue ? (int) $receivable->expected_on->diffInDays(today()) : 0,
            'confidence' => $receivable->confidence->value,
            'status' => $receivable->status->value,
            'invoiced_on' => static::date($receivable->invoiced_on),
            'received_on' => static::date($receivable->received_on),
            'is_recurring' => $receivable->is_recurring,
            'external_key' => $receivable->external_key,
            'notes' => $receivable->notes,
            'vault_ref' => $receivable->vault_ref,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function presentInsight(Insight $insight): array
    {
        return [
            'id' => $insight->id,
            'fingerprint' => $insight->fingerprint,
            'kind' => $insight->kind->value,
            'severity' => $insight->severity->value,
            'category' => $insight->category->value,
            'status' => $insight->status->value,
            'title' => $insight->title,
            'body' => $insight->body,
            'evidence' => $insight->evidence,
            'first_seen_at' => static::dateTime($insight->first_seen_at),
            'last_seen_at' => static::dateTime($insight->last_seen_at),
            'resolved_at' => static::dateTime($insight->resolved_at),
            'expires_at' => static::dateTime($insight->expires_at),
            'action_items_count' => (int) ($insight->action_items_count ?? $insight->actionItems()->count()),
            'source' => $insight->source->value,
            'actor' => $insight->actor,
            'notes' => $insight->notes,
            'vault_ref' => $insight->vault_ref,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function presentActionItem(ActionItem $actionItem): array
    {
        $isPending = in_array($actionItem->status, [ActionItemStatus::Todo, ActionItemStatus::Doing, ActionItemStatus::Waiting], true);

        return [
            'id' => $actionItem->id,
            'title' => $actionItem->title,
            'detail' => $actionItem->detail,
            'priority' => $actionItem->priority->value,
            'status' => $actionItem->status->value,
            'due_on' => static::date($actionItem->due_on),
            'days_overdue' => $isPending && $actionItem->due_on?->lt(today()) ? (int) $actionItem->due_on->diffInDays(today()) : 0,
            'owner' => $actionItem->owner,
            'is_mine' => $actionItem->isMine(),
            'related_type' => $actionItem->related_type === null ? null : Str::snake(class_basename($actionItem->related_type)),
            'related_id' => $actionItem->related_id,
            'completed_at' => static::dateTime($actionItem->completed_at),
            'external_key' => $actionItem->external_key,
            'source' => $actionItem->source->value,
            'notes' => $actionItem->notes,
            'vault_ref' => $actionItem->vault_ref,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function presentReportSummary(Report $report): array
    {
        return [
            'id' => $report->id,
            'type' => $report->type->value,
            'title' => $report->title,
            'period_start' => static::date($report->period_start),
            'period_end' => static::date($report->period_end),
            'excerpt' => static::excerpt($report->body),
            'created_at' => static::dateTime($report->created_at),
        ];
    }

    /**
     * Plain-text start of a Markdown body: headings/emphasis markers dropped, whitespace collapsed, at most $limit characters.
     */
    protected static function excerpt(?string $markdown, int $limit = 200): string
    {
        $text = preg_replace(['/^\s{0,3}#{1,6}\s*/m', '/[*_`>|]+/', '/\s+/u'], ['', '', ' '], (string) $markdown);

        $text = trim((string) $text);

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit)).'…' : $text;
    }

    /**
     * Latest run and latest successful run of a background job. `last_error` is only set when the latest run failed.
     *
     * @return array{last_status: ?string, last_run_at: ?string, last_ok_at: ?string, last_error: ?string}
     */
    protected static function presentSyncJob(SyncJob $job): array
    {
        $latest = SyncRun::latestFor($job);
        $lastOk = $latest?->status === SyncStatus::Ok ? $latest : SyncRun::latestFor($job, SyncStatus::Ok);

        return [
            'last_status' => $latest?->status->value,
            'last_run_at' => static::dateTime($latest?->started_at),
            'last_ok_at' => static::dateTime($lastOk?->finished_at ?? $lastOk?->started_at),
            'last_error' => $latest?->status === SyncStatus::Failed ? Str::limit((string) $latest->error, 300) : null,
        ];
    }
}
