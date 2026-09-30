<?php

namespace App\Domain\Infra;

/**
 * Parses the output of {@see SsmGateway::DISK_COMMANDS}. Lines that do not parse (headers, a line cut off by SSM's
 * 2,500-character output limit) are skipped.
 */
class DiskUsageParser
{
    /**
     * @return list<array{mount:string, filesystem:?string, fs_type:?string, size_bytes:int, used_bytes:int, available_bytes:int, used_percent:float}>
     */
    public function parse(string $platform, string $output): array
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];
        $disks = [];

        foreach ($lines as $line) {
            $disk = $platform === 'Windows' ? $this->windowsLine($line) : $this->dfLine($line);

            if ($disk !== null && $disk['size_bytes'] > 0) {
                $disks[$disk['mount']] = $disk;
            }
        }

        return array_values($disks);
    }

    /**
     * `df -PT -B1`: Filesystem Type 1-blocks Used Available Capacity Mounted-on (the mount point may contain spaces).
     *
     * @return array{mount:string, filesystem:?string, fs_type:?string, size_bytes:int, used_bytes:int, available_bytes:int, used_percent:float}|null
     */
    private function dfLine(string $line): ?array
    {
        if (! preg_match('/^(\S+)\s+(\S+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)%\s+(\/.*)$/', trim($line), $match)) {
            return null;
        }

        return self::disk($match[7], $match[1], $match[2], (int) $match[3], (int) $match[4], (int) $match[5]);
    }

    /**
     * `C:|NTFS|size|free` from Win32_LogicalDisk.
     *
     * @return array{mount:string, filesystem:?string, fs_type:?string, size_bytes:int, used_bytes:int, available_bytes:int, used_percent:float}|null
     */
    private function windowsLine(string $line): ?array
    {
        if (! preg_match('/^([A-Z]:)\|([^|]*)\|(\d+)\|(\d+)$/', trim($line), $match)) {
            return null;
        }

        $size = (int) $match[3];
        $free = (int) $match[4];

        return self::disk($match[1], $match[1], $match[2] ?: null, $size, $size - $free, $free);
    }

    /**
     * Usage the way df reports it: used / (used + available), so root-reserved blocks count as unavailable.
     *
     * @return array{mount:string, filesystem:?string, fs_type:?string, size_bytes:int, used_bytes:int, available_bytes:int, used_percent:float}
     */
    private static function disk(string $mount, ?string $filesystem, ?string $type, int $size, int $used, int $available): array
    {
        $usable = $used + $available;

        return [
            'mount' => $mount,
            'filesystem' => $filesystem,
            'fs_type' => $type,
            'size_bytes' => $size,
            'used_bytes' => $used,
            'available_bytes' => $available,
            'used_percent' => $usable > 0 ? round($used / $usable * 100, 2) : 0.0,
        ];
    }
}
