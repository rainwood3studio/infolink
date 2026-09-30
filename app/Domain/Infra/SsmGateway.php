<?php

namespace App\Domain\Infra;

use Aws\Ec2\Ec2Client;
use Aws\Ssm\SsmClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Thin wrapper over the AWS SDK for the calls the disk collector needs; credentials come from the named profile in
 * the shared credentials file (in docker: `~/.aws` mounted read-only at `/aws`).
 */
class SsmGateway
{
    /** Invocation statuses that will not change any more. */
    public const array TERMINAL_STATUSES = ['Success', 'Failed', 'TimedOut', 'Cancelled', 'Undeliverable', 'Terminated', 'DeliveryTimedOut', 'ExecutionTimedOut', 'AccessDenied'];

    /** SendCommand accepts at most this many instance ids per call. */
    public const int MAX_TARGETS_PER_COMMAND = 50;

    public const int COMMAND_WAIT_SECONDS = 90;

    /**
     * Read-only disk usage commands per SSM platform type. Pseudo/overlay/boot filesystems are excluded on Linux.
     */
    public const array DISK_COMMANDS = [
        'Linux' => [
            'document' => 'AWS-RunShellScript',
            'command' => 'df -PT -B1 -x tmpfs -x devtmpfs -x squashfs -x overlay -x efivarfs -x vfat -x fuse.lxcfs 2>/dev/null; true',
        ],
        'Windows' => [
            'document' => 'AWS-RunPowerShellScript',
            'command' => 'Get-CimInstance Win32_LogicalDisk -Filter "DriveType=3" | ForEach-Object { "{0}|{1}|{2}|{3}" -f $_.DeviceID, $_.FileSystem, $_.Size, $_.FreeSpace }',
        ],
    ];

    /**
     * `services.ssm.targets` ("profile@region,profile@region") as a list.
     *
     * @return list<array{account:string, region:string}>
     */
    public function targets(): array
    {
        return collect(explode(',', (string) config('services.ssm.targets')))
            ->map(fn (string $target): string => trim($target))
            ->filter()
            ->map(function (string $target): array {
                [$account, $region] = array_pad(explode('@', $target, 2), 2, null);

                return ['account' => $account, 'region' => $region ?: 'ap-northeast-1'];
            })
            ->values()
            ->all();
    }

    /**
     * Every SSM managed node in the account/region, with its EC2 Name tag and type when it is an EC2 instance.
     *
     * @return list<array{instance_id:string, computer_name:?string, platform:?string, platform_name:?string, ping_status:?string, name:?string, instance_type:?string}>
     */
    public function instances(string $account, string $region): array
    {
        $nodes = [];

        foreach ($this->ssm($account, $region)->getPaginator('DescribeInstanceInformation') as $page) {
            foreach ($page['InstanceInformationList'] ?? [] as $info) {
                $nodes[$info['InstanceId']] = [
                    'instance_id' => $info['InstanceId'],
                    'computer_name' => $info['ComputerName'] ?? null,
                    'platform' => $info['PlatformType'] ?? null,
                    'platform_name' => $info['PlatformName'] ?? null,
                    'ping_status' => $info['PingStatus'] ?? null,
                    'name' => null,
                    'instance_type' => null,
                ];
            }
        }

        $ec2Ids = array_values(array_filter(array_keys($nodes), fn (string $id): bool => str_starts_with($id, 'i-')));

        foreach (array_chunk($ec2Ids, 100) as $chunk) {
            $result = $this->ec2($account, $region)->describeInstances(['InstanceIds' => $chunk]);

            foreach ($result['Reservations'] ?? [] as $reservation) {
                foreach ($reservation['Instances'] ?? [] as $instance) {
                    $name = collect($instance['Tags'] ?? [])->firstWhere('Key', 'Name')['Value'] ?? null;
                    $nodes[$instance['InstanceId']]['name'] = $name;
                    $nodes[$instance['InstanceId']]['instance_type'] = $instance['InstanceType'] ?? null;
                }
            }
        }

        return array_values($nodes);
    }

    /**
     * Run the platform's disk command on the given nodes and wait for the results.
     * Nodes that have not finished within {@see self::COMMAND_WAIT_SECONDS} come back as `Pending`.
     *
     * @param  list<string>  $instanceIds
     * @return array<string, array{status:string, output:string, error:string}> keyed by instance id
     */
    public function runDiskCommand(string $account, string $region, string $platform, array $instanceIds): array
    {
        $spec = self::DISK_COMMANDS[$platform] ?? throw new RuntimeException("Unsupported platform {$platform}");
        $client = $this->ssm($account, $region);
        $results = [];

        foreach (array_chunk($instanceIds, self::MAX_TARGETS_PER_COMMAND) as $chunk) {
            $commandId = $client->sendCommand([
                'DocumentName' => $spec['document'],
                'InstanceIds' => $chunk,
                'Comment' => 'infolink: disk usage (read-only)',
                'TimeoutSeconds' => 60,
                'Parameters' => ['commands' => [$spec['command']], 'executionTimeout' => ['30']],
            ])['Command']['CommandId'];

            $results += $this->awaitInvocations($client, $commandId, $chunk);
        }

        return $results;
    }

    /**
     * @param  list<string>  $instanceIds
     * @return array<string, array{status:string, output:string, error:string}>
     */
    private function awaitInvocations(SsmClient $client, string $commandId, array $instanceIds): array
    {
        $deadline = Carbon::now()->addSeconds(self::COMMAND_WAIT_SECONDS);
        $results = [];

        do {
            Sleep::for(2)->seconds();

            foreach ($client->getPaginator('ListCommandInvocations', ['CommandId' => $commandId, 'Details' => true]) as $page) {
                foreach ($page['CommandInvocations'] ?? [] as $invocation) {
                    $plugin = $invocation['CommandPlugins'][0] ?? [];
                    $results[$invocation['InstanceId']] = [
                        'status' => $invocation['Status'],
                        'output' => (string) ($plugin['Output'] ?? ''),
                        'error' => (string) ($plugin['StatusDetails'] ?? $invocation['StatusDetails'] ?? ''),
                    ];
                }
            }

            $pending = array_filter($instanceIds, fn (string $id): bool => ! in_array($results[$id]['status'] ?? null, self::TERMINAL_STATUSES, true));
        } while ($pending !== [] && Carbon::now()->lt($deadline));

        foreach ($pending as $id) {
            $results[$id] = ['status' => 'Pending', 'output' => '', 'error' => '等待逾時'];
        }

        return $results;
    }

    private function ssm(string $account, string $region): SsmClient
    {
        return new SsmClient(['profile' => $account, 'region' => $region, 'version' => 'latest']);
    }

    private function ec2(string $account, string $region): Ec2Client
    {
        return new Ec2Client(['profile' => $account, 'region' => $region, 'version' => 'latest']);
    }
}
