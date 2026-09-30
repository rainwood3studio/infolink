<?php

namespace App\Filament\Widgets;

use App\Domain\Infra\ServerDiskReport;
use Filament\Widgets\ChartWidget;

/**
 * Daily peak usage of the fullest filesystems over the last 30 days. Only shown on the 硬碟空間 page.
 */
class ServerDiskTrendChart extends ChartWidget
{
    public const int DAYS = 30;

    public const int LIMIT = 6;

    /** Categorical series colors, in order. */
    public const array COLORS = ['#dc2626', '#f59e0b', '#2563eb', '#16a34a', '#9333ea', '#0891b2'];

    protected static bool $isDiscovered = false;

    protected ?string $heading = '最滿的 6 個分割區：使用率趨勢';

    protected ?string $description = '近 30 天，每日最高值（%）。';

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
        $trend = app(ServerDiskReport::class)->trend(self::DAYS, self::LIMIT);

        return [
            'datasets' => array_map(fn (array $series, int $index): array => [
                'label' => $series['label'],
                'data' => $series['data'],
                'borderColor' => self::COLORS[$index % count(self::COLORS)],
                'backgroundColor' => self::COLORS[$index % count(self::COLORS)],
                'spanGaps' => true,
            ], $trend['series'], array_keys($trend['series'])),
            'labels' => $trend['labels'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'elements' => ['point' => ['radius' => 2]],
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => ['y' => ['min' => 0, 'max' => 100]],
        ];
    }
}
