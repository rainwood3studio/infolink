<?php

namespace App\Filament\Widgets;

use App\Enums\Confidence;
use App\Models\Receivable;
use Filament\Widgets\ChartWidget;

/**
 * Outstanding project receivables (taxed) by expected month, stacked by confidence. Overdue ones land in the current month.
 */
class ReceivableScheduleChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected ?string $heading = '應收時程（含稅）';

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $currentMonth = today()->format('Y-m');
        $receivables = Receivable::query()->outstanding()->where('is_recurring', false)->get();
        $months = $receivables
            ->map(fn (Receivable $receivable): string => max($currentMonth, $receivable->expected_on->format('Y-m')))
            ->unique()
            ->sort()
            ->values();

        $sumFor = fn (Confidence $confidence): array => $months->map(fn (string $month): int => (int) $receivables
            ->filter(fn (Receivable $receivable): bool => $receivable->confidence === $confidence
                && max($currentMonth, $receivable->expected_on->format('Y-m')) === $month)
            ->sum('amount_taxed'))->all();

        return [
            'datasets' => [
                ['label' => '高確定性', 'data' => $sumFor(Confidence::High), 'backgroundColor' => '#16a34a', 'stack' => 'ar'],
                ['label' => '低確定性', 'data' => $sumFor(Confidence::Low), 'backgroundColor' => '#f59e0b', 'stack' => 'ar'],
            ],
            'labels' => $months->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return ['scales' => ['x' => ['stacked' => true], 'y' => ['stacked' => true]]];
    }
}
