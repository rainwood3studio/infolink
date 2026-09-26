<?php

namespace App\Filament\Widgets;

use App\Domain\Finance\CashForecaster;
use Filament\Widgets\ChartWidget;

/**
 * Month-end cash forecast to year end, starting from the latest bank balance. It stops at year end because recurring
 * income is only entered through December; beyond that the line would show costs without income.
 */
class CashForecastChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = '現金推估（至年底）';

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
        $forecast = app(CashForecaster::class)->calculate();

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
