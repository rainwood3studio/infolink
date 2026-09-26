<?php

namespace App\Console\Commands;

use App\Domain\Delivery\DeliveryMetrics;
use App\Domain\Delivery\RedmineSnapshotter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

#[Signature('infolink:snapshot-redmine
    {--date= : Snapshot date (Y-m-d, default today); the mirror only knows current state}
    {--rebuild-history= : Approximate daily open stock from this date (Y-m-d) up to yesterday, then stop}
    {--week= : ISO week (e.g. 2026-W36): print its stats and record the weekly metrics, then stop}')]
#[Description('Snapshot open Redmine issues and record the delivery metrics, then evaluate the alert rules')]
class SnapshotRedmine extends Command
{
    public function handle(RedmineSnapshotter $snapshotter, DeliveryMetrics $deliveryMetrics): int
    {
        if ($this->option('week') !== null) {
            return $this->reportWeek($deliveryMetrics, (string) $this->option('week'));
        }

        try {
            $rebuildFrom = $this->option('rebuild-history') !== null ? $this->parseDate((string) $this->option('rebuild-history')) : null;
            $date = $this->option('date') !== null ? $this->parseDate((string) $this->option('date')) : CarbonImmutable::today();
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage().' Use Y-m-d, e.g. 2026-09-26.');

            return self::INVALID;
        }

        if ($rebuildFrom !== null) {
            $days = $snapshotter->rebuildHistory($rebuildFrom);
            $this->components->info(sprintf('Reconstructed %d day(s) of open stock from %s.', $days, $rebuildFrom->toDateString()));

            return self::SUCCESS;
        }

        $rows = $snapshotter->snapshot($date);
        $deliveryMetrics->recordDaily($date);
        $deliveryMetrics->recordWeekly($date);

        $this->components->info(sprintf('Snapshot %s: %d row(s); daily and weekly delivery metrics recorded.', $date->toDateString(), $rows));

        $this->call('infolink:evaluate-rules');

        return self::SUCCESS;
    }

    protected function parseDate(string $value): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            $date = null;
        }

        if (! $date instanceof CarbonImmutable || $date->toDateString() !== $value) {
            throw new InvalidArgumentException("Invalid date [{$value}].");
        }

        return $date;
    }

    protected function reportWeek(DeliveryMetrics $deliveryMetrics, string $week): int
    {
        if (preg_match('/^(\d{4})-?W(\d{1,2})$/i', $week, $matches) !== 1) {
            $this->components->error('Invalid week; use e.g. 2026-W36.');

            return self::INVALID;
        }

        $monday = CarbonImmutable::today()->setISODate((int) $matches[1], (int) $matches[2])->startOfDay();
        $stats = $deliveryMetrics->weekStats($monday);
        $deliveryMetrics->recordWeekly($monday);

        $this->components->info(sprintf('%s (%s ~ %s)', $week, $stats['week_start'], $stats['week_end']));
        $this->table(['Metric', 'Value'], [
            ['created', $stats['created']],
            ['closed', $stats['closed']],
            ['net_flow', $stats['net_flow']],
            ['wenhao_throughput', $stats['wenhao_throughput']],
            ['advanced_to_verify', $stats['advanced_to_verify']],
            ['hours_logged', $stats['hours_logged']],
            ['created → acceptor', $stats['created_assigned_to_acceptor']],
            ['inflow_to_wenhao_ratio', $stats['inflow_to_wenhao_ratio'] ?? '—'],
        ]);

        foreach (['created_by_project', 'closed_by_project', 'advanced_to_verify_by_assignee', 'hours_by_user'] as $breakdown) {
            if ($stats[$breakdown] !== []) {
                $this->line(sprintf('  %s: %s', $breakdown, collect($stats[$breakdown])->map(fn (int|float $value, string $name): string => "{$name} {$value}")->implode('、')));
            }
        }

        return self::SUCCESS;
    }
}
