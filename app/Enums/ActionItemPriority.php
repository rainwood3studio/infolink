<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Action item priority.
 */
enum ActionItemPriority: string implements HasColor, HasLabel
{
    case P1 = 'p1';
    case P2 = 'p2';
    case P3 = 'p3';

    public function getLabel(): string
    {
        return match ($this) {
            self::P1 => 'P1',
            self::P2 => 'P2',
            self::P3 => 'P3',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::P1 => 'danger',
            self::P2 => 'warning',
            self::P3 => 'gray',
        };
    }
}
