<?php

namespace App\Filament\Widgets;

use App\Domain\Delivery\DeliverySummary;
use Filament\Widgets\ChartWidget;

/**
 * 交付 backlog trend: open issues plus 驗證中 split by acceptor, and stalled > 90 days.
 * Reconstructed (back-filled) days only know the open count, so the other lines are blank there.
 */
class DeliveryTrendChart extends ChartWidget
{
    public const int DAYS = 90;

    protected static ?int $sort = 4;

    protected ?string $heading = '交付：存量＋驗證中（文豪／他人）趨勢';

    protected ?string $description = '近 90 天；回推的日期只有未結案數。';

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
        $trend = app(DeliverySummary::class)->trend(self::DAYS);

        $series = fn (string $key): array => array_map(
            fn (array $day): ?int => $day['reconstructed'] ? null : $day[$key],
            $trend,
        );

        return [
            'datasets' => [
                [
                    'label' => '未結案',
                    'data' => array_column($trend, 'open'),
                    'borderColor' => '#2563eb',
                    'backgroundColor' => '#2563eb',
                ],
                [
                    'label' => '驗證中－文豪',
                    'data' => $series('verifying_acceptor'),
                    'borderColor' => '#16a34a',
                    'backgroundColor' => '#16a34a',
                ],
                [
                    'label' => '驗證中－他人',
                    'data' => $series('verifying_others'),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => '#f59e0b',
                ],
                [
                    'label' => '停滯 > 90 天',
                    'data' => $series('stalled_90d'),
                    'borderColor' => '#dc2626',
                    'backgroundColor' => '#dc2626',
                ],
            ],
            'labels' => array_map(fn (array $day): string => substr($day['date'], 5, 2).'/'.substr($day['date'], 8, 2), $trend),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'elements' => ['point' => ['radius' => 0]],
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => ['y' => ['beginAtZero' => true]],
        ];
    }
}
