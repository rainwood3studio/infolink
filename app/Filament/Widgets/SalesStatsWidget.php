<?php

namespace App\Filament\Widgets;

use App\Domain\Sales\DealService;
use App\Filament\Resources\Deals\DealResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * 業務 cards on the dashboard, from DealService::pipeline(); each links to the board.
 */
class SalesStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 7;

    protected ?string $heading = '業務';

    protected ?string $pollingInterval = null;

    protected int|array|null $columns = ['md' => 3];

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $pipeline = app(DealService::class)->pipeline();
        $boardUrl = DealResource::getUrl('board');
        $openCount = $pipeline['open_count'];
        $openAmount = $pipeline['amount_total'];
        $noNextAction = count($pipeline['no_next_action']);

        return [
            Stat::make('加權業務機會', number_format($pipeline['weighted_total'] / 10_000, 1).' 萬')
                ->description('金額 × 成交機率，未稅')
                ->url($boardUrl),
            Stat::make('進行中機會數', number_format($openCount))
                ->description('合計 '.number_format($openAmount / 10_000, 1).' 萬（未稅）')
                ->url($boardUrl),
            Stat::make('沒有下一步的機會', number_format($noNextAction))
                ->description($noNextAction > 0 ? '需要排下一步' : '每個機會都有下一步')
                ->color($noNextAction > 0 ? 'warning' : 'success')
                ->url($boardUrl),
        ];
    }
}
