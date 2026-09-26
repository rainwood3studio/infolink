<?php

namespace App\Console\Commands;

use App\Domain\Finance\FinancePosition;
use App\Domain\Finance\ForecastAccuracy;
use App\Domain\Finance\MonthlyFinanceMetrics;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('infolink:snapshot-finance')]
#[Description('Record today\'s finance metrics, the previous and current month\'s monthly metrics and forecast accuracy, store a cash forecast snapshot, then evaluate the alert rules')]
class SnapshotFinance extends Command
{
    /**
     * The previous month is re-recorded on every run (not just on the 1st), so a statement imported a few days late
     * still completes it and clears its 'partial month' flag. Every write is an upsert, so reruns are harmless.
     */
    public function handle(FinancePosition $financePosition, MonthlyFinanceMetrics $monthlyMetrics, ForecastAccuracy $forecastAccuracy): int
    {
        $forecast = $financePosition->recordSnapshot();

        if ($forecast === null) {
            $this->components->warn('No bank transactions yet; nothing recorded.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Recorded finance metrics as of %s; forecast year-end NT$%s.',
            $forecast->as_of->toDateString(),
            number_format($forecast->year_end_balance),
        ));

        $thisMonth = today()->toImmutable()->startOfMonth();

        foreach ([$thisMonth->subMonthNoOverflow(), $thisMonth] as $month) {
            if ($monthlyMetrics->record($month) !== []) {
                $this->components->info(sprintf('Recorded monthly finance metrics for %s.', $month->format('Y-m')));
            }
        }

        $accuracy = $forecastAccuracy->record();

        if ($accuracy !== []) {
            $this->components->info(sprintf('Recorded forecast accuracy for %d month(s).', count($accuracy)));
        }

        $this->call('infolink:evaluate-rules');

        return self::SUCCESS;
    }
}
