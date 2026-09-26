<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Primary granularity of a metric.
 */
enum PeriodType: string implements HasLabel
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Snapshot = 'snapshot';

    public function getLabel(): string
    {
        return match ($this) {
            self::Day => '日',
            self::Week => '週',
            self::Month => '月',
            self::Snapshot => '快照',
        };
    }
}
