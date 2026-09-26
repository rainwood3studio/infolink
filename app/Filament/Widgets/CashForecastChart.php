<?php

namespace App\Filament\Widgets;

use App\Domain\Finance\CashForecaster;
use Filament\Widgets\ChartWidget;

/**
 * Month-end cash forecast for the next six months, starting from the latest bank balance.
 */
class CashForecastChart extends ChartWidget
{
    public const int MONTHS_AHEAD = 6;

    protected static ?int $sort = 3;

    protected ?string $heading = '現金推估（未來 6 個月）';

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $forecaster = app(CashForecaster::class);
        $probe = $forecaster->calculate();
        $forecast = $forecaster->calculate(until: $probe->asOf->addMonthsNoOverflow(self::MONTHS_AHEAD)->format('Y-m'));

        return [
            'datasets' => [
                [
                    'label' => '月底餘額',
                    'data' => [$forecast->openingBalance, ...array_column($forecast->rows, 'balance')],
                    'fill' => true,
                ],
            ],
            'labels' => [$forecast->asOf->format('m/d'), ...array_column($forecast->rows, 'month')],
        ];
    }
}
