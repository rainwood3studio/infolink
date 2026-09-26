<?php

namespace App\Filament\Widgets\CashFlow;

use App\Enums\TransactionCategory;
use App\Filament\Support\ChartPalette;
use Filament\Support\RawJs;

/**
 * 金流分析: monthly withdrawals stacked by category.
 */
class OutflowStructureChart extends CashFlowChartWidget
{
    protected ?string $heading = '支出結構';

    protected ?string $description = '每月支出依分類疊加（含一次性與代墊）；看哪一類在推高月支出，薪資與勞健保通常是主體。';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function chartData(): array
    {
        $outflow = $this->analytics()->outflowByCategory();

        return [
            'datasets' => array_map(fn (array $category): array => [
                'label' => $category['label'],
                'data' => array_values(array_column($outflow['months'], $category['category'])),
                'backgroundColor' => ChartPalette::category(TransactionCategory::from($category['category'])),
            ], $outflow['categories']),
            'labels' => array_keys($outflow['months']),
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->moneyOptions(stacked: true);
    }
}
