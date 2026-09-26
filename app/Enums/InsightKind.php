<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What sort of thing an insight tells you.
 */
enum InsightKind: string implements HasColor, HasLabel
{
    case Risk = 'risk';
    case Anomaly = 'anomaly';
    case Reminder = 'reminder';
    case Opportunity = 'opportunity';
    case Observation = 'observation';

    public function getLabel(): string
    {
        return match ($this) {
            self::Risk => '風險',
            self::Anomaly => '異常',
            self::Reminder => '提醒',
            self::Opportunity => '機會',
            self::Observation => '觀察',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Risk => 'danger',
            self::Anomaly => 'warning',
            self::Reminder => 'info',
            self::Opportunity => 'success',
            self::Observation => 'gray',
        };
    }
}
