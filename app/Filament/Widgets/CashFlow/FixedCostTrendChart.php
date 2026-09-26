<?php

namespace App\Filament\Widgets\CashFlow;

use App\Domain\Finance\CashFlowAnalytics;
use App\Enums\TransactionCategory;
use App\Filament\Support\ChartPalette;
use Filament\Support\RawJs;

/**
 * 金流分析: regular salary / insurance / tax / subscription / rent per month.
 */
class FixedCostTrendChart extends CashFlowChartWidget
{
    protected ?string $heading = '固定成本趨勢';

    protected ?string $description = '各類固定成本每月實付（不含一次性）；稅多為雙月營業稅，會隔月跳動。線往上代表成本基準需要調整。';

    protected function getType(): string
    {
        return 'line';
    }

    protected function chartData(): array
    {
        $trend = $this->analytics()->fixedCostTrend();

        return [
            'datasets' => array_map(fn (TransactionCategory $category): array => [
                'label' => $category->getLabel(),
                'data' => array_values(array_column($trend['months'], $category->value)),
                'borderColor' => ChartPalette::category($category),
                'backgroundColor' => ChartPalette::category($category),
            ], CashFlowAnalytics::FIXED_COST_CATEGORIES),
            'labels' => array_keys($trend['months']),
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->moneyOptions();
    }
}
