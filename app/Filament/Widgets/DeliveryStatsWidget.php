<?php

namespace App\Filament\Widgets;

use App\Domain\Delivery\DeliverySummary;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * 交付總覽 cards. Only shown on the 交付總覽 page, not the dashboard.
 */
class DeliveryStatsWidget extends StatsOverviewWidget
{
    /** 驗證中 items sitting with someone other than the acceptor above which the card turns red (alert rule 脫離驗收流程). */
    public const int VERIFYING_OTHERS_THRESHOLD = 20;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected int|array|null $columns = ['md' => 3, 'xl' => 6];

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $summary = app(DeliverySummary::class)->current();
        $verifying = $summary['verifying'];

        return [
            Stat::make('未結案', number_format($summary['open']))
                ->description('驗證中合計 '.number_format($verifying['acceptor'] + $verifying['others'] + $verifying['unassigned'])),
            Stat::make('驗證中－文豪隊列', number_format($verifying['acceptor']))
                ->description('等最終驗收')
                ->color('info'),
            Stat::make('驗證中－非文豪', number_format($verifying['others']))
                ->description($verifying['unassigned'] > 0 ? '另有 '.$verifying['unassigned'].' 筆未指派' : '未走驗收流程')
                ->color($verifying['others'] > self::VERIFYING_OTHERS_THRESHOLD ? 'danger' : 'warning'),
            Stat::make('停滯 > 90 天', number_format($summary['stalled_90d']))
                ->description('> 30 天：'.number_format($summary['stalled_30d']))
                ->color($summary['stalled_90d'] > 0 ? 'warning' : 'success'),
            Stat::make('逾期', number_format($summary['overdue']))
                ->description('超過預計完成日')
                ->color($summary['overdue'] > 0 ? 'danger' : 'success'),
            Stat::make('未指派', number_format($summary['unassigned']))
                ->description('未結案且沒有被分派者')
                ->color($summary['unassigned'] > 0 ? 'warning' : 'success'),
        ];
    }
}
