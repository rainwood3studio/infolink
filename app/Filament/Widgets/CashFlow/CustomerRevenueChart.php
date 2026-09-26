<?php

namespace App\Filament\Widgets\CashFlow;

use App\Domain\Finance\CashFlowAnalytics;
use App\Filament\Support\ChartPalette;
use Filament\Support\RawJs;

/**
 * 金流分析: monthly revenue deposits stacked by customer (top customers of the period + 其他).
 */
class CustomerRevenueChart extends CashFlowChartWidget
{
    public const int TOP = 6;

    protected ?string $heading = '客戶營收';

    protected ?string $description = '每月營收入帳（含稅）依客戶疊加，只列期間前 6 大，其餘併入「其他」；看收入是否集中在少數月份與客戶。';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function chartData(): array
    {
        $revenue = $this->analytics()->revenueByCustomer(self::TOP);
        $top = array_values(array_filter(array_column($revenue['top'], 'customer'), fn (string $customer): bool => $customer !== CashFlowAnalytics::OTHERS));
        $colors = ChartPalette::customers([...$top, CashFlowAnalytics::OTHERS]);
        $datasets = array_map(fn (string $customer): array => [
            'label' => $customer,
            'data' => array_values(array_map(fn (array $month): int => $month[$customer] ?? 0, $revenue['months'])),
            'backgroundColor' => $colors[$customer],
        ], $top);

        $others = array_values(array_map(fn (array $month): int => array_sum($month) - array_sum(array_intersect_key($month, array_flip($top))), $revenue['months']));

        if (array_sum($others) > 0) {
            $datasets[] = ['label' => CashFlowAnalytics::OTHERS, 'data' => $others, 'backgroundColor' => $colors[CashFlowAnalytics::OTHERS]];
        }

        return ['datasets' => $datasets, 'labels' => array_keys($revenue['months'])];
    }

    protected function getOptions(): RawJs
    {
        return $this->moneyOptions(stacked: true);
    }
}
