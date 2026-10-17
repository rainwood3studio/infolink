<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What kind of fixed expense a recurring expense is; drives the badge on the calendar and the resource table.
 */
enum ExpenseCategory: string implements HasColor, HasLabel
{
    case Tax = 'tax';
    case Insurance = 'insurance';
    case Payroll = 'payroll';
    case Rent = 'rent';
    case Subscription = 'subscription';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Tax => '稅務',
            self::Insurance => '勞健保',
            self::Payroll => '薪資',
            self::Rent => '租金',
            self::Subscription => '訂閱',
            self::Other => '其他',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Tax => 'danger',
            self::Insurance => 'warning',
            self::Payroll => 'warning',
            self::Rent => 'gray',
            self::Subscription => 'info',
            self::Other => 'gray',
        };
    }
}
