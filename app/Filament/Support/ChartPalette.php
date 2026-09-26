<?php

namespace App\Filament\Support;

use App\Domain\Finance\CashFlowAnalytics;
use App\Enums\TransactionCategory;

/**
 * Fixed chart colours so a category / customer / series looks the same on every chart.
 */
class ChartPalette
{
    public const string INFLOW = '#16a34a';

    public const string OUTFLOW = '#dc2626';

    public const string NET = '#2563eb';

    public const string BALANCE = '#0f766e';

    public const string LOW_POINT = '#dc2626';

    public const string REGULAR = '#2563eb';

    public const string ONE_OFF = '#f97316';

    public const string REIMBURSEMENT_OUT = '#ca8a04';

    public const string REIMBURSEMENT_IN = '#84cc16';

    public const string OTHERS = '#9ca3af';

    /**
     * Customer colours, assigned by all-time revenue rank so a customer keeps its colour whatever period is shown.
     */
    public const array CUSTOMERS = ['#2563eb', '#16a34a', '#9333ea', '#ea580c', '#0891b2', '#db2777', '#65a30d', '#b45309', '#4f46e5', '#0d9488'];

    public static function category(TransactionCategory $category): string
    {
        return match ($category) {
            TransactionCategory::Revenue => '#16a34a',
            TransactionCategory::Salary => '#2563eb',
            TransactionCategory::Insurance => '#7c3aed',
            TransactionCategory::Tax => '#dc2626',
            TransactionCategory::Rent => '#0891b2',
            TransactionCategory::Subscription => '#db2777',
            TransactionCategory::Reimbursement => '#ca8a04',
            TransactionCategory::Other => '#6b7280',
        };
    }

    /**
     * @param  list<string>  $customers
     * @return array<string, string>
     */
    public static function customers(array $customers): array
    {
        $rank = array_flip(array_column(CashFlowAnalytics::between()->revenueByCustomer()['customers'], 'customer'));
        $colors = [];

        foreach ($customers as $customer) {
            $colors[$customer] = $customer === CashFlowAnalytics::OTHERS || ! isset($rank[$customer])
                ? self::OTHERS
                : self::CUSTOMERS[$rank[$customer] % count(self::CUSTOMERS)];
        }

        return $colors;
    }
}
