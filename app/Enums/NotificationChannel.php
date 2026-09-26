<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Where a notification is delivered.
 */
enum NotificationChannel: string implements HasLabel
{
    case Database = 'database';
    case Line = 'line';

    public function getLabel(): string
    {
        return match ($this) {
            self::Database => '後台通知',
            self::Line => 'LINE',
        };
    }
}
