<?php

namespace App\Filament\Widgets\CashFlow;

use App\Filament\Support\ChartPalette;
use Filament\Support\RawJs;

/**
 * 金流分析: the period's revenue share per customer (top 5 + 其他) with the concentration figures.
 */
class CustomerConcentrationChart extends CashFlowChartWidget
{
    public const int TOP = 5;

    protected ?string $heading = '客戶集中度';

    public function getDescription(): string
    {
        $concentration = $this->analytics()->revenueByCustomer(self::TOP)['concentration'];

        return sprintf(
            '期間營收占比（前 5 大＋其他）。前 1 大 %.1f%%、前 3 大 %.1f%%，HHI %s（> 2,500 屬高度集中）。',
            $concentration['top1_share_pct'],
            $concentration['top3_share_pct'],
            number_format($concentration['hhi']),
        );
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function chartData(): array
    {
        $top = $this->analytics()->revenueByCustomer(self::TOP)['top'];
        $colors = ChartPalette::customers(array_column($top, 'customer'));

        return [
            'datasets' => [[
                'label' => '營收',
                'data' => array_column($top, 'amount'),
                'backgroundColor' => array_values($colors),
            ]],
            'labels' => array_column($top, 'customer'),
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->doughnutOptions();
    }
}
