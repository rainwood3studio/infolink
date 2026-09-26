<?php

namespace App\Filament\Widgets\CashFlow;

use App\Filament\Support\ChartPalette;
use Filament\Support\RawJs;

/**
 * 金流分析: outflow split into regular / one-off / reimbursement, next to reimbursement repayments.
 */
class OutflowTypeChart extends CashFlowChartWidget
{
    protected ?string $heading = '常態 vs 一次性 vs 代墊';

    protected ?string $description = '左柱疊加當月支出：常態（可推估的固定開銷）、一次性、代墊；右柱為代墊回收。常態部分才是估可撐月數的基礎。';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function chartData(): array
    {
        $months = $this->analytics()->monthlyFlows();

        return [
            'datasets' => [
                ['label' => '常態支出', 'data' => array_column($months, 'regular_outflow'), 'backgroundColor' => ChartPalette::REGULAR, 'stack' => 'out'],
                ['label' => '一次性支出', 'data' => array_column($months, 'one_off_outflow'), 'backgroundColor' => ChartPalette::ONE_OFF, 'stack' => 'out'],
                ['label' => '代墊支出', 'data' => array_column($months, 'reimbursement_out'), 'backgroundColor' => ChartPalette::REIMBURSEMENT_OUT, 'stack' => 'out'],
                ['label' => '代墊回收', 'data' => array_column($months, 'reimbursement_in'), 'backgroundColor' => ChartPalette::REIMBURSEMENT_IN, 'stack' => 'in'],
            ],
            'labels' => array_column($months, 'month'),
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->moneyOptions(stacked: true);
    }
}
