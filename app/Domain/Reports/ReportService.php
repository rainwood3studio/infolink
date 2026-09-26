<?php

namespace App\Domain\Reports;

use App\Enums\ReportType;
use App\Enums\Source;
use App\Models\Report;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The single write path for reports.
 *
 * Periodic reports are one per type and period: the period start is normalised to the type's granularity
 * (weekly → Monday, monthly → 1st) and the report is upserted under the external key `<type>:<period_start>`,
 * so re-running an analysis overwrites the report instead of adding another. Ad hoc reports are upserted by the
 * caller's external key when given, otherwise always created.
 */
class ReportService
{
    /**
     * @param  array{type: ReportType|string, period_start: CarbonInterface|string, period_end?: CarbonInterface|string|null, title: string, body: string, metrics_snapshot?: array<string, mixed>|null, notify?: bool, vault_ref?: string|null, notes?: string|null}  $attributes
     */
    public function save(array $attributes, Source $source = Source::Manual, ?string $externalKey = null): Report
    {
        $type = $attributes['type'] instanceof ReportType ? $attributes['type'] : ReportType::from($attributes['type']);
        $periodStart = $this->normalisePeriodStart($type, CarbonImmutable::parse($attributes['period_start']));
        $periodEnd = isset($attributes['period_end'])
            ? CarbonImmutable::parse($attributes['period_end'])->startOfDay()
            : $this->defaultPeriodEnd($type, $periodStart);

        $attributes = [
            ...$attributes,
            'type' => $type,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ];

        if ($type !== ReportType::Adhoc) {
            $externalKey = self::periodKey($type, $periodStart);
        }

        if (blank($externalKey)) {
            return Report::query()->create([...$attributes, 'source' => $source]);
        }

        return Report::upsertFromSource($source, $externalKey, $attributes);
    }

    /**
     * The idempotency key of a periodic report.
     */
    public static function periodKey(ReportType $type, CarbonInterface $periodStart): string
    {
        return $type->value.':'.$periodStart->toDateString();
    }

    public function normalisePeriodStart(ReportType $type, CarbonImmutable $date): CarbonImmutable
    {
        $date = $date->startOfDay();

        return match ($type) {
            ReportType::WeeklyRedmine, ReportType::WeeklyCompany => $date->startOfWeek(CarbonInterface::MONDAY),
            ReportType::MonthlyFinance => $date->startOfMonth(),
            ReportType::DailyBrief, ReportType::Adhoc => $date,
        };
    }

    protected function defaultPeriodEnd(ReportType $type, CarbonImmutable $periodStart): ?CarbonImmutable
    {
        return match ($type) {
            ReportType::DailyBrief => $periodStart,
            ReportType::WeeklyRedmine, ReportType::WeeklyCompany => $periodStart->addDays(6),
            ReportType::MonthlyFinance => $periodStart->endOfMonth()->startOfDay(),
            ReportType::Adhoc => null,
        };
    }
}
