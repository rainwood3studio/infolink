<?php

namespace App\Filament\Widgets;

use App\Domain\Alerts\Rules\ServerDiskRule;
use App\Domain\Infra\ServerDiskReport;
use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Filament\Pages\ServerDisks;
use App\Models\Server;
use App\Models\SyncRun;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * 硬碟空間 cards. Only shown on the 硬碟空間 page, not the dashboard.
 */
class ServerDiskStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected int|array|null $columns = ['md' => 2, 'xl' => 4];

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $report = app(ServerDiskReport::class);
        $disks = $report->current();
        $fullest = $disks->first();
        $unreachable = $report->unreachable();
        $warning = $disks->where('used_percent', '>=', ServerDisks::WARNING_PERCENT);
        $danger = $warning->where('used_percent', '>=', ServerDisks::DANGER_PERCENT);
        $lastRun = SyncRun::latestFor(SyncJob::ServerDisks);

        return [
            Stat::make('機器', number_format(Server::query()->where('is_active', true)->count()))
                ->description(number_format($disks->count()).' 個分割區'),
            Stat::make('最滿', $fullest === null ? '—' : number_format($fullest['used_percent'], 1).'%')
                ->description($fullest === null ? '尚未收集' : "{$fullest['name']} {$fullest['mount']}，剩 ".ServerDiskRule::gigabytes($fullest['available_bytes']).' GB')
                ->color(match (true) {
                    $fullest === null => 'gray',
                    $fullest['used_percent'] >= ServerDisks::DANGER_PERCENT => 'danger',
                    $fullest['used_percent'] >= ServerDisks::WARNING_PERCENT => 'warning',
                    default => 'success',
                }),
            Stat::make('≥ '.ServerDisks::WARNING_PERCENT.'%', number_format($warning->count()))
                ->description($danger->isNotEmpty() ? '其中 '.$danger->count().' 個 ≥ '.ServerDisks::DANGER_PERCENT.'%' : '個分割區')
                ->color($danger->isNotEmpty() ? 'danger' : ($warning->isNotEmpty() ? 'warning' : 'success')),
            Stat::make('無法取得', number_format($unreachable->count()))
                ->description($unreachable->isNotEmpty()
                    ? $unreachable->map(fn (Server $server): string => $server->label())->implode('、')
                    : ($lastRun === null ? '尚未收集' : '上次收集 '.$lastRun->started_at->diffForHumans()
                        .($lastRun->status === SyncStatus::Failed ? '（失敗）' : '')))
                ->color($unreachable->isNotEmpty() || $lastRun?->status === SyncStatus::Failed ? 'danger' : 'gray'),
        ];
    }
}
