<?php

namespace App\Filament\Widgets\CashFlow;

use App\Enums\TransactionCategory;
use App\Filament\Support\ChartPalette;
use Filament\Support\RawJs;

/**
 * 金流分析: the period's withdrawals by category.
 */
class OutflowCompositionChart extends CashFlowChartWidget
{
    protected ?string $heading = '期間支出組成';

    protected ?string $description = '整段期間的支出依分類占比；滑過可看金額與百分比。';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function chartData(): array
    {
        $categories = $this->analytics()->outflowByCategory()['categories'];

        return [
            'datasets' => [[
                'label' => '支出',
                'data' => array_column($categories, 'amount'),
                'backgroundColor' => array_map(fn (array $category): string => ChartPalette::category(TransactionCategory::from($category['category'])), $categories),
            ]],
            'labels' => array_column($categories, 'label'),
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->doughnutOptions();
    }
}
