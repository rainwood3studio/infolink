<?php

namespace App\Filament\Widgets\CashFlow;

use App\Filament\Support\Money;
use App\Filament\Widgets\CashFlow\Concerns\ReadsCashFlowPeriod;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * 金流分析: headline numbers of the chosen period.
 */
class CashFlowKpiWidget extends StatsOverviewWidget
{
    use ReadsCashFlowPeriod;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected int|array|null $columns = ['md' => 3, '2xl' => 6];

    protected ?string $heading = '期間摘要';

    protected function getDescription(): string
    {
        $period = $this->analytics()->period();

        if ($period['first_date'] === null) {
            return '這段期間沒有逐筆交易資料。';
        }

        return sprintf('%s ～ %s，共 %d 個有資料的月份；流入含營收、代墊回收等所有入帳。', $period['first_date'], $period['last_date'], count($period['months']));
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $summary = $this->analytics()->summary();
        $concentration = $summary['revenue_concentration'];

        return [
            Stat::make('總流入', $this->tenThousands($summary['total_inflow']))
                ->description('月均 '.Money::format($summary['avg_monthly_inflow'])),
            Stat::make('總流出', $this->tenThousands($summary['total_outflow']))
                ->description('其中一次性 '.Money::format($summary['one_off_outflow'])),
            Stat::make('淨現金流', $this->tenThousands($summary['net']))
                ->description($summary['closing_balance'] === null ? '—' : '期末餘額 '.Money::format($summary['closing_balance']))
                ->color($summary['net'] < 0 ? 'danger' : 'success'),
            Stat::make('平均常態月支出', $this->tenThousands($summary['avg_monthly_regular_outflow']))
                ->description('不含一次性與代墊'),
            Stat::make('前三大客戶佔營收', number_format($concentration['top3_share_pct'], 1).'%')
                ->description($concentration['top1_customer'] === null ? '期間沒有營收' : sprintf('最大 %s %.1f%%，HHI %s', $concentration['top1_customer'], $concentration['top1_share_pct'], number_format($concentration['hhi'])))
                ->color($concentration['top3_share_pct'] >= 80 ? 'warning' : 'gray'),
            Stat::make('代墊未回收', $this->tenThousands($summary['reimbursement_outstanding']))
                ->description('累計代墊支出 − 已回收')
                ->color($summary['reimbursement_outstanding'] > 0 ? 'warning' : 'success'),
        ];
    }

    protected function tenThousands(int $amount): string
    {
        return number_format($amount / 10_000, 1).' 萬';
    }
}
