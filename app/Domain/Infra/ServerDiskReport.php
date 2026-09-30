<?php

namespace App\Domain\Infra;

use App\Models\Server;
use App\Models\ServerDiskSample;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read model for the 硬碟空間 page and the disk-usage alert rule: the newest sample per active server/filesystem,
 * its growth over the last {@see self::GROWTH_WINDOW_DAYS} days and a naive linear days-until-full.
 */
class ServerDiskReport
{
    public const int GROWTH_WINDOW_DAYS = 7;

    /** Growth is only projected when the baseline is at least this old, so a few hours of noise do not count. */
    public const int MIN_GROWTH_SPAN_HOURS = 24;

    /**
     * Newest filesystems of active servers, fullest first.
     *
     * @return Collection<string, array{key:string, server_id:int, instance_id:string, name:string, account:string, instance_type:?string, platform_name:?string, mount:string, filesystem:?string, fs_type:?string, size_bytes:int, used_bytes:int, available_bytes:int, used_percent:float, growth_bytes_per_day:?float, days_to_full:?int, collected_at:Carbon}>
     */
    public function current(): Collection
    {
        $servers = Server::query()->where('is_active', true)->whereNotNull('last_collected_at')->get()->keyBy('id');

        if ($servers->isEmpty()) {
            return collect();
        }

        $latest = ServerDiskSample::query()
            ->whereIn('server_id', $servers->keys())
            ->where(function ($query) use ($servers): void {
                foreach ($servers as $server) {
                    $query->orWhere(fn ($query) => $query->where('server_id', $server->id)->where('collected_at', $server->last_collected_at));
                }
            })
            ->get();

        $baselines = ServerDiskSample::query()
            ->whereIn('server_id', $servers->keys())
            ->where('collected_at', '>=', now()->subDays(self::GROWTH_WINDOW_DAYS))
            ->orderBy('collected_at')
            ->get(['server_id', 'mount', 'collected_at', 'used_bytes'])
            ->unique(fn (ServerDiskSample $sample): string => "{$sample->server_id}|{$sample->mount}")
            ->keyBy(fn (ServerDiskSample $sample): string => "{$sample->server_id}|{$sample->mount}");

        return $latest
            ->map(function (ServerDiskSample $sample) use ($servers, $baselines): array {
                $server = $servers[$sample->server_id];
                $key = "{$sample->server_id}|{$sample->mount}";
                $growth = self::growthPerDay($baselines[$key] ?? null, $sample);

                return [
                    'key' => $key,
                    'server_id' => $server->id,
                    'instance_id' => $server->instance_id,
                    'name' => $server->label(),
                    'account' => $server->account,
                    'instance_type' => $server->instance_type,
                    'platform_name' => $server->platform_name,
                    'mount' => $sample->mount,
                    'filesystem' => $sample->filesystem,
                    'fs_type' => $sample->fs_type,
                    'size_bytes' => $sample->size_bytes,
                    'used_bytes' => $sample->used_bytes,
                    'available_bytes' => $sample->available_bytes,
                    'used_percent' => $sample->used_percent,
                    'growth_bytes_per_day' => $growth,
                    'days_to_full' => $growth !== null && $growth > 0 ? (int) floor($sample->available_bytes / $growth) : null,
                    'collected_at' => $sample->collected_at,
                ];
            })
            ->sortByDesc('used_percent')
            ->keyBy('key');
    }

    /**
     * Active servers whose latest collection attempt failed or that never reported.
     *
     * @return Collection<int, Server>
     */
    public function unreachable(): Collection
    {
        return Server::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNotNull('last_error')->orWhereNull('last_collected_at'))
            ->orderBy('name')
            ->get();
    }

    /**
     * Daily peak usage (%) of the `$limit` fullest filesystems over the last `$days` days.
     *
     * @return array{labels: list<string>, series: list<array{label:string, data: list<?float>}>}
     */
    public function trend(int $days, int $limit): array
    {
        $top = $this->current()->take($limit);
        $dates = collect(range($days - 1, 0))->map(fn (int $ago): string => today()->subDays($ago)->toDateString());

        $peaks = ServerDiskSample::query()
            ->whereIn('server_id', $top->pluck('server_id')->unique())
            ->whereIn('mount', $top->pluck('mount')->unique())
            ->where('collected_at', '>=', today()->subDays($days - 1))
            ->get(['server_id', 'mount', 'collected_at', 'used_percent'])
            ->groupBy(fn (ServerDiskSample $sample): string => "{$sample->server_id}|{$sample->mount}")
            ->map(fn (Collection $samples): Collection => $samples
                ->groupBy(fn (ServerDiskSample $sample): string => $sample->collected_at->toDateString())
                ->map(fn (Collection $day): float => (float) $day->max('used_percent')));

        return [
            'labels' => $dates->map(fn (string $date): string => substr($date, 5, 2).'/'.substr($date, 8, 2))->all(),
            'series' => $top->map(fn (array $row): array => [
                'label' => $row['mount'] === '/' ? $row['name'] : "{$row['name']} {$row['mount']}",
                'data' => $dates->map(fn (string $date): ?float => $peaks[$row['key']][$date] ?? null)->all(),
            ])->values()->all(),
        ];
    }

    private static function growthPerDay(?ServerDiskSample $baseline, ServerDiskSample $latest): ?float
    {
        if ($baseline === null) {
            return null;
        }

        $hours = $baseline->collected_at->diffInHours($latest->collected_at);

        if ($hours < self::MIN_GROWTH_SPAN_HOURS) {
            return null;
        }

        return ($latest->used_bytes - $baseline->used_bytes) / ($hours / 24);
    }
}
