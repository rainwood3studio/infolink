<?php

namespace App\Domain\Sales;

use App\Domain\Metrics\MetricRecorder;
use App\Enums\DealStage;
use App\Models\Deal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Computes the sales.* metrics from the deals table and writes them through MetricRecorder (source: system).
 *
 * Daily snapshots: `sales.pipeline_weighted` (Σ amount × probability of open deals), `sales.deals_open` (total, plus
 * `stage:<stage>` for every open stage, zeros included) and `sales.deals_no_next_action` (open deals whose
 * `next_action_on` is empty or before the day). Monthly: `sales.won_amount` (amount of deals won with `closed_at`
 * in the month; the previous month is re-recorded too so a late, back-dated win still lands).
 */
class SalesMetrics
{
    public function __construct(
        protected MetricRecorder $recorder,
        protected DealService $deals,
    ) {}

    /**
     * Record every sales metric for a date (default today) from the current deals.
     */
    public function record(?CarbonInterface $date = null): void
    {
        $date = CarbonImmutable::instance($date ?? today())->startOfDay();
        $pipeline = $this->deals->pipeline($date);

        DB::transaction(function () use ($date, $pipeline): void {
            $this->recorder->record('sales.pipeline_weighted', $pipeline['weighted_total'], $date);
            $this->recorder->record('sales.deals_open', $pipeline['open_count'], $date);

            foreach ($pipeline['by_stage'] as $stage => $summary) {
                $this->recorder->record('sales.deals_open', $summary['count'], $date, "stage:{$stage}");
            }

            $this->recorder->record('sales.deals_no_next_action', $pipeline['no_next_action']->count(), $date);

            foreach ([$date->subMonthNoOverflow(), $date] as $month) {
                $this->recorder->record('sales.won_amount', $this->wonAmount($month), $month->startOfMonth());
            }
        });
    }

    /**
     * Untaxed amount of the deals won (by `closed_at`) in the month containing `$month`.
     */
    public function wonAmount(CarbonInterface $month): int
    {
        $month = CarbonImmutable::instance($month);

        return (int) Deal::query()
            ->where('stage', DealStage::Won)
            ->where('closed_at', '>=', $month->startOfMonth())
            ->where('closed_at', '<', $month->startOfMonth()->addMonth())
            ->sum('amount_untaxed');
    }
}
