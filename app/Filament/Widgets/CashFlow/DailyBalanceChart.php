<?php

namespace App\Filament\Widgets\CashFlow;

use App\Filament\Support\ChartPalette;
use Filament\Support\RawJs;

/**
 * 金流分析: end-of-day balance with each month's lowest point marked.
 */
class DailyBalanceChart extends CashFlowChartWidget
{
    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = '每日餘額';

    protected ?string $description = '線為每日日終餘額（無交易日沿用前一日）；紅點為各月最低點（依對帳單順序，含日內低點，多落在發薪與繳勞健保日）——現金壓力看這裡，不是看月底。';

    protected function getType(): string
    {
        return 'line';
    }

    protected function chartData(): array
    {
        $daily = $this->analytics()->dailyBalance();
        $lows = array_column($this->analytics()->monthlyFlows(), 'min_balance', 'min_balance_date');

        return [
            'datasets' => [
                [
                    'label' => '月內最低',
                    'data' => array_map(fn (array $day): ?int => $lows[$day['date']] ?? null, $daily),
                    'borderColor' => ChartPalette::LOW_POINT,
                    'backgroundColor' => ChartPalette::LOW_POINT,
                    'showLine' => false,
                    'pointRadius' => 5,
                    'pointHoverRadius' => 7,
                    'order' => 0,
                ],
                [
                    'label' => '日終餘額',
                    'data' => array_column($daily, 'balance'),
                    'borderColor' => ChartPalette::BALANCE,
                    'backgroundColor' => ChartPalette::BALANCE,
                    'pointRadius' => 0,
                    'borderWidth' => 2,
                    'stepped' => true,
                    'order' => 1,
                ],
            ],
            'labels' => array_column($daily, 'date'),
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->moneyOptions();
    }
}
