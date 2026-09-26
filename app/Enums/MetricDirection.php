<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Which direction of change is good; drives trend colours.
 */
enum MetricDirection: string implements HasLabel
{
    case Up = 'up';
    case Down = 'down';
    case None = 'none';

    public function getLabel(): string
    {
        return match ($this) {
            self::Up => '越高越好',
            self::Down => '越低越好',
            self::None => '無',
        };
    }
}
