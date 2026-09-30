<?php

namespace App\Domain\Alerts\Rules;

use App\Domain\Alerts\Firing;
use App\Domain\Infra\ServerDiskReport;
use App\Enums\InsightSeverity;
use App\Models\AlertRule;
use Carbon\CarbonImmutable;

/**
 * 硬碟空間: a server filesystem at or above `threshold` % used (default 80), or projected to fill within `params.days`
 * days (default 14) at its 7-day growth rate. At or above `critical_threshold` % it is raised as critical.
 * One insight per filesystem (`disk-usage:<instance>:<mount>`); it auto-resolves once space is freed.
 *
 * Template variables: name, instance_id, mount, percent, free, size, days_to_full.
 */
class ServerDiskRule extends BaseRule
{
    public function __construct(protected ServerDiskReport $report) {}

    public static function label(): string
    {
        return '伺服器硬碟空間';
    }

    public function evaluate(AlertRule $rule, CarbonImmutable $now): array
    {
        $threshold = $this->threshold($rule, 80);
        $critical = $this->criticalThreshold($rule);
        $horizonDays = (int) $this->param($rule, 'days', 14);
        $firings = [];

        foreach ($this->report->current() as $disk) {
            $isFull = self::breaches((string) ($rule->operator ?: '>='), $disk['used_percent'], $threshold);
            $fillsSoon = $disk['days_to_full'] !== null && $disk['days_to_full'] <= $horizonDays;

            if (! $isFull && ! $fillsSoon) {
                continue;
            }

            $growth = $disk['growth_bytes_per_day'];

            $firings[] = new Firing(
                vars: [
                    'name' => $disk['name'],
                    'instance_id' => $disk['instance_id'],
                    'mount' => $disk['mount'],
                    'percent' => self::number($disk['used_percent']),
                    'free' => self::gigabytes($disk['available_bytes']),
                    'size' => self::gigabytes($disk['size_bytes']),
                    'days_to_full' => $disk['days_to_full'] ?? '—',
                ],
                body: implode("\n", [
                    "- 機器：{$disk['name']}（`{$disk['instance_id']}`，{$disk['account']}）",
                    sprintf('- 掛載點 `%s`：已用 **%s%%**，剩 %s GB / 共 %s GB', $disk['mount'], self::number($disk['used_percent']), self::gigabytes($disk['available_bytes']), self::gigabytes($disk['size_bytes'])),
                    '- 近 7 天增長：'.($growth === null ? '資料不足' : self::gigabytes($growth).' GB／天')
                        .($disk['days_to_full'] !== null ? "，約 **{$disk['days_to_full']} 天**後滿" : ''),
                    '',
                    '**建議**：清理 log / docker image / 舊備份，或擴充 EBS 磁碟區；滿了服務會寫入失敗。',
                ]),
                evidence: [
                    'instance_id' => $disk['instance_id'],
                    'mount' => $disk['mount'],
                    'used_percent' => $disk['used_percent'],
                    'available_bytes' => $disk['available_bytes'],
                    'days_to_full' => $disk['days_to_full'],
                    'collected_at' => $disk['collected_at']->toIso8601String(),
                ],
                severity: $critical !== null && $disk['used_percent'] >= $critical ? InsightSeverity::Critical : null,
            );
        }

        return $firings;
    }

    /**
     * Bytes as GB with 1 decimal: 3,874,349,056 → "3.6".
     */
    public static function gigabytes(int|float $bytes): string
    {
        return number_format($bytes / 1024 ** 3, 1);
    }
}
