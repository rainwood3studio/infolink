<?php

namespace App\Domain\Finance;

use App\Domain\Metrics\MetricRecorder;
use App\Enums\Source;
use App\Models\BankTransaction;
use App\Models\CashForecast;
use Carbon\CarbonImmutable;

/**
 * Forecast accuracy: how far last month's forecast of this month's closing balance was from the real one.
 *
 * For each completed month M:
 * - forecast: the latest cash_forecasts snapshot whose as_of falls in M−1 (latest as_of, then latest id), and the
 *   `balance` of its row for M. Months whose forecast has no row for M (e.g. a December forecast that stops at
 *   year end) are not paired.
 * - actual: the sum over bank accounts of the last transaction balance on or before M's month end.
 * - M is complete only when a transaction dated after M's month end exists, i.e. the statement has been imported
 *   past the month end; until then the month is not paired.
 *
 * error = actual − predicted (positive: better than forecast); error_ratio = error ÷ |predicted| (null when 0).
 */
class ForecastAccuracy
{
    public function __construct(
        protected ReceivableService $receivables,
        protected MetricRecorder $recorder,
    ) {}

    /**
     * Every completed month that has a forecast from the previous month, oldest first.
     *
     * @return list<array{month: string, forecast_id: int, forecast_as_of: string, predicted: int, actual: int, error: int, error_ratio: ?float}>
     */
    public function history(): array
    {
        $firstForecast = CashForecast::query()->min('as_of');
        $latestTransaction = BankTransaction::query()->max('txn_date');

        if ($firstForecast === null || $latestTransaction === null) {
            return [];
        }

        $history = [];
        $month = CarbonImmutable::parse($firstForecast)->startOfMonth()->addMonthNoOverflow();
        $latestTransaction = CarbonImmutable::parse($latestTransaction)->startOfDay();

        for (; $month->endOfMonth()->startOfDay()->lt($latestTransaction); $month = $month->addMonthNoOverflow()) {
            $entry = $this->forMonth($month);

            if ($entry !== null) {
                $history[] = $entry;
            }
        }

        return $history;
    }

    /**
     * The accuracy of the previous month's forecast for the month containing `$month`, or null when unpaired.
     *
     * @return array{month: string, forecast_id: int, forecast_as_of: string, predicted: int, actual: int, error: int, error_ratio: ?float}|null
     */
    public function forMonth(CarbonImmutable $month): ?array
    {
        $month = $month->startOfMonth();
        $monthEnd = $month->endOfMonth()->startOfDay();

        if (! BankTransaction::query()->whereDate('txn_date', '>', $monthEnd)->exists()) {
            return null;
        }

        $previous = $month->subMonthNoOverflow();
        $forecast = CashForecast::query()
            ->whereDate('as_of', '>=', $previous)
            ->whereDate('as_of', '<=', $previous->endOfMonth()->startOfDay())
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->first();

        $row = collect($forecast?->rows ?? [])->firstWhere('month', $month->format('Y-m'));
        $actual = $this->receivables->currentCashBalance($monthEnd);

        if ($forecast === null || $row === null || $actual === null) {
            return null;
        }

        $predicted = (int) $row['balance'];
        $error = $actual['balance'] - $predicted;

        return [
            'month' => $month->format('Y-m'),
            'forecast_id' => $forecast->id,
            'forecast_as_of' => $forecast->as_of->toDateString(),
            'predicted' => $predicted,
            'actual' => $actual['balance'],
            'error' => $error,
            'error_ratio' => $predicted === 0 ? null : round($error / abs($predicted), 4),
        ];
    }

    /**
     * Record cash.forecast_error (and the ratio) for every paired month. Idempotent: values are upserted per month.
     *
     * @return list<array{month: string, forecast_id: int, forecast_as_of: string, predicted: int, actual: int, error: int, error_ratio: ?float}>
     */
    public function record(Source $source = Source::System): array
    {
        $history = $this->history();
        $entries = [];

        foreach ($history as $entry) {
            $periodStart = CarbonImmutable::createFromFormat('!Y-m', $entry['month']);
            $notes = sprintf(
                'forecast #%d as of %s predicted %s; actual %s',
                $entry['forecast_id'],
                $entry['forecast_as_of'],
                number_format($entry['predicted']),
                number_format($entry['actual']),
            );

            $entries[] = ['key' => 'cash.forecast_error', 'value' => $entry['error'], 'period_start' => $periodStart, 'notes' => $notes];

            if ($entry['error_ratio'] !== null) {
                $entries[] = ['key' => 'cash.forecast_error_ratio', 'value' => $entry['error_ratio'], 'period_start' => $periodStart, 'notes' => $notes];
            }
        }

        $this->recorder->recordMany($entries, $source);

        return $history;
    }
}
