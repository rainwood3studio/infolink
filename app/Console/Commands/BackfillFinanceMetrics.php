<?php

namespace App\Console\Commands;

use App\Domain\Finance\ForecastAccuracy;
use App\Domain\Finance\MonthlyFinanceMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('infolink:backfill-finance-metrics {--from= : First month to record (Y-m); defaults to the first bank transaction month} {--without-summary : Do not seed company.net_cashflow_ytd from the vault monthly bank summary}')]
#[Description('Record the monthly finance metrics for every month with line-level bank transactions, plus forecast accuracy')]
class BackfillFinanceMetrics extends Command
{
    public const string SUMMARY_FILE = 'seeders/data/finance/bank_monthly_summary.json';

    public const string SUMMARY_NOTE = '03.Business/Finance/帳戶流水分析 2025-07~2026-08.md';

    /**
     * The monthly summary only has per-month deposit/withdrawal totals (no category split, no one-off flags), so it
     * is never used for cash.monthly_cost or revenue.received; it only seeds the YTD net cash flow for the months
     * before line-level data starts, so later months can chain onto it.
     */
    public function handle(MonthlyFinanceMetrics $monthlyMetrics, ForecastAccuracy $forecastAccuracy): int
    {
        $from = $this->option('from');

        if ($from !== null && preg_match('/^\d{4}-\d{2}$/', $from) !== 1) {
            $this->components->error('--from must be a month in Y-m format.');

            return self::FAILURE;
        }

        if (! $this->option('without-summary')) {
            $this->seedSummaryYtd($monthlyMetrics);
        }

        $recorded = $monthlyMetrics->recordRange($from === null ? null : CarbonImmutable::createFromFormat('!Y-m', $from));

        if ($recorded === []) {
            $this->components->warn('No months with bank transactions; nothing recorded.');
        }

        foreach ($recorded as $month => $values) {
            $this->components->twoColumnDetail($month, collect($values)->map(fn (int|float $value, string $key): string => "{$key}={$value}")->implode('  '));
        }

        $accuracy = $forecastAccuracy->record();
        $this->components->info(sprintf('Recorded forecast accuracy for %d month(s).', count($accuracy)));

        return self::SUCCESS;
    }

    protected function seedSummaryYtd(MonthlyFinanceMetrics $monthlyMetrics): void
    {
        $path = database_path(self::SUMMARY_FILE);

        if (! is_file($path)) {
            $this->components->warn('No monthly bank summary found; YTD net cash flow starts from line-level data only.');

            return;
        }

        $result = $monthlyMetrics->recordSummaryYtd(json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR), self::SUMMARY_NOTE);

        if ($result['skipped'] !== null) {
            $this->components->warn("Monthly summary not used: {$result['skipped']}.");

            return;
        }

        foreach ($result['recorded'] as $month => $ytd) {
            $this->components->twoColumnDetail("{$month} (summary)", "company.net_cashflow_ytd={$ytd}");
        }
    }
}
