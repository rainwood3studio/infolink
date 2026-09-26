<?php

namespace App\Filament\Widgets;

use App\Domain\Finance\CashForecaster;
use App\Domain\Finance\ForecastResult;
use Filament\Widgets\ChartWidget;

/**
 * 現金與推估: the live forecast through year end — monthly inflow / outflow bars with the month-end balance line.
 */
class CurrentForecastChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = '目前推估（至年底）';

    protected ?string $maxHeight = '280px';

    protected ?string $pollingInterval = null;

    private ?ForecastResult $forecast = null;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function forecast(): ForecastResult
    {
        return $this->forecast ??= app(CashForecaster::class)->calculate();
    }

    public function getDescription(): string
    {
        return '方法：'.($this->forecast()->assumptions['method'] ?? '—');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $forecast = $this->forecast();

        return [
            'datasets' => [
                [
                    'type' => 'line',
                    'label' => '月底餘額',
                    'data' => [$forecast->openingBalance, ...array_column($forecast->rows, 'balance')],
                    'borderColor' => '#2563eb',
                    'backgroundColor' => '#2563eb',
                    'order' => 0,
                ],
                [
                    'label' => '流入',
                    'data' => [null, ...array_column($forecast->rows, 'inflow')],
                    'backgroundColor' => '#16a34a',
                    'order' => 1,
                ],
                [
                    'label' => '流出',
                    'data' => [null, ...array_map(fn (int $outflow): int => -$outflow, array_column($forecast->rows, 'outflow'))],
                    'backgroundColor' => '#dc2626',
                    'order' => 1,
                ],
            ],
            'labels' => [$forecast->asOf->format('m/d'), ...array_column($forecast->rows, 'month')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return ['interaction' => ['mode' => 'index', 'intersect' => false]];
    }
}
