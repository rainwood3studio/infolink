<?php

namespace App\Filament\Widgets\CashFlow;

use App\Filament\Support\ChartPalette;
use Filament\Support\RawJs;

/**
 * 金流分析: monthly inflow / outflow bars with the net line.
 */
class MonthlyCashFlowChart extends CashFlowChartWidget
{
    protected ?string $heading = '月度流入／流出／淨額';

    protected ?string $description = '綠柱為當月入帳、紅柱為當月支出（向下），藍線為淨額；線在 0 以下代表當月燒錢。';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function chartData(): array
    {
        $months = $this->analytics()->monthlyFlows();

        return [
            'datasets' => [
                [
                    'type' => 'line',
                    'label' => '淨額',
                    'data' => array_column($months, 'net'),
                    'borderColor' => ChartPalette::NET,
                    'backgroundColor' => ChartPalette::NET,
                    'order' => 0,
                ],
                [
                    'label' => '流入',
                    'data' => array_column($months, 'inflow'),
                    'backgroundColor' => ChartPalette::INFLOW,
                    'order' => 1,
                ],
                [
                    'label' => '流出',
                    'data' => array_map(fn (int $outflow): int => -$outflow, array_column($months, 'outflow')),
                    'backgroundColor' => ChartPalette::OUTFLOW,
                    'order' => 1,
                ],
            ],
            'labels' => array_column($months, 'month'),
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->moneyOptions();
    }
}
