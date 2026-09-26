<?php

namespace App\Domain\Finance;

use App\Domain\Metrics\MetricRecorder;
use App\Enums\Source;
use App\Models\CashForecast;
use App\Models\CostBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The headline finance numbers (the pinned dashboard cards), computed live from transactions and receivables.
 */
class FinancePosition
{
    public const int LOOKAHEAD_DAYS = 90;

    public function __construct(
        protected ReceivableService $receivables,
        protected CashForecaster $forecaster,
    ) {}

    /**
     * @return array{as_of:CarbonImmutable, balance:int, monthly_cost:?int, runway_months:?float, forecast_min_90d:int, forecast:ForecastResult, outstanding_taxed:int, low_confidence_taxed:int, overdue_taxed:int}|null
     */
    public function current(): ?array
    {
        $cash = $this->receivables->currentCashBalance();

        if ($cash === null) {
            return null;
        }

        $totals = $this->receivables->outstandingTotals();
        $forecast = $this->forecaster->calculate();
        $lookahead = $this->forecaster->calculate(until: $cash['as_of']->addDays(self::LOOKAHEAD_DAYS)->format('Y-m'));
        $monthlyCost = CostBaseline::query()
            ->whereDate('effective_from', '<=', $cash['as_of'])
            ->latest('effective_from')
            ->value('monthly_cost');

        return [
            'as_of' => $cash['as_of'],
            'balance' => $cash['balance'],
            'monthly_cost' => $monthlyCost,
            'runway_months' => $monthlyCost ? round($cash['balance'] / $monthlyCost, 2) : null,
            'forecast_min_90d' => min($cash['balance'], ...array_column($lookahead->rows, 'balance')),
            'forecast' => $forecast,
            'outstanding_taxed' => $totals['high_taxed'],
            'low_confidence_taxed' => $totals['low_taxed'],
            'overdue_taxed' => $totals['overdue_taxed'],
        ];
    }

    /**
     * Store today's finance metric values and a forecast snapshot, so trends and forecast accuracy can be tracked.
     */
    public function recordSnapshot(Source $source = Source::System): ?CashForecast
    {
        $position = $this->current();

        if ($position === null) {
            return null;
        }

        return DB::transaction(function () use ($position, $source): CashForecast {
            app(MetricRecorder::class)->recordMany(array_values(array_filter([
                ['key' => 'cash.balance', 'value' => $position['balance']],
                $position['runway_months'] === null ? null : ['key' => 'cash.runway_months', 'value' => $position['runway_months']],
                ['key' => 'cash.forecast_min_90d', 'value' => $position['forecast_min_90d']],
                ['key' => 'cash.forecast_year_end', 'value' => $position['forecast']->yearEndBalance],
                ['key' => 'ar.outstanding_taxed', 'value' => $position['outstanding_taxed']],
                ['key' => 'ar.low_confidence_taxed', 'value' => $position['low_confidence_taxed']],
                ['key' => 'ar.overdue_taxed', 'value' => $position['overdue_taxed']],
            ])), $source);

            return $this->forecaster->snapshot(source: $source);
        });
    }
}
