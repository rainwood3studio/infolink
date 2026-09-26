<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Company-level project lifecycle.
 */
enum ProjectStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Closing = 'closing';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => '進行中',
            self::Closing => '結案中',
            self::Closed => '已結案',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'info',
            self::Closing => 'warning',
            self::Closed => 'success',
        };
    }
}
