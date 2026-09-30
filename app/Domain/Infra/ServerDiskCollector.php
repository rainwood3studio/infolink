<?php

namespace App\Domain\Infra;

use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Models\Server;
use App\Models\ServerDiskSample;
use App\Models\SyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Collects disk usage from every SSM managed node in the configured accounts (`services.ssm.targets`) by running a
 * read-only `df` through Run Command, and stores one {@see ServerDiskSample} per filesystem.
 *
 * Every call records a SyncRun (job `server_disks`). A node that is offline or whose command fails keeps its previous
 * samples and gets `last_error`; the run fails only when an account cannot be listed at all.
 */
class ServerDiskCollector
{
    /** Samples older than this are pruned after each run. */
    public const int RETENTION_DAYS = 180;

    public function __construct(
        private readonly SsmGateway $gateway,
        private readonly DiskUsageParser $parser,
    ) {}

    public function collect(): SyncRun
    {
        $run = SyncRun::create(['job' => SyncJob::ServerDisks, 'started_at' => now(), 'status' => SyncStatus::Running]);
        $stats = ['servers' => 0, 'collected' => 0, 'unreachable' => 0, 'samples' => 0];
        $errors = [];
        $collectedAt = now()->startOfSecond();

        foreach ($this->gateway->targets() as $target) {
            try {
                $this->collectTarget($target['account'], $target['region'], $collectedAt, $stats);
            } catch (Throwable $exception) {
                Log::error('Server disk collection failed.', ['target' => $target, 'exception' => $exception]);
                $errors[] = "{$target['account']}@{$target['region']}: {$exception->getMessage()}";
            }
        }

        ServerDiskSample::query()->where('collected_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();

        $run->update([
            'status' => $errors === [] ? SyncStatus::Ok : SyncStatus::Failed,
            'finished_at' => now(),
            'stats' => $stats,
            'error' => $errors === [] ? null : Str::limit(implode("\n", $errors), 2000),
        ]);

        return $run->refresh();
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function collectTarget(string $account, string $region, Carbon $collectedAt, array &$stats): void
    {
        $nodes = collect($this->gateway->instances($account, $region));
        $stats['servers'] += $nodes->count();

        $servers = $nodes->mapWithKeys(fn (array $node): array => [$node['instance_id'] => Server::query()->updateOrCreate(
            ['instance_id' => $node['instance_id']],
            [
                ...collect($node)->except('instance_id')->all(),
                'account' => $account,
                'region' => $region,
                'is_active' => true,
                'last_seen_at' => now(),
            ],
        )]);

        Server::query()
            ->where('account', $account)
            ->where('region', $region)
            ->whereNotIn('instance_id', $servers->keys())
            ->update(['is_active' => false]);

        $reachable = $servers->filter(fn (Server $server): bool => $server->ping_status === 'Online' && isset(SsmGateway::DISK_COMMANDS[$server->platform]));

        $servers->diffKeys($reachable)->each(function (Server $server) use (&$stats): void {
            $server->update(['last_error' => isset(SsmGateway::DISK_COMMANDS[$server->platform])
                ? "SSM 狀態 {$server->ping_status}"
                : "不支援的平台 {$server->platform}"]);
            $stats['unreachable']++;
        });

        foreach ($reachable->groupBy('platform', preserveKeys: true) as $platform => $group) {
            $results = $this->gateway->runDiskCommand($account, $region, $platform, $group->keys()->all());

            foreach ($group as $instanceId => $server) {
                $result = $results[$instanceId] ?? ['status' => 'Missing', 'output' => '', 'error' => '沒有回應'];
                $disks = $result['status'] === 'Success' ? $this->parser->parse($platform, $result['output']) : [];

                if ($disks === []) {
                    $server->update(['last_error' => trim("{$result['status']} {$result['error']}")]);
                    $stats['unreachable']++;

                    continue;
                }

                $server->diskSamples()->createMany(array_map(fn (array $disk): array => [...$disk, 'collected_at' => $collectedAt], $disks));
                $server->update(['last_collected_at' => $collectedAt, 'last_error' => null]);
                $stats['collected']++;
                $stats['samples'] += count($disks);
            }
        }
    }
}
