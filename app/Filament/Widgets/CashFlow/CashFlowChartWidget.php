<?php

namespace App\Filament\Widgets\CashFlow;

use App\Filament\Widgets\CashFlow\Concerns\ReadsCashFlowPeriod;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Base for the 金流分析 charts: period from the page filters, NT$ tooltips and 萬 axis ticks.
 */
abstract class CashFlowChartWidget extends ChartWidget
{
    use ReadsCashFlowPeriod;

    protected static bool $isDiscovered = false;

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = null;

    protected ?string $emptyStateHeading = '這段期間沒有逐筆交易資料';

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        return $this->analytics()->hasData() ? $this->chartData() : [];
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function chartData(): array;

    /**
     * Bar / line options: index tooltips as `label：NT$1,234,567` (zeros hidden), y ticks in 萬.
     */
    protected function moneyOptions(bool $stacked = false): RawJs
    {
        $stacked = $stacked ? 'true' : 'false';

        return RawJs::make(<<<JS
            {
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: { stacked: {$stacked} },
                    y: {
                        stacked: {$stacked},
                        ticks: { callback: (value) => (value / 10000).toLocaleString('en-US') + ' 萬' },
                    },
                },
                plugins: {
                    tooltip: {
                        filter: (item) => item.parsed.y !== null && item.parsed.y !== 0,
                        callbacks: {
                            label: (context) => context.dataset.label + '：' + (context.parsed.y < 0 ? '−' : '') + 'NT\$' + Math.abs(Math.round(context.parsed.y)).toLocaleString('en-US'),
                        },
                    },
                },
            }
        JS);
    }

    /**
     * Doughnut options: tooltips as `label：NT$1,234,567（12.3%）`.
     */
    protected function doughnutOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                plugins: {
                    legend: { position: 'right' },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const total = context.dataset.data.reduce((sum, value) => sum + value, 0);
                                const share = total ? (context.parsed / total * 100).toFixed(1) : '0.0';

                                return ' ' + context.label + '：NT$' + Math.round(context.parsed).toLocaleString('en-US') + '（' + share + '%）';
                            },
                        },
                    },
                },
            }
        JS);
    }
}
