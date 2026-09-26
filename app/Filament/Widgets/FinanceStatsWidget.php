<?php

namespace App\Filament\Widgets;

use App\Domain\Finance\FinancePosition;
use App\Filament\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Pinned finance cards. Computed live from transactions and receivables so edits show up immediately.
 */
class FinanceStatsWidget extends StatsOverviewWidget
{
    /** Minimum forecast balance within 90 days below which the card turns red (see alert rules in docs/04). */
    public const int CASH_LOW_THRESHOLD = 500_000;

    protected static ?int $sort = 1;

    protected ?string $heading = '財務';

    protected int|array|null $columns = ['md' => 3, 'xl' => 6];

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $position = app(FinancePosition::class)->current();

        if ($position === null) {
            return [Stat::make('現金餘額', '—')->description('尚未匯入銀行交易')];
        }

        $runwayMonths = $position['runway_months'];
        $forecast = $position['forecast'];

        return [
            Stat::make('現金餘額', $this->tenThousands($position['balance']))
                ->description($position['as_of']->format('m/d').' 銀行餘額'),
            Stat::make('可撐月數', $runwayMonths === null ? '—' : number_format($runwayMonths, 1))
                ->description($position['monthly_cost'] ? '月成本 '.Money::format($position['monthly_cost']) : '尚未設定月成本')
                ->color(match (true) {
                    $runwayMonths === null => 'gray',
                    $runwayMonths < 2 => 'danger',
                    $runwayMonths < 3 => 'warning',
                    default => 'success',
                }),
            Stat::make('90 天最低', $this->tenThousands($position['forecast_min_90d']))
                ->description('推估，只計高確定性應收')
                ->color($position['forecast_min_90d'] < self::CASH_LOW_THRESHOLD ? 'danger' : 'success'),
            Stat::make('未收應收（含稅）', $this->tenThousands($position['outstanding_taxed']))
                ->description('低確定性另有 '.$this->tenThousands($position['low_confidence_taxed'])),
            Stat::make('逾期應收', $this->tenThousands($position['overdue_taxed']))
                ->description($position['overdue_taxed'] > 0 ? '需要追款' : '沒有逾期')
                ->color($position['overdue_taxed'] > 0 ? 'danger' : 'success'),
            Stat::make('推估年底', $this->tenThousands($forecast->yearEndBalance))
                ->description('最低 '.$this->tenThousands($forecast->minBalance).'（'.$forecast->minBalanceMonth.'）'),
        ];
    }

    protected function tenThousands(int $amount): string
    {
        return number_format($amount / 10_000, 1).' 萬';
    }
}
