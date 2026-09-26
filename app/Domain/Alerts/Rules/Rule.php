<?php

namespace App\Domain\Alerts\Rules;

use App\Domain\Alerts\Firing;
use App\Models\AlertRule;
use Carbon\CarbonImmutable;

/**
 * A rule implementation (`alert_rules.query_class`, or {@see MetricThresholdRule} for metric rules).
 */
interface Rule
{
    /**
     * Human label shown in the admin's rule-class picker.
     */
    public static function label(): string;

    /**
     * Everything currently firing. An empty list means the condition is clear (open insights get auto-resolved).
     *
     * @return list<Firing>
     */
    public function evaluate(AlertRule $rule, CarbonImmutable $now): array;

    /**
     * Fingerprints this rule owns, for auto-resolve: a string ending in `*` is a prefix, anything else is exact.
     *
     * @return list<string>
     */
    public function ownedFingerprints(AlertRule $rule): array;
}
