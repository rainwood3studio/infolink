<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Stored receivable status; overdue is derived, never stored.
 */
enum ReceivableStatus: string implements HasColor, HasLabel
{
    case Planned = 'planned';
    case Invoiced = 'invoiced';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Planned => '預計',
            self::Invoiced => '已開票',
            self::Received => '已收款',
            self::Cancelled => '已取消',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::Invoiced => 'info',
            self::Received => 'success',
            self::Cancelled => 'danger',
        };
    }
}
