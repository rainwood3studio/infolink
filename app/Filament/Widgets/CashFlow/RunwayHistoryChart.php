<?php

namespace App\Filament\Widgets\CashFlow;

use App\Domain\Finance\CashFlowAnalytics;
use App\Filament\Support\ChartPalette;
use Filament\Support\RawJs;

/**
 * 金流分析: month-end balance ÷ trailing 3-month average regular outflow.
 */
class RunwayHistoryChart extends CashFlowChartWidget
{
    protected ?string $heading = '可撐月數歷史';

    protected ?string $description = '月底餘額 ÷ 近 3 個月平均常態支出（不含一次性與代墊）；低於 3 個月要注意，低於 2 個月是警訊。';

    protected function getType(): string
    {
        return 'line';
    }

    protected function chartData(): array
    {
        $history = $this->analytics()->runwayHistory();

        return [
            'datasets' => [[
                'label' => '可撐月數',
                'data' => array_column($history, 'runway_months'),
                'borderColor' => ChartPalette::BALANCE,
                'backgroundColor' => ChartPalette::BALANCE,
            ]],
            'labels' => array_column($history, 'month'),
        ];
    }

    protected function getOptions(): RawJs
    {
        $months = CashFlowAnalytics::RUNWAY_TRAILING_MONTHS;

        return RawJs::make(<<<JS
            {
                scales: { y: { beginAtZero: true, ticks: { callback: (value) => value + ' 月' } } },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: (context) => context.parsed.y === null ? '近 {$months} 個月無常態支出' : '可撐 ' + context.parsed.y.toFixed(1) + ' 個月',
                        },
                    },
                },
            }
        JS);
    }
}
