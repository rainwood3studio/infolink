<?php

namespace App\Filament\Widgets;

use App\Models\CashForecast;
use Filament\Widgets\ChartWidget;

/**
 * 現金與推估: how the saved forecasts' year-end and minimum balances moved from one snapshot to the next.
 */
class ForecastVersionsChart extends ChartWidget
{
    public const int LIMIT = 60;

    protected static bool $isDiscovered = false;

    protected ?string $heading = '推估版本比較';

    protected ?string $description = '每次儲存的推估快照：年底餘額與推估期間最低餘額。';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    protected ?string $pollingInterval = null;

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $snapshots = CashForecast::query()
            ->latest('id')
            ->limit(self::LIMIT)
            ->get(['id', 'as_of', 'created_at', 'year_end_balance', 'min_balance'])
            ->reverse()
            ->values();

        return [
            'datasets' => [
                [
                    'label' => '推估年底',
                    'data' => $snapshots->pluck('year_end_balance')->all(),
                    'borderColor' => '#2563eb',
                    'backgroundColor' => '#2563eb',
                ],
                [
                    'label' => '推估最低',
                    'data' => $snapshots->pluck('min_balance')->all(),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => '#f59e0b',
                ],
            ],
            'labels' => $snapshots->map(fn (CashForecast $snapshot): string => $snapshot->created_at->format('m/d H:i'))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return ['interaction' => ['mode' => 'index', 'intersect' => false]];
    }
}
