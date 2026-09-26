<?php

namespace App\Filament\Widgets;

use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Models\BankTransaction;
use App\Models\SyncRun;
use Filament\Widgets\Widget;

/**
 * 資料新鮮度 bar at the top of the dashboard: when each data source last updated successfully.
 */
class DataFreshnessWidget extends Widget
{
    protected string $view = 'filament.widgets.data-freshness-widget';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return list<array{label:string, value:string, color:string, icon:?string, hint:?string}>
     */
    public function getSources(): array
    {
        return [
            $this->redmine(),
            $this->bank(),
            ['label' => '今日簡報', 'value' => '尚未啟用', 'color' => 'gray', 'icon' => null, 'hint' => null],
        ];
    }

    /**
     * @return array{label:string, value:string, color:string, icon:?string, hint:?string}
     */
    protected function redmine(): array
    {
        $latest = SyncRun::latestFor(SyncJob::RedmineIssues);
        $lastOk = $latest?->status === SyncStatus::Ok ? $latest : SyncRun::latestFor(SyncJob::RedmineIssues, SyncStatus::Ok);
        $source = ['label' => 'Redmine', 'value' => '尚未同步', 'color' => 'gray', 'icon' => null, 'hint' => null];

        if ($latest?->status === SyncStatus::Failed) {
            return [
                ...$source,
                'value' => '同步失敗 '.$latest->started_at->diffForHumans(),
                'color' => 'danger',
                'icon' => 'heroicon-m-x-circle',
                'hint' => $lastOk ? '上次成功 '.($lastOk->finished_at ?? $lastOk->started_at)->diffForHumans() : '從未成功',
            ];
        }

        if ($lastOk !== null) {
            return [
                ...$source,
                'value' => ($lastOk->finished_at ?? $lastOk->started_at)->diffForHumans(),
                'color' => 'success',
                'icon' => 'heroicon-m-check-circle',
            ];
        }

        return $source;
    }

    /**
     * @return array{label:string, value:string, color:string, icon:?string, hint:?string}
     */
    protected function bank(): array
    {
        $latest = BankTransaction::query()->max('txn_date');

        return [
            'label' => '銀行明細',
            'value' => $latest === null ? '尚未匯入' : '至 '.date('m/d', strtotime((string) $latest)),
            'color' => $latest === null ? 'gray' : 'primary',
            'icon' => null,
            'hint' => null,
        ];
    }
}
