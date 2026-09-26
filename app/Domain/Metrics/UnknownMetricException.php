<?php

namespace App\Domain\Metrics;

use DomainException;

/**
 * Thrown when a value is written for a metric key that has no definition.
 */
class UnknownMetricException extends DomainException
{
    public static function forKey(string $key): self
    {
        return new self("Unknown metric key [{$key}]. Define it in metric_definitions first.");
    }
}
