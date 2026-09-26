<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Bank transaction classification.
 */
enum TransactionCategory: string implements HasColor, HasLabel
{
    case Revenue = 'revenue';
    case Salary = 'salary';
    case Insurance = 'insurance';
    case Tax = 'tax';
    case Rent = 'rent';
    case Subscription = 'subscription';
    case Reimbursement = 'reimbursement';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Revenue => '收入',
            self::Salary => '薪資',
            self::Insurance => '勞健保',
            self::Tax => '稅',
            self::Rent => '租金',
            self::Subscription => '訂閱',
            self::Reimbursement => '代墊',
            self::Other => '其他',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Revenue => 'success',
            self::Salary => 'warning',
            self::Insurance => 'warning',
            self::Tax => 'danger',
            self::Rent => 'gray',
            self::Subscription => 'gray',
            self::Reimbursement => 'info',
            self::Other => 'gray',
        };
    }
}
