<?php

use App\Domain\Infra\DiskUsageParser;
use App\Domain\Infra\ServerDiskCollector;
use App\Domain\Infra\ServerDiskReport;
use App\Domain\Infra\SsmGateway;
use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Models\Server;
use App\Models\ServerDiskSample;

const DF_OUTPUT = <<<'TXT'
Filesystem      Type     1-blocks        Used   Available Capacity Mounted on
/dev/root       ext4  31146531840 27256365056  3874349056      88% /
/dev/nvme1n1    xfs  107374182400 10737418240 96636764160      10% /mnt/data files
/dev/nvme0n1p16 ext4    933457920    99258368   769032192      12% /bo
TXT;

function node(string $id, array $overrides = []): array
{
    return [
        'instance_id' => $id,
        'computer_name' => "host-{$id}",
        'platform' => 'Linux',
        'platform_name' => 'Ubuntu',
        'ping_status' => 'Online',
        'name' => "name-{$id}",
        'instance_type' => 't3.small',
        ...$overrides,
    ];
}

beforeEach(function () {
    config(['services.ssm.targets' => 'default@ap-northeast-1']);
});

test('parses df output the way df reports usage, keeping mount points with spaces', function () {
    $disks = (new DiskUsageParser)->parse('Linux', DF_OUTPUT);

    expect($disks)->toHaveCount(3)
        ->and($disks[0])->toMatchArray(['mount' => '/', 'fs_type' => 'ext4', 'available_bytes' => 3874349056, 'used_percent' => 87.55])
        ->and($disks[1]['mount'])->toBe('/mnt/data files');
});

test('parses windows logical disks', function () {
    $disks = (new DiskUsageParser)->parse('Windows', "C:|NTFS|100|25\r\nD:||200|200\r\n");

    expect($disks)->toBe([
        ['mount' => 'C:', 'filesystem' => 'C:', 'fs_type' => 'NTFS', 'size_bytes' => 100, 'used_bytes' => 75, 'available_bytes' => 25, 'used_percent' => 75.0],
        ['mount' => 'D:', 'filesystem' => 'D:', 'fs_type' => null, 'size_bytes' => 200, 'used_bytes' => 0, 'available_bytes' => 200, 'used_percent' => 0.0],
    ]);
});

test('stores samples for reachable servers and records why the others are missing', function () {
    Server::factory()->create(['instance_id' => 'i-gone', 'account' => 'default', 'region' => 'ap-northeast-1']);

    $this->mock(SsmGateway::class, function ($mock) {
        $mock->shouldReceive('targets')->andReturn([['account' => 'default', 'region' => 'ap-northeast-1']]);
        $mock->shouldReceive('instances')->with('default', 'ap-northeast-1')->andReturn([
            node('i-ok'),
            node('i-fail'),
            node('i-offline', ['ping_status' => 'ConnectionLost']),
        ]);
        $mock->shouldReceive('runDiskCommand')
            ->once()
            ->with('default', 'ap-northeast-1', 'Linux', ['i-ok', 'i-fail'])
            ->andReturn([
                'i-ok' => ['status' => 'Success', 'output' => DF_OUTPUT, 'error' => ''],
                'i-fail' => ['status' => 'Failed', 'output' => '', 'error' => 'boom'],
            ]);
    });

    $run = app(ServerDiskCollector::class)->collect();

    expect($run)
        ->job->toBe(SyncJob::ServerDisks)
        ->status->toBe(SyncStatus::Ok)
        ->stats->toBe(['servers' => 3, 'collected' => 1, 'unreachable' => 2, 'samples' => 3])
        ->and(Server::firstWhere('instance_id', 'i-ok'))
        ->name->toBe('name-i-ok')
        ->last_error->toBeNull()
        ->last_collected_at->not->toBeNull()
        ->and(Server::firstWhere('instance_id', 'i-fail')->last_error)->toBe('Failed boom')
        ->and(Server::firstWhere('instance_id', 'i-offline')->last_error)->toBe('SSM 狀態 ConnectionLost')
        ->and(Server::firstWhere('instance_id', 'i-gone')->is_active)->toBeFalse()
        ->and(ServerDiskSample::count())->toBe(3);
});

test('a failing account fails the run without touching its servers', function () {
    $this->mock(SsmGateway::class, function ($mock) {
        $mock->shouldReceive('targets')->andReturn([['account' => 'default', 'region' => 'ap-northeast-1']]);
        $mock->shouldReceive('instances')->andThrow(new RuntimeException('The security token included in the request is invalid.'));
    });

    $run = app(ServerDiskCollector::class)->collect();

    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($run->error)->toContain('default@ap-northeast-1: The security token');
});

test('the report projects days until full from the growth over the last week', function () {
    $this->travelTo(now()->startOfHour());
    $server = Server::factory()->create(['last_collected_at' => now()]);
    $gb = 1024 ** 3;
    $sample = fn (int $daysAgo, int $usedGb) => ServerDiskSample::factory()->for($server)->create([
        'collected_at' => now()->subDays($daysAgo),
        'size_bytes' => 100 * $gb,
        'used_bytes' => $usedGb * $gb,
        'available_bytes' => (100 - $usedGb) * $gb,
        'used_percent' => $usedGb,
    ]);
    $sample(10, 10);
    $sample(6, 60);
    $sample(0, 72);

    $disk = app(ServerDiskReport::class)->current()->sole();

    expect($disk)
        ->used_percent->toBe(72.0)
        ->growth_bytes_per_day->toBe(2.0 * $gb)
        ->days_to_full->toBe(14);
});
